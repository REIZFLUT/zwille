# EatMyShort.Link

[English](README.md) · **Deutsch**

Ein moderner, sicherheitsbewusster, selbst gehosteter Kurzlink-Dienst für Unternehmen. Wie Bitly,
nur vollständig unter eigener Kontrolle – auf der eigenen Infrastruktur, mit eigenen Domains und
eigenen Daten.

Auf einem aktuellen Symfony-8.1-Kern (PHP 8.4) verbindet EatMyShort.Link eine bewusst schlanke
Architektur mit zeitgemäßen Sicherheitsverfahren aus dem Unternehmensumfeld: phishing-resistente
Passkey-Anmeldung, optional verpflichtende Zwei-Faktor-Authentifizierung, strikte
Content-Security-Policies, datenschutzfreundliche Statistik und feingranulare Zugriffskontrolle.
Entwickelt mit Blick auf Wartbarkeit, betriebliche Einfachheit und langfristige Tragfähigkeit.

Kein Node-Build, keine Queue, keine Hintergrunddienste. Eine moderne PHP-Anwendung, die ihre
Aufgabe erledigt.

## Funktionen

- **Beliebig viele (Sub)Domains** – `go.firma.de/messe`, `jobs.firma.de/azubi`; Kürzel sind pro Domain eindeutig,
  Startseite und unbekannte Kürzel lassen sich je Domain weiterleiten (optional mit angehängtem Pfad).
- **Sprachabhängige Ziele** – ein Kurzlink, je nach Browsersprache unterschiedliche Ziel-URLs.
- **Tracking ohne Cookies** – Klicks pro Tag, eindeutige Besucher, Herkunft, Geräte, Browser, Betriebssysteme,
  Sprachen, optional Länder (lokale GeoIP-Datenbank). Keine vollständigen IP-Adressen, Bots werden erkannt und
  gar nicht erst gespeichert. Erfasst wird erst nach dem Absenden der Weiterleitung – keine zusätzliche Wartezeit.
- **Mehrere Nutzer** – jeder verwaltet seine Links, Admins sehen alles; Admins geben Domains pro Nutzer frei.
  Einladung per E-Mail-Link, Passwort vergessen, Login-Drosselung.
- **Admin-Bereich auf IP-Adressen/Netze einschränkbar** (IPv4/IPv6, CIDR) mit Aussperr-Schutz.
- **Passkeys (WebAuthn)** für passwortlose, phishing-sichere Anmeldung; **TOTP** mit Backup-Codes für Passwort-Logins;
  2FA-Pflicht für Admins einschaltbar.
- **Lesezeichen** – die aktuelle Seite direkt aus der Lesezeichenleiste kürzen (mit oder ohne Wunschkürzel),
  optional anlegen und gleich weiterleiten; Teilen-Links auf jeder Link-Seite.
- **CSV-Import** – einen Export eines bestehenden Kurzlinkers oder eine Excel-Liste in einem Schritt
  übernehmen: erst Vorschau, gängige Spaltennamen (`url`, `keyword`, `title`) werden erkannt,
  Dubletten übersprungen.
- **Arbeitsliste** – nach Klicks, letztem Klick, Ziel oder Kürzel sortieren; mehrere Links auf einmal
  aktivieren, deaktivieren, löschen oder verschieben (nur Links, die man ändern darf).
- **Tags und interne Notizen** – Links nach Kampagnen mit beliebig vielen Tags gruppieren, die Liste
  nach Tag filtern und Tags auf einer eigenen Seite verwalten; jeder Link kann eine Notiz fürs eigene
  Team tragen, die nie über die API oder eine Weiterleitung sichtbar wird.
- **Automatische Seitentitel** – beim Anlegen vom Ziel abgeholt (abschaltbar, mit SSRF-Schutz, damit der
  Server nie private Adressen aufruft).
- **Zeitfenster und Klicklimit** – einen Link erst zu einem späteren Zeitpunkt starten, die Zahl der Klicks
  begrenzen (oder unbegrenzt lassen) und Besucher eines nicht erreichbaren Links statt auf eine Hinweisseite
  auf eine Ausweich-Adresse leiten.
