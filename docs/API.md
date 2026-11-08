# REST-API v1

Basis-URL: `https://<admin-host>/api/v1` · Format: JSON · Authentifizierung:
persönliches Token aus **Profil → API** als `Authorization: Bearer zwl_…`.
Tokens aus der Zeit vor der Umbenennung (`emsl_…`, `emsa_…`) bleiben gültig.

Ein persönliches Token handelt mit den Rechten seines Nutzers: eigene Links (Admins:
alle), nur freigegebene Domains (Admins: alle aktiven). Limit: 120 Anfragen pro Minute
und Nutzer (`429` mit `Retry-After`). Für KI-Agenten gibt es eigene, eingeschränkte
Tokens, siehe [KI-Agent-Tokens](#ki-agent-tokens).

## Endpunkte

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/domains` | Domains, die das Token verwenden darf |
| GET | `/links?q=&domain=&page=` | Links suchen (50 pro Seite) |
| POST | `/links` | Link anlegen |
| GET | `/links/{id}` | Link lesen |
| PATCH | `/links/{id}` | Link ändern (nur übergebene Felder) |
| DELETE | `/links/{id}` | Link löschen (`204`) |
| GET | `/links/{id}/stats?days=7\|30\|90\|365` | Statistik eines Links |
| GET | `/expand?url=&domain=` | Kurz-URL oder Code auflösen (YOURLS-kompatibel) |
| GET | `/stats?days=7\|30\|90\|365` | Gesamtstatistik des Kontos |

## Link-Felder

| Feld | Typ | Hinweis |
|---|---|---|
| `target_url` | string | Pflicht beim Anlegen, nur http(s), nicht auf eigene Domains |
| `domain` | string\|int | Hostname oder ID; ohne Angabe die Standard-Domain des Nutzers |
| `code` | string | Wunschkürzel; leer/fehlend = zufällig. Kleinbuchstaben, Ziffern, `-`, `_` |
| `title` | string\|null | interne Bezeichnung |
| `redirect_type` | int | 302 (Standard), 301, 307, 308 |
| `active` | bool | |
| `starts_at` | string\|null | ISO 8601; der Link ist erst ab diesem Zeitpunkt erreichbar |
| `expires_at` | string\|null | ISO 8601 |
| `max_clicks` | int\|null | Klicklimit; leer/fehlend = unbegrenzt, ein Wert < 1 ergibt `422` |
| `fallback_url` | string\|null | Ausweich-Ziel für nicht erreichbare Links, nur http(s); sonst `422` |
| `localisations` | object | `{"en": "https://…", "de-at": "https://…"}` – ersetzt alle bisherigen |
| `device_targets` | array | `[{"device": "ios", "url": "https://…"}, …]` für `ios`, `android`, `desktop` – ersetzt alle bisherigen. Ein unbekanntes Gerät oder eine Nicht-http(s)-URL ergibt `422` |
| `tags` | string[] | Tag-Namen; ersetzt alle bisherigen. Unbekannte Namen werden für den Token-Inhaber angelegt |
| `password` | string\|null | Schreibgeschützt: `null`/fehlend = Schutz unverändert, `""` = Schutz entfernen, sonst neues Passwort (mind. 6 Zeichen, höchstens 72 Bytes, sonst `422`). Der Hash wird nie zurückgegeben |

`notes` (interne Notiz) wird **weder angenommen noch zurückgegeben** – ein Body mit `notes`
antwortet mit `422` (`validation_failed`).

`max_clicks` begrenzt die Zahl der Weiterleitungen. Die Zählung erfolgt erst nach dem Absenden der
Antwort (kein Worker, keine Queue), ist also **ungefähr** – ein kurzer gleichzeitiger Ansturm kann ein
paar Klicks mehr durchlassen. Ein `max_clicks` < 1 oder eine `fallback_url` ohne http(s) ergibt `422`.

Antwort (`data`): zusätzlich `id`, `short_url`, `tags`, `clicks`, `last_click_at`,
`owner`, `created_at`, `updated_at`, `password_protected` (bool) und `device_targets`
(object wie `localisations`, z. B. `{"ios": "https://…"}`).

`password` ist **schreibgeschützt**: Die Antwort enthält nie den Hash, sondern nur
`password_protected` (`true`/`false`). Ein gesetztes Passwort schützt die Kurz-URL: Besucher
bekommen zuerst ein Formular, das Ziel wird erst nach der richtigen Eingabe sichtbar (und nie
vorher an Plugins oder in die Antwort gegeben).

`device_targets` lösen in dieser Reihenfolge auf: Geräte-Ziel (iOS/iPadOS → `ios`,
Android → `android`, alles andere inkl. Bots → `desktop`), sonst sprachabhängiges Ziel, sonst
die Standardadresse. Als Eingabe ersetzt das übergebene Array die gesamte Menge.

## Beispiele

```bash
curl -X POST https://links.example.com/api/v1/links \
  -H "Authorization: Bearer zwl_…" -H "Content-Type: application/json" \
  -d '{"target_url": "https://www.example.com/messe", "code": "messe", "domain": "go.example.com"}'
```

```json
{"data": {"id": 12, "short_url": "https://go.example.com/messe", "code": "messe", "target_url": "https://www.example.com/messe", "redirect_type": 302, "active": true, "localisations": {}, "clicks": 0, "…": "…"}}
```

## Kurz-URL auflösen: `/expand`

YOURLS-kompatibel: liefert das Ziel eines Kürzels, ohne einen Klick zu zählen und ohne
weiterzuleiten. `url` ist entweder eine vollständige Kurz-URL (`http`/`https`, der Host
wird zur Domain aufgelöst) oder ein blanker Code. Bei einem blanken Code kann `domain`
den Hostname nennen; ohne `domain` gilt die Standard-Domain des Nutzers.

```bash
curl "https://links.example.com/api/v1/expand?url=https://go.example.com/messe" \
  -H "Authorization: Bearer ***"
curl "https://links.example.com/api/v1/expand?url=messe&domain=go.example.com" \
  -H "Authorization: Bearer ***"
```

```json
{"data": {"id": 12, "code": "messe", "short_url": "https://go.example.com/messe", "target_url": "https://www.example.com/messe", "active": true, "expires_at": null}}
```

Fehler: `400` ohne `url`, `403` fremder Link, `404` unbekannter Code oder unbekannte bzw.
inaktive Domain.

## Gesamtstatistik: `/stats`

Statistik aller eigenen Links (Admins: aller Links) für die letzten `days` Tage. Erlaubt
sind `7`, `30`, `90` und `365` (Standard `30`); jeder andere Wert ergibt `400`. `totals`
und `per_day` zählen nur menschliche Klicks (Bots ausgeschlossen); `top_links` sind die
zehn meistgeklickten Links im selben Zeitraum und Scope.

```bash
curl "https://links.example.com/api/v1/stats?days=30" -H "Authorization: Bearer ***"
```

```json
{"data": {"from": "2026-10-07", "to": "2026-11-05", "totals": {"clicks": 128, "visitors": 97}, "per_day": [{"day": "2026-10-07", "clicks": 4, "visitors": 3}], "breakdowns": {"referrerHost": [{"value": "www.example.com", "clicks": 12}], "countryCode": [], "deviceType": [], "browser": [], "os": [], "language": []}, "top_links": [{"id": 12, "code": "messe", "short_url": "https://go.example.com/messe", "target_url": "https://www.example.com/messe", "clicks": 42}]}}
```

Fehler: `400` bei einem `days`-Wert außerhalb der Liste.

## Fehler

- `401` – Token fehlt, ist ungültig, abgelaufen oder der Nutzer gesperrt
- `403` – Link gehört jemand anderem
- `404` – unbekannter Link/Code oder unbekannte bzw. inaktive Domain
- `400` – Body ist kein JSON-Objekt oder ein Parameter hat einen ungültigen Wert
- `422` – Validierung: `{"error": "validation_failed", "violations": [{"field": "code", "message": "…"}]}`

## KI-Agent-Tokens

Für KI-Agenten (z. B. Claude Code) und andere Automatisierung gibt es unter **Profil → API**
eigene Tokens mit dem Präfix `zwa_` (vor der Umbenennung `emsa_`). Sie benutzen dieselben Endpunkte, dürfen aber weniger:

- **Nie Admin-Rechte**, auch wenn das Token einem Admin gehört: nur eigene Links und die dem
  Nutzer freigegebenen Domains, die Gesamtstatistik nur über eigene Links.
- **Laufen immer ab**: nach 7, 30 oder 90 Tagen.
- **Nur die gewählten Rechte (Scopes)**. Fehlt eines, antwortet die API mit
  `403 {"error": "scope_missing", "required_scope": "…"}`.

| Scope | Endpunkte |
|---|---|
| `domains:read` | `GET /domains` |
| `links:read` | `GET /links`, `GET /links/{id}`, `GET /expand` |
| `links:write` | `POST /links`, `PATCH /links/{id}` |
| `links:delete` | `DELETE /links/{id}` |
| `stats:read` | `GET /links/{id}/stats`, `GET /stats` |

Voreingestellt sind nur die lesenden Scopes. Neue Endpunkte ohne Scope-Angabe sind für
Agent-Tokens gesperrt.

Optionale Grenzen beim Anlegen:

- **Domains**: Das Token sieht und ändert nur Links auf diesen Domains. Die Gesamtstatistik
  (`/stats`) gibt es dann nicht (`403 scope_restricted`), nur die Statistik einzelner Links.
- **Ziel-Hosts**: Ziele (auch sprachabhängige) müssen auf einem dieser Hosts oder einer
  Subdomain davon liegen, sonst `422` mit Feld `target_url` bzw. `localisations`.
- **Absender-Adressen**: IP-Adressen oder CIDR-Bereiche, von denen das Token benutzt werden
  darf. Von anderswo antwortet die API mit `401`.

**Schreibbudget pro Agent-Token**, zusätzlich zum Limit pro Nutzer: 30 schreibende Anfragen
pro Minute und 300 pro Tag, davon höchstens 20 Löschungen pro Tag. Darüber antwortet die API
mit `429 {"error": "rate_limited"}` und `Retry-After`.

**Widerrufen**: im Profil (das Token bleibt als „widerrufen“ sichtbar), für Admins unter
**Administration → KI-Agenten** (einzeln oder alle) oder in der Konsole mit
`php bin/console app:token:revoke <id>` bzw. `--all-agents`. Der **Notschalter** „KI-Agenten
zulassen“ unter **Administration → Einstellungen** sperrt alle Agent-Tokens sofort, ohne sie
zu widerrufen. Persönliche Tokens sind davon nicht betroffen.

Ein abgelaufenes, widerrufenes oder gesperrtes Token und ein Aufruf von einer nicht
freigegebenen Adresse ergeben alle dieselbe Antwort `401`, damit ein fremdes Token nicht
verrät, welche Grenze gegriffen hat.
