#!/usr/bin/env bash
#
# Deployment fuer Zwille auf klassischem PHP-FPM-Hosting.
#
# Ablauf: Vorpruefungen -> Datenbank-Backup -> Wartungsmodus -> git pull ->
# composer install -> Migrationen -> Assets -> Cache -> PHP-FPM-Reload ->
# Wartungsmodus aus -> Healthcheck.
#
# Schlaegt ein Schritt zwischen Pull und FPM-Reload fehl, wird der alte Commit
# wiederhergestellt (sofern ALLOW_ROLLBACK nicht 0 ist). Der Wartungsmodus wird
# in jedem Fehlerfall beendet, damit die Seite nicht dauerhaft gesperrt bleibt.
#
# Optionen:
#   --dry-run            zeigt nur die Schritte, aendert nichts
#   -h, --help           diese Hilfe
#
# Umgebungsvariablen:
#   SKIP_BACKUP=1        kein Datenbank-Backup vor dem Deploy
#   ALLOW_ROLLBACK=0     bei Fehlern nicht auf den alten Commit zurueckgehen
#   PHP_FPM_RELOAD='...' eigener Befehl zum Neuladen von PHP-FPM (sonst systemctl
#                        reload als root bzw. per passwortlosem sudo; der Reload-Weg
#                        wird vor dem ersten schreibenden Schritt geprueft)
#   HEALTHCHECK=0        Healthcheck am Ende ueberspringen
#   HEALTH_URL=...       Default: DEFAULT_URI aus .env.local + /healthz,
#                        sonst http://127.0.0.1/healthz
#   HEALTH_RETRIES=10    Versuche (Default 10), HEALTH_RETRY_DELAY=2 Sekunden
#
# Release-Verzeichnisse mit symlink-Umschaltung sind die sauberere Variante
# (atomarer Wechsel, sofortiger Rollback ohne git reset). Dieses Skript bleibt
# bewusst beim vorhandenen In-Place-Layout.

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

DRY_RUN=0
ROLLBACK_ARMED=0
OLD_COMMIT=""

