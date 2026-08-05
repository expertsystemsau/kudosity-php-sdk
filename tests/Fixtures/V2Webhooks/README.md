# V2 webhook fixtures — captured from the live API

These are **real webhook deliveries**, captured on 2026-08-05 by registering a
webhook against an ngrok tunnel and sending live messages. They are not copied
from the upstream documentation, which matters: the live payloads carry fields
the docs omit.

Phone numbers are redacted — the recipient to `61400000000`, the sender to
`61481074185` (the example sender the vendored skills use). Message and webhook
IDs are left intact; they are opaque UUIDs for records that no longer exist.

| Fixture | Event |
|---|---|
| `sms-status-sent.json` | `SMS_STATUS`, first of two events for one message |
| `sms-status-delivered.json` | `SMS_STATUS`, second event, **same `status.id`** |
| `sms-inbound-with-last-message.json` | `SMS_INBOUND` with `last_message` populated |
| `mms-status-delivered.json` | `MMS_STATUS`, carrying a carrier `description` |

## Three fields the documentation does not mention

1. **`webhook_id`** (top level) — which registration fired. Useful when running
   separate webhooks per environment or event group.
2. **`webhook_name`** (top level) — the registration's name.
3. **`status.description`** on `MMS_STATUS` — carrier-level detail, e.g.
   `"Sent to Vodafone (response status details: Success)"`. Good for diagnostics
   and absent from the documented example.

Any DTO built for these events should carry all three.

## Behaviours these fixtures pin

- **Two status events arrived for one message**, `SENT` then `DELIVERED`, four
  seconds apart, sharing one `status.id`. The docs warn they are not guaranteed
  to arrive in order, so handling must be idempotent and keyed on `status.id` —
  a late `SENT` must never overwrite a recorded `DELIVERED`.
- **`status.status` is UPPERCASE** here (`SENT`, `DELIVERED`), whereas the send
  endpoints answer lowercase. `MessageStatus::fromApi()` is case-insensitive for
  exactly this reason.
- **`last_message.message_ref` is the reply-threading join key.** The inbound
  fixture proves a `message_ref` set on the outbound (`order-9931:cust-4471`)
  survives the round trip through a customer reply. Route replies on this, not
  on the phone number — number matching breaks when one contact is in two flows
  at once, and again when `routed_via` shows a shared number was used.
- **In an inbound payload, `mo.sender` is the customer and `mo.recipient` is your
  number** — the reverse of an outbound. Note the webhook `filter`'s `sender`
  key matches against `mo.recipient` for inbound events, i.e. it filters by
  *your* number.
- **Real message text is untidy** — the captured reply is `"YES "`, with a
  trailing space. Do not assume trimmed input.

## Delivery request shape

No signature. The complete header set observed was `accept-encoding`,
`content-length`, `content-type`, `host`, `sentry-trace`, `traceparent`, and
`user-agent: Go-http-client/2.0`. There is no HMAC, signature, or auth header of
any kind, so a receiver cannot verify a delivery came from Kudosity. Deliveries
originated from `35.197.178.201` (Google Cloud).

## Not captured, and why

`MMS_INBOUND` is missing. Two picture-message replies to the dedicated number
produced no event at all, with `MMS_INBOUND` present in the webhook filter and
the receiving endpoint verified reachable throughout — while inbound *SMS* to the
same number from the same handset worked. This points at inbound MMS needing
separate provisioning on the number rather than an SDK or configuration fault.
Open question with Kudosity.

`WHATSAPP_*` and `RCS_*` are also absent: the account has neither a WhatsApp
sender nor an RCS agent provisioned.
