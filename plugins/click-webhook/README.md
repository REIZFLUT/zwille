# click-webhook

Reports every recorded click to an external system as a signed JSON `POST` - a CRM, a
warehouse, a customer's own analytics. It is the "app to app over an authenticated API"
pattern: a machine-readable event, authenticated with an HMAC signature so the receiver can
trust the call really came from this installation.

It listens to `App\Event\Tracking\ClickRecordedEvent`, which fires in the `kernel.terminate`
path **after** the visitor has already received their redirect (under PHP-FPM after
`fastcgi_finish_request()`). A slow endpoint only occupies a worker and never delays a
visitor - but the call also never reaches the response, so a delivery failure is logged and
never thrown: the click is already committed and nothing here may undo it.

## Settings

| Key | Type | Meaning |
|---|---|---|
| `url` | string | Target of the webhook. Without it the plugin does nothing (no request is made). |
| `timeout` | float (default `2`) | Request timeout in seconds. |
| `secret` | string (optional) | When set, each request carries `X-Zwille-Signature: sha256=<hmac>` over the raw body. The same value is also sent as `X-Emsl-Signature` (deprecated name from before the rename, will be dropped in a later major version). |
| `headers` | map (optional) | Extra HTTP headers, e.g. an API key. |

Payload (JSON):

```json
{
    "event": "click.recorded",
    "link_id": 42,
    "short_code": "spring",
    "short_url": "https://go.example.com/spring",
    "target_url": "https://www.example.com/spring?utm_source=newsletter",
    "occurred_at": "2026-11-06T09:30:00+01:00",
    "country_code": "DE",
    "device_type": "mobile",
    "unique_visitor": true
}
```

`unique_visitor` is true for the visitor's first click on this link on this calendar day - the
same visitor the link statistics count. It is a best-effort hint: two simultaneous first clicks
may both carry `true`.

The signature lets the receiver verify authenticity and integrity: compute
`hash_hmac('sha256', $rawBody, $secret)` and compare it (in constant time) with the hex value
after `sha256=`.

## Enabling it

Add it to [`config/plugins.yaml`](../../config/plugins.yaml); keep the secret out of the
repository and read it from an environment variable:

```yaml
plugins:
    click-webhook:
        enabled: true
        settings:
            url: 'https://crm.example.com/hooks/clicks'
            secret: '%env(CLICK_WEBHOOK_SECRET)%'
            timeout: 2.5
            headers:
                X-Api-Key: '%env(CRM_API_KEY)%'
```

The change takes effect on the next request; a whitelist change needs no `cache:clear`.
The settings reach the plugin as the container parameter `app.plugins.click-webhook.settings`; `services.yaml`
injects that parameter into `ClickWebhookListener`. See
[`plugins/README.md`](../README.md) for the contract.