log() { printf '\n==> %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
fail() {
    printf 'FEHLER: %s\n' "$*" >&2
    exit 1
}

# Fuehrt einen Befehl aus oder zeigt ihn im Dry-Run nur an.
run() {
    if ((DRY_RUN)); then
        printf '    [dry-run] %s\n' "$*"
        return 0
    fi
    printf '    $ %s\n' "$*"
    "$@"
}

usage() {
    cat <<'EOF'
Deployment fuer Zwille (in-place).

Aufruf: bin/deploy.sh [--dry-run]

  --dry-run   zeigt nur die Schritte, aendert nichts
  -h, --help  diese Hilfe

Umgebung:
  SKIP_BACKUP=1         kein Datenbank-Backup vor dem Deploy
  ALLOW_ROLLBACK=0      bei Fehlern nicht auf den alten Commit zurueckgehen
  PHP_FPM_RELOAD='...'  eigener Befehl zum Neuladen von PHP-FPM
  HEALTHCHECK=0         Healthcheck am Ende ueberspringen
  HEALTH_URL=...        Default: DEFAULT_URI aus .env.local + /healthz,
                        sonst http://127.0.0.1/healthz
  HEALTH_RETRIES=10     Versuche, HEALTH_RETRY_DELAY=2 Sekunden
EOF
}

while (($# > 0)); do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *) fail "Unbekannte Option: $1 (erlaubt: --dry-run)" ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
# Fehlerbehandlung: Wartungsmodus beenden, Code zurueckrollen, FPM neu laden.
# ---------------------------------------------------------------------------
on_error() {
    local status=$?
    trap - ERR
    set +e

    printf '\nFEHLER: Deployment abgebrochen (Exit-Code %s).\n' "$status" >&2

    if [[ -f var/maintenance ]]; then
        rm -f var/maintenance && printf '    Wartungsmodus beendet.\n'
    fi

    if ((ROLLBACK_ARMED)) && [[ "${ALLOW_ROLLBACK:-1}" != "0" ]] && [[ -n "$OLD_COMMIT" ]]; then
        printf '    Rollback auf %s ...\n' "$OLD_COMMIT"
        if git reset --hard "$OLD_COMMIT"; then
            printf '    Code auf den alten Commit zurueckgesetzt.\n'
            # vendor/ und der kompilierte Container gehoeren zum neuen Stand; ohne diese
            # beiden Schritte laeuft der alte Code gegen neue Bibliotheken.
            if ! composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction; then
                printf '    WARNUNG: composer install beim Rollback fehlgeschlagen.\n' >&2
            fi
            if ! php bin/console cache:clear --env=prod; then
                printf '    WARNUNG: cache:clear beim Rollback fehlgeschlagen.\n' >&2
            fi
            reload_fpm || printf '    WARNUNG: PHP-FPM liess sich beim Rollback nicht neu laden.\n'
            printf '    HINWEIS: Bereits ausgefuhrte Migrationen werden NICHT zurueckgerollt.\n'
            printf '             Noetigenfalls doctrine:migrations:migrate prev manuell ausfuehren.\n'
        else
            printf '    WARNUNG: git reset --hard ist fehlgeschlagen; bitte manuell eingreifen.\n' >&2
        fi
    elif ((ROLLBACK_ARMED)); then
        printf '    Rollback deaktiviert (ALLOW_ROLLBACK=0); bitte Stand und Dienst manuell pruefen.\n' >&2
    fi

    exit "$status"
}
trap on_error ERR

# ---------------------------------------------------------------------------
# PHP-FPM neu laden. Ohne Reload laeuft bei opcache.validate_timestamps=0 weiterhin
# der alte Code gegen den neuen Container - das ist der Kern des Befunds B1.
#
# Reihenfolge: PHP_FPM_RELOAD (freier Befehl), systemctl reload als root, sudo -n
# systemctl reload, kill -USR2 auf den Master-Prozess (nur als root moeglich).
# Ob ueberhaupt ein Weg existiert, prueft resolve_fpm_reload() VOR dem ersten
# schreibenden Schritt - sonst scheitert jedes Deploy erst nach den Migrationen.
# ---------------------------------------------------------------------------
FPM_RELOAD_KIND=""
FPM_RELOAD_TARGET=""

resolve_fpm_reload() {
    if [[ -n "${PHP_FPM_RELOAD:-}" ]]; then
        FPM_RELOAD_KIND="command"
        return 0
    fi

    local unit="" master="" units="" wanted=""
    if command -v systemctl >/dev/null 2>&1; then
        units="$(systemctl list-units --type=service --plain --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}')" || true
    fi

    # Hosts often run several PHP versions (7.4 next to 8.4). Reloading the wrong pool
    # would leave the app's own opcache stale, so pick the unit that matches the PHP
    # running this script; anything else has to be chosen explicitly.
    wanted="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm.service"
    if printf '%s\n' $units | grep -qx -- "$wanted"; then
        unit="$wanted"
    elif [[ "$(printf '%s\n' $units | grep -c .)" == "1" ]]; then
        unit="$(printf '%s\n' $units | grep .)"
    else
        unit=""
    fi

    if [[ "$(id -u)" == "0" ]]; then
        if [[ -n "$unit" ]]; then
            FPM_RELOAD_KIND="systemd"
            FPM_RELOAD_TARGET="$unit"
            return 0
        fi
        master="$(fpm_master_pid || true)"
        if [[ -n "$master" ]]; then
            FPM_RELOAD_KIND="signal"
            FPM_RELOAD_TARGET="$master"
            return 0
        fi
    fi

    if [[ -n "$unit" ]] && command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        FPM_RELOAD_KIND="sudo-systemd"
        FPM_RELOAD_TARGET="$unit"
        return 0
    fi

    printf 'FEHLER: Kein Weg gefunden, PHP-FPM neu zu laden.\n' >&2
    printf '        Das Skript laeuft als %s; systemctl reload braucht Root-Rechte.\n' "$(id -un)" >&2
    if [[ -n "$units" ]]; then
        printf '        Gefundene FPM-Units: %s\n' "$(printf '%s ' $units)" >&2
        printf '        Mehrere PHP-Versionen: die Unit der App-Version waere %s.\n' "$wanted" >&2
    fi
    printf '        Entweder als root/sudo ausfuehren, passwortloses sudo fuer systemctl\n' >&2
    printf '        einrichten oder PHP_FPM_RELOAD setzen, z. B.\n' >&2
    printf '        PHP_FPM_RELOAD="sudo -n systemctl reload php8.4-fpm".\n' >&2
    return 1
}

fpm_master_pid() {
    local file pid
    for file in /run/php/php*-fpm.pid /var/run/php/php*-fpm.pid; do
        [[ -r "$file" ]] || continue
        pid="$(tr -dc '0-9' <"$file")"
        [[ -n "$pid" ]] && printf '%s' "$pid" && return 0
    done
    if command -v pgrep >/dev/null 2>&1; then
        pid="$(pgrep -o -f 'php-fpm: master process' || true)"
        [[ -n "$pid" ]] && printf '%s' "$pid" && return 0
    fi
    return 1
}

reload_fpm() {
    if ((DRY_RUN)); then
        info "[dry-run] PHP-FPM neu laden (${FPM_RELOAD_KIND:-unbekannt} ${FPM_RELOAD_TARGET:-})"
        return 0
    fi

    case "$FPM_RELOAD_KIND" in
        command)
            run bash -c "$PHP_FPM_RELOAD" || return 1
            ;;
        systemd)
            systemctl reload "$FPM_RELOAD_TARGET" || return 1
            info "systemctl reload $FPM_RELOAD_TARGET"
            ;;
        sudo-systemd)
            sudo -n systemctl reload "$FPM_RELOAD_TARGET" || return 1
            info "sudo -n systemctl reload $FPM_RELOAD_TARGET"
            ;;
        signal)
            kill -USR2 "$FPM_RELOAD_TARGET" || return 1
            info "kill -USR2 $FPM_RELOAD_TARGET"
            ;;
        *)
            printf 'FEHLER: PHP-FPM-Reload ist nicht konfiguriert.\n' >&2
            return 1
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Vorpruefungen
# ---------------------------------------------------------------------------
log 'Vorpruefungen'
command -v git >/dev/null 2>&1 || fail 'git ist nicht installiert.'
command -v composer >/dev/null 2>&1 || fail 'composer ist nicht installiert.'
command -v php >/dev/null 2>&1 || fail 'php ist nicht installiert.'
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "$PROJECT_DIR ist kein Git-Repository."
[[ -w "$PROJECT_DIR" ]] || fail "$PROJECT_DIR ist fuer $(id -un) nicht beschreibbar."
[[ -d var && -w var ]] || fail 'var/ fehlt oder ist nicht beschreibbar (chown auf den PHP-FPM-Nutzer).'
if ! git diff --quiet || ! git diff --cached --quiet; then
    fail 'Das Repository hat lokale Aenderungen. Bitte bereinigen; deploy.sh ueberschreibt sonst nichts, aber git pull --ff-only scheitert.'