- QR-Codes (SVG/PNG), CSV-Export, Ablaufdatum, Aktiv-Schalter, 301/302/307/308.
- **REST-API** mit persönlichen Tokens ([docs/API.md](docs/API.md)).
- **Plugins** – typisierte Events statt Eingriffen in den Kern: Weiterleitungsziele umschreiben,
  Klicks ablehnen, Links mit sichtbarer Meldung zurückweisen, API-Antworten und Admin-Oberfläche
  erweitern ([docs/EXTENDING.md](docs/EXTENDING.md)).
- **Audit-Log** – sicherheitsrelevante Aktionen werden mit Akteur, Zeitpunkt und kleinem Kontext protokolliert: An- und Abmeldung, 2FA-, Passwort-, Passkey- und API-Token-Änderungen, Admin-Änderungen an Nutzern, Domains, IP-Regeln und Einstellungen sowie jedes Anlegen, Ändern und Löschen von Links. Im Admin-Bereich filterbar und per Aufbewahrung bereinigt.
- Oberfläche auf Deutsch und Englisch, hell/dunkel, mobil nutzbar, eigenes Logo und Akzentfarbe
  (Standard: Simpsons-Gelb `#fed90f`; Schrift- und Textfarben werden für Kontrast nach WCAG berechnet,
  das Favicon übernimmt die Farbe).

## Technik-Stack

Modern von Anfang an – aktuelle Versionen, Standardwerkzeuge, keine beweglichen Teile, die zusätzliche Betreuung brauchen:

| | |
|---|---|
| **Framework** | Symfony 8.1 – PHP-Attributes statt XML/YAML-Verdrahtung, Dependency Injection durchgehend, `declare(strict_types=1)` in jeder Datei, typisierte Properties und `readonly`, wo es hingehört |
| **Laufzeit** | PHP 8.4 (8.5 funktioniert) mit `intl`, `pdo_sqlite`, `sodium`, `mbstring`; `gd` für PNG-QR-Codes und `apcu` sind optional |
| **Daten** | Doctrine ORM und Migrationen; standardmäßig SQLite im WAL-Modus – kein Datenbankserver, der zusätzlich installiert, gepatcht und gesichert werden muss. MariaDB/MySQL ist über die `.env` wählbar (`DB_CONNECTION` plus die `DB_*`-Variablen) |
| **Frontend** | Twig plus Symfony AssetMapper: kein Node, kein Bundler, kein npm-Abhängigkeitsbaum |
| **Qualitätstore** | PHPStan Level 8, php-cs-fixer (`@Symfony`), 190+ Tests über HTTP-, Service- und Konsolenpfade, `composer audit` und per SHA gepinnte GitHub Actions im CI |
| **Betrieb** | keine Queue, kein Redis, kein Worker zum Überwachen: Hausarbeit sind ein paar Cronzeilen (Klicks und Caches aufräumen, GeoIP-Datei erneuern) sowie ein Deploy-Skript mit Backup → Wartungsmodus → Migrationen → PHP-FPM-Reload → Rollback, rotierende Logdateien und ein `/healthz`-Endpunkt |

## Sicherheit & Datenschutz by Design

Sicherheit und Datenschutz sind hier Architekturentscheidungen, keine nachträglich ergänzten Funktionen:

- **Strikte CSP mit Nonce pro Anfrage** auf jeder App-Seite. Kurzdomains starten keine
  Session und setzen keine Cookies, eine Weiterleitung ist also nicht über Seiten hinweg
  verkettbar.
- **Nichts Geheimes liegt im Klartext**: API-Tokens nur als SHA-256-Hash, Passwort-Resets als
  Selector/Verifier-Paar, TOTP-Schlüssel mit `APP_SECRET` verschlüsselt – und ein
  Platzhalter-Secret wird in Produktion beim Start abgelehnt.
- **In der Session liegt nur ein `crc32c` des Passwort-Hashes**: Passwort- oder Rollenwechsel
  und das Sperren eines Kontos beenden offene Sessions sofort.
- **Zwei-Faktor** über WebAuthn-Passkeys oder TOTP mit gesalzenen Backup-Codes, gedrosselter
  Login und gedrosselte Code-Prüfung; Änderungen an 2FA, Tokens oder Passkeys verlangen
  vorher eine erneute Bestätigung.
- **Admin-Bereich** über IP-Freigabeliste (IPv4/IPv6, CIDR) **und** einen Voter auf jedem
  Admin-Controller abgesichert; unbekannte Hosts erreichen die App-Routen gar nicht erst.
