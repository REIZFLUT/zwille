# REST-API v1

Basis-URL: `https://<admin-host>/api/v1` · Format: JSON · Authentifizierung:
persönliches Token aus **Profil → API** als `Authorization: Bearer emsl_…`.

Ein Token handelt mit den Rechten seines Nutzers: eigene Links (Admins: alle),
nur freigegebene Domains (Admins: alle aktiven). Limit: 120 Anfragen pro Minute
und Nutzer (`429` mit `Retry-After`).

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
| `expires_at` | string\|null | ISO 8601 |
| `localisations` | object | `{"en": "https://…", "de-at": "https://…"}` – ersetzt alle bisherigen |
| `tags` | string[] | Tag-Namen; ersetzt alle bisherigen. Unbekannte Namen werden für den Token-Inhaber angelegt |

`notes` (interne Notiz) wird **weder angenommen noch zurückgegeben** – ein Body mit `notes`
antwortet mit `422` (`validation_failed`).

Antwort (`data`): zusätzlich `id`, `short_url`, `tags`, `clicks`, `last_click_at`,
`owner`, `created_at`, `updated_at`.

## Beispiele

```bash
curl -X POST https://links.example.com/api/v1/links \
  -H "Authorization: Bearer emsl_…" -H "Content-Type: application/json" \
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