fi

if [[ "${HEALTHCHECK:-1}" != "0" && -z "${HEALTH_URL:-}" ]]; then
    command -v curl >/dev/null 2>&1 || fail 'curl fehlt, wird fuer den Healthcheck gebraucht (oder HEALTHCHECK=0 setzen).'
fi

# Vor dem ersten schreibenden Schritt klaeren, ob PHP-FPM ueberhaupt neu geladen
# werden kann: sonst rollt ein an sich erfolgreiches Deploy nach den Migrationen zurueck.
resolve_fpm_reload || fail 'Abbruch vor dem ersten schreibenden Schritt.'
info "PHP-FPM-Reload: ${FPM_RELOAD_KIND} ${FPM_RELOAD_TARGET}"

# ---------------------------------------------------------------------------
# 1) Backup
# ---------------------------------------------------------------------------
if [[ "${SKIP_BACKUP:-0}" == "1" ]]; then
    log 'Backup uebersprungen (SKIP_BACKUP=1)'
else
    log 'Datenbank-Backup'
    run php bin/console app:db:backup
fi

# ---------------------------------------------------------------------------
# 2) Wartungsmodus
# ---------------------------------------------------------------------------
log 'Wartungsmodus aktivieren'
if ((DRY_RUN)); then
    info '[dry-run] var/maintenance anlegen'
