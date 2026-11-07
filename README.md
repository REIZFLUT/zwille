# EatMyShort.Link

**English** · [Deutsch](README.de.md)

A modern, security-conscious, self-hosted short-link service built for companies. Like Bitly,
but entirely under your control — on your own infrastructure, with your own domains and your
own data.

Built on a current Symfony 8.1 core (PHP 8.4), EatMyShort.Link combines a deliberately lean
architecture with contemporary enterprise security practices: phishing-resistant passkey
authentication, optional mandatory two-factor authentication, strict content security policies,
privacy-first analytics and granular access controls. Designed with maintainability, operational
simplicity and long-term sustainability in mind.

No Node build step, no queue, no background workers. Just a modern PHP application that does
its job.

## Features

- **Any number of (sub)domains** – `go.company.com/trade-show`, `jobs.company.com/apprentice`; short codes are
  unique per domain, and the root page and unknown codes can be redirected per domain (optionally with the path
  appended).
- **Language-aware targets** – one short link, different target URLs depending on the browser language.
- **Tracking without cookies** – clicks per day, unique visitors, referrers, devices, browsers, operating systems,
  languages, optionally countries (local GeoIP database). No full IP addresses; bots are detected and never stored.
  Recording happens only after the redirect has been sent — no added latency.
- **Multiple users** – everyone manages their own links, admins see everything; admins grant domains per user.
  Invitations by e-mail link, password reset, login throttling.
- **Admin area can be restricted to IP addresses/networks** (IPv4/IPv6, CIDR) with lock-out protection.
- **Passkeys (WebAuthn)** for passwordless, phishing-resistant sign-in; **TOTP** with backup codes for password
  logins; mandatory 2FA for admins can be switched on.
- **Bookmarklets** – shorten the page you are on from the bookmarks bar (with or without a custom code),
  optionally create-and-follow, plus share links on every link page.
- **CSV import** – bring an existing short-link export or an Excel list over in one step: preview first,
  customary column names (`url`, `keyword`, `title`) are recognised, duplicates are skipped.
- **Working list** – sort by clicks, last click, target or code; activate, deactivate, delete or move
  several links at once (only links you are allowed to change).
- **Automatic page titles** – fetched from the target page when a link is created (switchable, with an
  SSRF guard so the server never calls private addresses).
- QR codes (SVG/PNG), CSV export, expiry date, active toggle, 301/302/307/308.
- **REST API** with personal tokens ([docs/API.md](docs/API.md)).
- **AI agent tokens** – for AI agents and other automation: never admin rights, always
  expiring, only the chosen scopes (read, write, delete, statistics), optionally limited to
  domains, target hosts and client addresses, with their own write budget, an admin overview
  and a kill switch.
- **Plugins** – typed events instead of patched core files: rewrite redirect targets, veto
  clicks, reject links with a visible message, extend API payloads and the admin UI
  ([docs/EXTENDING.md](docs/EXTENDING.md)).
- Interface in German and English, light/dark, usable on mobile, custom logo and accent colour
  (default: Simpsons yellow `#fed90f`; text and font colours are calculated for WCAG contrast,
  the favicon picks up the colour).

## Tech stack

Modern by design — current versions, standard tooling, no moving parts to babysit:

| | |
|---|---|
| **Framework** | Symfony 8.1 — attributes instead of XML/YAML wiring, dependency injection throughout, `declare(strict_types=1)` in every file, typed properties and readonly where they belong |
| **Runtime** | PHP 8.4 (8.5 works) with `intl`, `pdo_sqlite`, `sodium`, `mbstring`; `gd` for PNG QR codes and `apcu` are optional |
| **Data** | Doctrine ORM and migrations; SQLite in WAL mode by default — no database server to install, patch or back up on the side. MariaDB/MySQL is selectable via the `.env` (`DB_CONNECTION` plus the `DB_*` variables) |
| **Frontend** | Twig plus Symfony AssetMapper: no Node, no bundler, no npm dependency tree |
| **Quality gates** | PHPStan level 8, php-cs-fixer (`@Symfony`), 190+ tests covering HTTP, service and console paths, `composer audit` and SHA-pinned GitHub Actions in CI |
| **Operations** | no queue, no Redis, no worker to monitor: housekeeping is a few cron lines (prune clicks and caches, refresh the GeoIP file), plus a deploy script with backup → maintenance mode → migrations → PHP-FPM reload → rollback, rotating log files and a `/healthz` endpoint |

## Security & Privacy by Design

Security and privacy are architecture decisions in this project, not features added later:

