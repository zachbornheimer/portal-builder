# Anonymizer contract

DragonGate sends judge-facing files to an anonymizer host, then stores the returned bytes. The built-in host is **All Intersections** at `https://api.allintersections.com`. A portal may inherit the site default, override it, or leave both blank — blank falls through to All Intersections.

A custom host is allowed if it implements **the same two POSTs**. Do not invent other paths.

## 1. Create

`POST /v1/anonymizations`

- Multipart field: `file`
- Headers: `Authorization: Bearer {key}`, `Idempotency-Key: {uuid-v4}`
- The key is a header only. Never put it in the query string.

Success envelope:

```json
{
  "object": "anonymization",
  "request_id": "req_01HZK2N5GQR9T8X4B6FJW3Y1AS",
  "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
  "livemode": true,
  "data": {
    "id": "an_01example",
    "status": "completed",
    "filename": "score.pdf",
    "file_type": "application/pdf",
    "bytes": 20480,
    "created": 1700000000
  }
}
```

`data.status` must be `completed` before the next call.

## 2. Content

Immediate `POST /v1/anonymizations/{id}/content`

- Same Bearer and Idempotency-Key headers
- Response body is the **raw file**, not JSON
- Persist those bytes. The API is not a file host.

## Operator rules

- **Fail-open (default):** any error, timeout, or invalid magic (`%PDF`, MP3, JPEG, PNG) keeps the original file. The operator log dest is `fail`, not success.
- **Fail-closed:** when `anonymizeFailClosed` is on, the same errors refuse the original. Submit and stage do not store identifying bytes.
- **Missing key:** when anonymize is on and the resolved API key (or a usable host) is empty, submit and stage fail with a validation error. This is never a silent skip.
- Timeout is at least **120 seconds**.
- Max upload is **50 MiB**. Larger files skip the API.
- Retry 5xx with the **same** idempotency key.
- Site Default Settings store the default host, key, and on/off. A portal field that is empty or `null` inherits. Clearing a portal field returns to inherit.

See `PORTAL-MODEL.md` PortalOptions for the definition fields.