else
    mkdir -p var
    : >var/maintenance
fi

# ---------------------------------------------------------------------------
# 3) Code aktualisieren
# ---------------------------------------------------------------------------
OLD_COMMIT="$(git rev-parse HEAD)"
ROLLBACK_ARMED=1

log "Code aktualisieren (git pull --ff-only, bisher $OLD_COMMIT)"
if ((DRY_RUN)); then
    info '[dry-run] git pull --ff-only'
else
    git pull --ff-only
    info "neuer Stand: $(git rev-parse --short HEAD)"
fi

# ---------------------------------------------------------------------------
# 4) Abhaengigkeiten
# ---------------------------------------------------------------------------
log 'Composer-Abhaengigkeiten installieren'
run composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

# ---------------------------------------------------------------------------
# 5) Datenbankschema
# ---------------------------------------------------------------------------
log 'Migrationen ausfuehren'
run php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# ---------------------------------------------------------------------------
# 6) Assets
# ---------------------------------------------------------------------------
log 'Asset-Map kompilieren'
run php bin/console asset-map:compile

# ---------------------------------------------------------------------------
# 7) Cache
# ---------------------------------------------------------------------------
log 'Cache leeren und aufwaermen'
run php bin/console cache:clear --env=prod
run php bin/console cache:warmup --env=prod

# ---------------------------------------------------------------------------
# 8) Plugins pruefen (Whitelist, plugin.yaml, requires.api, Rechte)
# ---------------------------------------------------------------------------
log 'Plugins pruefen'
run php bin/console app:plugin:validate --env=prod

# ---------------------------------------------------------------------------
# 9) PHP-FPM neu laden (sonst laeuft der alte OPcache-Inhalt weiter)
# ---------------------------------------------------------------------------
log 'PHP-FPM neu laden'
reload_fpm

# ---------------------------------------------------------------------------
# 10) Wartungsmodus beenden
# ---------------------------------------------------------------------------
log 'Wartungsmodus beenden'
if ((DRY_RUN)); then
    info '[dry-run] var/maintenance entfernen'
else
    rm -f var/maintenance
fi
ROLLBACK_ARMED=0

# ---------------------------------------------------------------------------
# 10) Healthcheck (Fehler rollt nicht zurueck: der Code ist bereits live)
# ---------------------------------------------------------------------------
health_url() {
    if [[ -n "${HEALTH_URL:-}" ]]; then
        printf '%s' "$HEALTH_URL"
        return
    fi

    local uri=""
    if [[ -f .env.local ]]; then
        uri="$(grep -E '^[[:space:]]*DEFAULT_URI=' .env.local | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
    fi
    if [[ -n "$uri" ]]; then
        printf '%s/healthz' "${uri%/}"
    else
        printf 'http://127.0.0.1/healthz'
    fi
}

if [[ "${HEALTHCHECK:-1}" == "0" ]]; then
    log 'Healthcheck uebersprungen (HEALTHCHECK=0)'
else
    url="$(health_url)"
    log "Healthcheck: $url"
    if ((DRY_RUN)); then
        info "[dry-run] curl -fsS $url (mit Retry)"
    else
        retries="${HEALTH_RETRIES:-10}"
        delay="${HEALTH_RETRY_DELAY:-2}"
        ok=0
        for ((attempt = 1; attempt <= retries; attempt++)); do
            if curl -fsS --max-time 5 -o /dev/null "$url"; then
                info "OK nach Versuch $attempt"
                ok=1
                break
            fi
            info "Versuch $attempt/$retries fehlgeschlagen, warte ${delay}s"
            sleep "$delay"
        done
        [[ "$ok" == "1" ]] || fail "Healthcheck $url ist nach $retries Versuchen fehlgeschlagen."
    fi
fi

log "Deployment abgeschlossen: $(git rev-parse --short HEAD)"