- **Strict CSP with a per-request nonce** on every app page. Short-link domains start no
  session and set no cookies, so a redirect cannot be correlated across sites.
- **Nothing secret is stored in clear**: API tokens only as SHA-256 hashes, password resets
  as selector/verifier pairs, TOTP secrets encrypted with `APP_SECRET` — and a placeholder
  secret is refused at boot in production.
- **Sessions hold only a `crc32c` of the password hash**: changing a password or role, or
  disabling an account, ends every open session immediately.
- **Two-factor authentication** with WebAuthn passkeys or TOTP plus salted backup codes,
  throttled login and code verification; changing 2FA, tokens or passkeys requires
  re-authentication first.
- **Admin access** is protected by an IP allowlist (IPv4/IPv6, CIDR) *and* a voter on every
  admin controller; unknown hosts never reach application routes at all.
- **AI agents get least privilege**: an agent token never carries admin rights, whoever owns
  it, every API action declares the scope it needs (actions without one are closed to
  agents), and a single switch in the settings blocks every agent at once.
- **Privacy by default**: no full IP addresses (IPv4 `/24`, IPv6 `/48`), no external
  analytics or geolocation service — visitor data never leaves your server.
- **Every query is parameterised** through Doctrine/DBAL (no string-built SQL), Twig escapes
  by default, title fetching is guarded against SSRF, and secrets live in `.env` outside the
  web root — never in the repository.

## Extensions

The core is not the end of the road: plugins hook into typed events instead of patching the
application. A plugin is a directory under `plugins/`, enabled by name in
`config/plugins.yaml` — no Composer package, no forked core. It can change redirect targets,
veto clicks, reject links with a user-visible message, suggest short codes, add fields to API
responses and contribute dashboard cards and admin navigation entries.

Two working examples ship with the app, both disabled by default: `utm-tagging` (adds campaign
parameters to outgoing targets) and `click-webhook` (posts every stored click, signed, to an
M2M endpoint).

The full contract — event catalogue, veto/reject semantics, stability promise, security notes —
is in **[docs/EXTENDING.md](docs/EXTENDING.md)**.

## Installation

See **[docs/INSTALL.md](docs/INSTALL.md)** (PHP 8.4, nginx or Apache, SQLite).

In short:

```bash
composer install --no-dev -a
cp .env.example .env                      # adjust the values
php bin/console doctrine:migrations:migrate -n
php bin/console asset-map:compile
php bin/console app:user:create admin@company.com "Admin" --admin
php bin/console app:domain:add go.company.com --default
```

## Console commands

| Command | Purpose |
|---|---|
| `app:user:create <email> [name] [--admin] [--invite]` | Create a user (prompts for a password or issues an invitation link) |
| `app:domain:add <host> [--root=URL] [--default]` | Add a short-link domain |
| `app:admin-ip [list\|allow\|remove\|clear] [cidr] [label]` | Manage the IP restriction for `/admin` (emergency access) |
| `app:clicks:prune [--days=N]` | Delete old clicks (cron) |
| `app:db:backup [target]` | Consistent database backup: `VACUUM INTO` for SQLite, `mariadb-dump`/`mysqldump` for MySQL/MariaDB |
| `app:plugin:list` | List whitelisted plugins and whether they are loaded |
| `app:plugin:validate` | Check the plugin whitelist and every plugin directory (exit 1 on errors) |
| `app:token:revoke [id] [--all-agents]` | Revoke an API token, or every AI agent token at once; lists agent tokens without arguments |

## Development

```bash
composer install
php bin/console doctrine:migrations:migrate -n
vendor/bin/phpunit                 # tests (separate SQLite test database)
vendor/bin/phpstan analyse         # level 8
vendor/bin/php-cs-fixer fix --dry-run
```

Architecture in brief:

- `src/EventListener/ShortLinkHostListener.php` – every host except `APP_ADMIN_HOSTS` goes straight to the
  `RedirectController`; app routes do not exist there.
- `src/Tracking/ClickRecorder.php` – writes clicks in `kernel.terminate`, after the response has been sent.
- `src/Security/` – IP restriction, API tokens, voters, encrypted TOTP secrets, passkey integration.
- `src/Doctrine/SqlitePragmaMiddleware.php` – WAL, busy timeout, foreign keys.

The previous version on the Continy framework lives under the tag `continy-final`;
what was carried over from it is documented in [docs/legacy-inventory.md](docs/legacy-inventory.md).

## License

GPL-3.0, see [LICENSE](LICENSE).