- **Datenschutz als Voreinstellung**: keine vollständigen IP-Adressen (IPv4 `/24`, IPv6 `/48`),
  keine externen Analyse- oder Geodienste – Besucherdaten verlassen den Server nicht.
- **Jede Abfrage ist parametrisiert** über Doctrine/DBAL (kein zusammengebautes SQL), Twig
  escaped standardmäßig, das Holen von Seitentiteln ist gegen SSRF geschützt, und Zugangsdaten
  liegen in einer `.env` außerhalb des Webroots – nie im Repository.

## Erweiterungen

Der Kern ist nicht das Ende: Plugins hängen sich an typisierte Events, statt die Anwendung zu
patchen. Ein Plugin ist ein Verzeichnis unter `plugins/`, per Name in `config/plugins.yaml`
freigeschaltet – kein Composer-Paket, kein geforkter Kern. Es kann Weiterleitungsziele ändern,
Klicks ablehnen, Links mit sichtbarer Meldung zurückweisen, Kürzel vorschlagen, API-Antworten um
Felder ergänzen und Dashboard-Karten sowie Admin-Navigation beisteuern.

Zwei lauffähige Beispiele liegen bei, beide standardmäßig deaktiviert: `utm-tagging` (hängt
Kampagnen-Parameter an ausgehende Ziele) und `click-webhook` (meldet jeden gespeicherten Klick
signiert an einen M2M-Endpunkt).

Der vollständige Vertrag – Event-Katalog, Veto-/Ablehnungs-Semantik, Stabilitätszusage,
Sicherheitshinweise – steht in **[docs/EXTENDING.md](docs/EXTENDING.md)**.

## Installation

Siehe **[docs/INSTALL.md](docs/INSTALL.md)** (PHP 8.4, nginx oder Apache, SQLite).

Kurzfassung:

```bash
composer install --no-dev -a
cp .env.example .env                         # anpassen
php bin/console doctrine:migrations:migrate -n
php bin/console asset-map:compile
php bin/console app:user:create admin@firma.de "Admin" --admin
php bin/console app:domain:add go.firma.de --default
```

## Konsolenbefehle

| Befehl | Zweck |
|---|---|
| `app:user:create <email> [name] [--admin] [--invite]` | Nutzer anlegen (Passwort abfragen oder Einladungslink) |
| `app:domain:add <host> [--root=URL] [--default]` | Kurzdomain anlegen |
| `app:admin-ip [list\|allow\|remove\|clear] [cidr] [label]` | IP-Sperre für `/admin` verwalten (Notfallzugang) |
| `app:clicks:prune [--days=N]` | alte Klicks löschen (Cron) |
| `app:db:backup [ziel]` | konsistente Datenbanksicherung: `VACUUM INTO` für SQLite, `mariadb-dump`/`mysqldump` für MySQL/MariaDB |
| `app:plugin:list` | freigeschaltete Plugins und ob sie geladen sind |
| `app:plugin:validate` | Whitelist und alle Plugin-Verzeichnisse prüfen (Exit 1 bei Fehlern) |

## Entwicklung

```bash
composer install
php bin/console doctrine:migrations:migrate -n
vendor/bin/phpunit                 # Tests (eigene SQLite-Testdatenbank)
vendor/bin/phpstan analyse         # Level 8
vendor/bin/php-cs-fixer fix --dry-run
```

Aufbau in Kürze:

- `src/EventListener/ShortLinkHostListener.php` – jeder Host außer `APP_ADMIN_HOSTS` geht direkt an den
  `RedirectController`; App-Routen existieren dort nicht.
- `src/Tracking/ClickRecorder.php` – schreibt Klicks in `kernel.terminate`, nach dem Senden der Antwort.
- `src/Security/` – IP-Sperre, API-Tokens, Voter, verschlüsselte TOTP-Schlüssel, Passkey-Anbindung.
- `src/Doctrine/SqlitePragmaMiddleware.php` – WAL, Busy-Timeout, Foreign Keys.

Die frühere Version auf dem Continy-Framework liegt unter dem Tag `continy-final`;
was daraus übernommen wurde, steht in [docs/legacy-inventory.md](docs/legacy-inventory.md).

## Lizenz

GPL-3.0, siehe [LICENSE](LICENSE).