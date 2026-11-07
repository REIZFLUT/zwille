# Installation (klassisches PHP-Hosting)

EatMyShort.Link braucht standardmäßig keinen Datenbankserver, keinen Node-Build und
keinen Queue-Worker: PHP-FPM, ein Webserver und eine SQLite-Datei reichen. Wer trotzdem
einen Datenbankserver betreiben will, schaltet in der `.env` auf MariaDB/MySQL um - siehe
*Datenbank wählen*.

## Voraussetzungen

- PHP **8.4** (oder 8.5) mit `ctype`, `iconv`, `intl`, `pdo_sqlite`, `sodium`, `mbstring`;
  für MariaDB/MySQL zusätzlich `pdo_mysql`; empfohlen `apcu` und `opcache`, für
  PNG-QR-Codes `gd`
- Composer 2
- nginx oder Apache, TLS-Zertifikate (z. B. Let's Encrypt) für Admin-Host und alle Kurzdomains
- ausgehender SMTP-Zugang für Einladungen und Passwort-Links (optional; ohne Mailer zeigt
  die Oberfläche den Link zum Weitergeben an)

## Hosts planen

- **Admin-Host**, z. B. `links.firma.de`: Verwaltung, Login, API. Steht in `APP_ADMIN_HOSTS`
  (der erste Eintrag ist zugleich die Passkey-Domain, später nicht mehr ändern, sonst
  funktionieren registrierte Passkeys nicht mehr).
- **Kurzdomains**, z. B. `go.firma.de`, `jobs.firma.de`: werden in der Oberfläche unter
  *Domains* angelegt. Jeder Host, der nicht in `APP_ADMIN_HOSTS` steht, wird als Kurzdomain
  behandelt; unbekannte Hosts bekommen eine 404-Seite.

Alle Namen zeigen per DNS (A/AAAA oder CNAME) auf denselben Server und landen im selben vhost.

## Schritte

```bash
cd /var/www
git clone https://github.com/REIZFLUT/eatmyshort.link.git eatmyshort
cd eatmyshort
cp .env.example .env                            # Werte anpassen, siehe unten
composer install --no-dev --optimize-autoloader --classmap-authoritative
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile
php bin/console app:user:create admin@firma.de "Vorname Nachname" --admin
php bin/console app:domain:add go.firma.de --root=https://www.firma.de --default
```

`var/` (Cache, Logs in `var/log`, Sessions in `var/sessions`,
`var/data/eatmyshort.sqlite` samt `-wal`/`-shm`) muss für den PHP-FPM-Nutzer
gehören und beschreibbar sein:

```bash
mkdir -p var/log var/sessions var/data
chown -R www-data:www-data var
```

`var/log` und `var/sessions` müssen beschreibbar bleiben: Produktionslogs landen in
`var/log/prod.log` (rotierend, 14 Dateien), und ohne beschreibbares `var/sessions`
gehen Anmeldungen verloren.

## Konfiguration ohne .env im Repository

Im Repository liegt **keine** `.env`-Datei (`.env.local` und `.env.test` sind
git-ignoriert), sondern nur die Vorlage **`.env.example`**. Beim Start lädt die
Anwendung in dieser Reihenfolge:

1. `.env`, falls vorhanden – sonst ersatzweise `.env.local` (git-ignoriert);
2. im Testlauf ausschließlich `.env.test` (git-ignoriert).

`.env.example` wird **nie** automatisch geladen; sie ist nur eine Kopiervorlage.

**Variante 1 – echte Umgebungsvariablen (empfohlen für Produktion).** Im
PHP-FPM-Pool:

```ini
env[APP_ENV] = prod
env[APP_SECRET] = <32+ zufällige Zeichen>
env[APP_ADMIN_HOSTS] = links.firma.de
env[DEFAULT_URI] = https://links.firma.de
```

oder in nginx, z. B. `fastcgi_param APP_ENV prod;` (dann auch die übrigen Werte).
Reale Umgebungsvariablen haben Vorrang vor jeder `.env`-Datei.

**Variante 2 – `.env` aus der Vorlage (einfach, bleibt git-ignoriert).**

```bash
cp .env.example .env        # Werte anpassen; für Produktion APP_ENV=prod und APP_SECRET setzen
```

**Ohne beides startet die App nicht:** Fehlt eine dot-env-Datei und ist `APP_ENV`
nicht als echte Umgebungsvariable gesetzt, bricht der Start mit einer klaren
Meldung ab, statt still im Dev-Modus (mit Debug) zu laufen.

`.env` und `.env.local` dürfen **niemals** ins Repository. `bin/deploy.sh` liefert
keine Umgebungsvariablen mit – sie bleiben im FPM-Pool bzw. in einer nicht
versionierten `.env`-Datei auf dem Server.

**Bestehende Installation aktualisieren:** Früher kamen `APP_SECRET` und `DATABASE_URL`
aus der eingecheckten `.env`. `APP_SECRET` aus der alten `.env` in die eigene `.env.local`
übernehmen (oder als echte Umgebungsvariable setzen) und **unverändert** lassen, sonst sind
die gespeicherten TOTP-Schlüssel nicht mehr lesbar. `DATABASE_URL` ist inzwischen optional:
ohne sie läuft die App auf SQLite in `var/data/eatmyshort.sqlite`. Wer die bisherige
Datenbank behalten will, übernimmt den alten Wert entweder weiter als `DATABASE_URL` oder
stellt auf die `DB_*`-Variablen um.

### `.env.local`

| Variable | Bedeutung |
|---|---|
| `APP_ENV=prod` | Produktivmodus |
| `APP_SECRET` | 32+ zufällige Zeichen (`php -r "echo bin2hex(random_bytes(16));"`). Verschlüsselt auch die TOTP-Schlüssel – **nicht nachträglich ändern**, sonst müssen alle Nutzer TOTP neu einrichten. Wird beim Start in `prod` geprüft: leer, zu kurz oder ein Platzhalter aus der Vorlage bricht den Boot ab (auch bei Konsolenbefehlen). |
| `APP_ADMIN_HOSTS` | Admin-Host(s), kommagetrennt |
| `DEFAULT_URI` | `https://<admin-host>` (Links in Konsolenausgaben) |
| `DB_CONNECTION` | `sqlite` (Default), `mysql` oder `mariadb` – ohne Angabe läuft alles auf SQLite |
| `DB_DATABASE` | SQLite: Pfad zur Datei (relativ zum Projektverzeichnis oder absolut); MariaDB/MySQL: Datenbankname |
| `DB_HOST`, `DB_PORT` | MariaDB/MySQL, Standard `127.0.0.1` und `3306` |
| `DB_USERNAME`, `DB_PASSWORD` | MariaDB/MySQL; Sonderzeichen im Passwort sind erlaubt |
| `DB_SOCKET` | MariaDB/MySQL über Unix-Socket – ersetzt `DB_HOST`/`DB_PORT` |
| `DB_CHARSET` | Zeichensatz, Standard `utf8mb4` |
| `DB_SERVER_VERSION` | optional: Serverversion festnageln (z. B. `mariadb-11.4.0`), sonst wird sie beim Verbinden erkannt |
| `DATABASE_URL` | optionaler Override; hat **immer** Vorrang vor allen `DB_*`-Werten. Ohne DATABASE_URL wird die DSN aus den `DB_*`-Variablen gebaut (Default: SQLite unter `var/data/eatmyshort.sqlite`) |
| `MAILER_DSN`, `MAILER_FROM` | z. B. `smtp://user:pass@smtp.firma.de:587` |
| `ADMIN_IP_ALLOWLIST` | IPs/Netze, die `/admin` **immer** öffnen dürfen (Aussperr-Schutz), kommagetrennt |
| `TRUSTED_PROXIES` | nur hinter Reverse-Proxy/Load-Balancer: **nur dessen IPs** (z. B. `10.0.0.5,10.0.0.6`), niemals `REMOTE_ADDR`. Sonst glaubt die App jedem Client-Header und die IP-Sperre ist mit einem gefälschten `X-Forwarded-For` umgehbar. Leer = keine Proxys. |
| `APP_TIMEZONE` | Zeitzone der Statistik, Standard `Europe/Berlin` |
| `GEOIP_DATABASE_PATH` | optional, Pfad zu `GeoLite2-Country.mmdb` (MaxMind, kostenloses Konto) oder `dbip-country-lite.mmdb` (DB-IP, CC BY 4.0) |
| `CLICK_RETENTION_DAYS` | Aufbewahrung der Klicks, Standard 730, `0` = unbegrenzt |
| `AUDIT_RETENTION_DAYS` | Aufbewahrung des Audit-Logs, Standard 365, `0` = unbegrenzt |

## Datenbank wählen

Ohne weitere Angabe läuft die App auf SQLite (`var/data/eatmyshort.sqlite`). Wer einen
Datenbankserver will, stellt in der `.env` auf MariaDB/MySQL um:

```ini
DB_CONNECTION=mariadb        # sqlite (Default) | mysql | mariadb (Aliase, derselbe Treiber)
DB_DATABASE=eatmyshort
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=eatmyshort
DB_PASSWORD=...
DB_CHARSET=utf8mb4
```

Regeln:

- **`DATABASE_URL` gewinnt immer.** Ist sie gesetzt (echte Umgebungsvariable oder
  dot-env-Datei), wird sie unverändert benutzt und die `DB_*`-Variablen werden ignoriert –
  die beiden Schemata werden nie gemischt.
- Vorrang der Quellen: echte Umgebungsvariablen > `.env.local` > `.env`.
- Ein unbekanntes `DB_CONNECTION` bricht den Start mit klarer Meldung ab, statt still auf
  SQLite zurückzufallen.
- Sonderzeichen in `DB_USERNAME`/`DB_PASSWORD` sind erlaubt (werden URL-kodiert).
- `DB_SOCKET` verbindet über einen Unix-Socket und ersetzt dann `DB_HOST`/`DB_PORT`.
- `DB_SERVER_VERSION` (z. B. `mariadb-11.4.0`) nagelt die Serverversion fest; ohne den Wert
  wird sie beim Verbinden erkannt (gilt für SQLite wie MariaDB/MySQL).
- Setzt man `DATABASE_URL` von Hand mit URL-kodierten Zugangsdaten, muss ein `%` als `%%`
  geschrieben werden (Regel des `resolve`-Prozessors); die `DB_*`-Variablen erledigen die
  Kodierung selbst.

Datenbank und Nutzer anlegen:

```sql
CREATE DATABASE eatmyshort CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'eatmyshort'@'localhost' IDENTIFIED BY '<passwort>';
GRANT ALL PRIVILEGES ON eatmyshort.* TO 'eatmyshort'@'localhost';
FLUSH PRIVILEGES;
```

Danach die `DB_*`-Werte in der `.env` setzen und migrieren:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Die Migrationen sind treiberneutral: der Lauf erzeugt das Schema auf der leeren
MariaDB/MySQL-Datenbank genauso wie auf SQLite.

Backups übernimmt `app:db:backup` für beide Treiber: SQLite per `VACUUM INTO`,
MariaDB/MySQL per `mariadb-dump` bzw. `mysqldump` (`--single-transaction --quick`,
Zugangsdaten über `MYSQL_PWD` statt auf der Kommandozeile). Das Ziel endet auf `.sql` oder
`.sql.gz`. Liegt keines der Werkzeuge im `PATH`, meldet der Befehl das mit Namen – dann das
Client-Paket (`mariadb-client`/`mysql-client`) installieren.

## Webserver

- nginx: [`docs/examples/nginx.conf`](examples/nginx.conf)
- Apache: `public/.htaccess` ist enthalten; vhost mit `DocumentRoot …/public` und `AllowOverride All`.

Beide schicken **alle** Hosts (Admin-Host und Kurzdomains) an `public/index.php`.
HTTP leitet auf HTTPS um.

## Cronjobs

```cron
# Klicks älter als CLICK_RETENTION_DAYS löschen
15 3 * * *  www-data  php /var/www/eatmyshort/bin/console app:clicks:prune --quiet
# Audit-Log älter als AUDIT_RETENTION_DAYS löschen
20 3 * * *  www-data  php /var/www/eatmyshort/bin/console app:audit:prune --quiet
# Sicherung der Datenbank (konsistent im laufenden Betrieb), 14 Tage behalten.
# SQLite-Sicherungen enden auf .sqlite, MariaDB/MySQL-Sicherungen auf .sql.gz.
30 3 * * *  www-data  php /var/www/eatmyshort/bin/console app:db:backup --quiet && find /var/www/eatmyshort/var/backup \( -name '*.sqlite' -o -name '*.sql.gz' \) -mtime +14 -delete
# Abgelaufene Cache-Einträge entfernen (u. a. die Zähler des Tracking-Rate-Limiters)
45 3 * * *  www-data  php /var/www/eatmyshort/bin/console cache:pool:prune --quiet
# GeoIP-Datenbank monatlich erneuern (DB-IP Lite, CC BY 4.0). Erst laden, dann ersetzen:
# scheitert der Download, bleibt die alte Datei liegen (kein leeres .mmdb).
10 4 1 * *  www-data  cd /var/www/eatmyshort/var/geoip && curl -fsSL -o dbip-new.mmdb.gz "https://download.db-ip.com/free/dbip-country-lite-$(date +\%Y-\%m).mmdb.gz" && gunzip -f dbip-new.mmdb.gz && mv dbip-new.mmdb dbip-country-lite.mmdb && php /var/www/eatmyshort/bin/console cache:clear --env=prod --quiet
```

### GeoIP-Datenbank (optional)

Länder in der Statistik brauchen eine lokale `.mmdb`; es wird nie ein externer Dienst
gefragt. Ohne Datei bleiben die Länder leer und *Admin → Einstellungen* zeigt einen Hinweis.

```bash
mkdir -p var/geoip
curl -fsSL "https://download.db-ip.com/free/dbip-country-lite-$(date +%Y-%m).mmdb.gz" \
  | gunzip -c > var/geoip/dbip-country-lite.mmdb
# GEOIP_DATABASE_PATH=/var/www/eatmyshort/var/geoip/dbip-country-lite.mmdb in .env.local
```

DB-IP Lite steht unter CC BY 4.0 und verlangt die Angabe „IP Geolocation by DB-IP";
MaxMind GeoLite2 ist die Alternative (kostenloses Konto, Download per Lizenzschlüssel).
Die Datei ist gut 8 MB (IPv4 + IPv6) und gehört nicht ins Repository (`var/` ist ignoriert).

Der Tracking-Rate-Limiter legt pro aufrufender IP einen Zähler im Cache an. Die
Einträge laufen nach einer Minute ab, die Dateien werden aber erst durch
`cache:pool:prune` gelöscht — ohne den Cronjob wächst `var/cache` mit der Zahl der
Besucher-IPs.

`app:db:backup` sichert **nur die Datenbank**. `.env.local` gehört in ein separates,
verschlüsseltes Backup, insbesondere `APP_SECRET`: Daraus werden die TOTP-Schlüssel
der Nutzer abgeleitet. Ohne diesen Wert sind die Seeds nach einem Serververlust
unwiederbringlich.

## Updates

```bash
bin/deploy.sh --dry-run   # zeigt nur die Schritte, ändert nichts
bin/deploy.sh
```

Das Skript prüft die Voraussetzungen, schreibt ein Datenbank-Backup
(`SKIP_BACKUP=1` überspringt es), aktiviert den Wartungsmodus (`var/maintenance`: der
Admin-Host antwortet mit 503, Kurzlinks leiten weiter),
holt den neuen Stand (`git pull --ff-only`), installiert die Abhängigkeiten ohne
Dev-Pakete, führt Migrationen aus, kompiliert die Assets, leert und wärmt den
Prod-Cache, lädt PHP-FPM neu und prüft danach `GET /healthz`. Schlägt ein Schritt
zwischen Pull und FPM-Reload fehl, wird der alte Commit wiederhergestellt
(`ALLOW_ROLLBACK=0` deaktiviert das) und der Wartungsmodus beendet.

Der FPM-Reload ist Pflicht, weil unten `opcache.validate_timestamps=0` empfohlen
wird: ohne Reload läuft der alte Code gegen den neuen Container. `systemctl reload`
braucht Root-Rechte; das Skript läuft aber als Dateibesitzer. Es sucht sich deshalb
einen Weg (als root: systemd-Unit oder `kill -USR2` auf den Master-Prozess; sonst
passwortloses `sudo -n`, sofern eingerichtet) und **prüft das vor dem ersten
schreibenden Schritt** — fehlt ein Weg, bricht es sofort ab, statt nach den
Migrationen zurückzurollen. Alternativ `PHP_FPM_RELOAD='sudo -n systemctl reload
php8.4-fpm'` (oder ein eigenes Wrapper-Skript) setzen.

Der Rollback setzt den Commit zurück, installiert die Abhängigkeiten des alten
Stands neu und leert den Prod-Cache; **Migrationen werden nicht zurückgerollt**
(das Skript weist darauf hin). Der Healthcheck nutzt `HEALTH_URL` oder leitet die URL
aus `DEFAULT_URI` ab (Standard `http://127.0.0.1/healthz`).

Release-Verzeichnisse mit symlink-Umschaltung sind die sauberere Variante: Der
Wechsel ist atomar und ein Rollback braucht kein `git reset`. Dieses Projekt bleibt
beim In-Place-Layout, weil das Hosting so eingerichtet ist.

## Sicherheit im Betrieb

- **IP-Sperre**: *Admin → IP-Sperre*. Ohne Einträge ist `/admin` von überall erreichbar.
  Ausgesperrt? `php bin/console app:admin-ip allow <deine-ip>` oder `app:admin-ip clear`.
- **Zweiter Faktor**: *Admin → Einstellungen → Zweiten Faktor für Admins vorschreiben*.
  Passkeys gelten als vollwertiger zweiter Faktor.
- **Passkeys** brauchen HTTPS und funktionieren nur unter dem ersten Host aus `APP_ADMIN_HOSTS`.
- Kurzdomains setzen keine Cookies und kennen keine Login- oder Admin-Routen.
- **`.env.local` separat sichern**: Die Datei enthält `APP_SECRET`, aus dem die
  TOTP-Schlüssel abgeleitet werden. `app:db:backup` sichert sie nicht mit; ohne eine
  verschlüsselte Kopie sind die Seeds nach einem Serververlust unwiederbringlich.
- **`/healthz`** ist ohne Login erreichbar und meldet nur `{"status":"ok"}` bzw. bei
  Datenbankfehlern `{"status":"error"}` mit HTTP 503 – ohne interne Details.
- **Titelabruf**: Beim Anlegen holt der Server den Seitentitel des Ziels ab (abschaltbar unter
  *Admin → Einstellungen*), erst nachdem der Link die Prüfung bestanden hat und nie beim
  CSV-Import. Der Abruf ist auf 3 s je Schritt, zwei Weiterleitungen und 256 KB begrenzt und
  ruft niemals private, Loopback-, Link-Local-, CGNAT- oder NAT64-Adressen auf (SSRF-Schutz
  über Symfonys `NoPrivateNetworkHttpClient`: die geprüfte Adresse wird für die Verbindung
  festgehalten, jede Weiterleitung wird vor dem Abruf erneut geprüft). Benötigt ausgehendes
  HTTP/HTTPS; in Netzen ohne Ausgang bleibt der Titel einfach leer.
- **CSV-Import**: *Admin → Import*. Kopfzeile ist Pflicht; erkannt werden `code`/`keyword`,
  `target_url`/`url`, `title`, `domain`, `redirect_type`, `active`, `expires_at` (`clicks` wird
  ignoriert, damit YOURLS-Exporte direkt passen). Grenzen: 5 MB, 5000 Zeilen pro Lauf,
  UTF-8 (BOM und Semikolon sind ok). Der erste Schritt zeigt nur eine Vorschau, geschrieben wird
  erst nach der Bestätigung; vorhandene Kürzel auf der Zieldomain werden übersprungen.
  Seitentitel werden beim Import nicht abgerufen (ein Abruf je Zeile würde das Zeitlimit
  sprengen); fehlende Titel bleiben leer.

## Leistung

Gemessen auf einem kleinen Entwicklungsrechner (4 Kerne, 4 PHP-FPM-Worker, Xdebug geladen,
OPcache mit Zeitstempelprüfung): **gut 50 Weiterleitungen pro Sekunde inklusive Tracking**,
also rund 3.000 pro Minute; 3.000 parallele Aufrufe wurden ohne Sperrfehler vollständig gezählt.
Die Weiterleitung selbst braucht im Kernel rund 4 ms, das Tracking danach rund 20 ms – der
Besucher wartet darauf nicht.

Für Produktion:

- `opcache.validate_timestamps=0` (nach jedem Deploy PHP-FPM neu laden) und
  `opcache.preload=/var/www/eatmyshort/config/preload.php`, `opcache.preload_user=www-data`
- kein Xdebug auf dem Server
- `pm.max_children` passend zu Kernen und RAM (je Worker ca. 30–40 MB)
- APCu aktivieren (wird für Systemcaches automatisch genutzt)

## Wechsel auf MariaDB/MySQL oder PostgreSQL

SQLite im WAL-Modus reicht für typische Unternehmenslasten. Ein Datenbankserver ist
gleichwertig wählbar: `DB_CONNECTION=mariadb` (oder `mysql`) plus die `DB_*`-Werte in der
`.env`, siehe *Datenbank wählen*. Die mitgelieferten Migrationen sind treiberneutral – ein
Migrationslauf erzeugt das Schema auf der leeren Zieldatenbank. `app:db:backup` sichert
SQLite wie MariaDB/MySQL, ohne dass die Werkzeuge der Datenbank von Hand bemüht werden
müssen.

PostgreSQL wird nicht unterstützt: `DB_CONNECTION` kennt nur `sqlite`, `mysql` und
`mariadb`. Dafür bräuchte es einen eigenen Migrationssatz und einen Test der Abfragen auf
der Zieldatenbank.
