# V2 webhook fixtures — captured from the live API

These are **real webhook deliveries**, captured on 2026-08-05 and 2026-08-06 by
registering a webhook against an ngrok tunnel and sending live messages. They are
not copied from the upstream documentation, which matters: the live payloads
carry fields the docs omit — and, in the inbound MMS case, omit a field the docs
say is there.

Phone numbers are redacted — the customer's handset to `61400000000`, our own
number to `61481074185` (the example sender the vendored skills use). On inbound
events those roles are reversed, so it is `mo.recipient` that reads
`61481074185`. Message and webhook IDs are left intact; they are opaque
identifiers for records that no longer exist.

| Fixture | Event |
|---|---|
| `sms-status-sent.json` | `SMS_STATUS`, first of two events for one message |
| `sms-status-delivered.json` | `SMS_STATUS`, second event, **same `status.id`** |
| `sms-inbound-with-last-message.json` | `SMS_INBOUND` with `last_message` populated |
| `mms-status-delivered.json` | `MMS_STATUS`, carrying a carrier `description` |
| `link-hit-sms.json` | `LINK_HIT`, `hits: 1` — captured 2026-08-05, and **not a human tap**; see below |
| `link-hit-sms-repeat.json` | `LINK_HIT`, `hits: 2` — the same link again, proving `hits` is cumulative |
| `mms-inbound-with-media.json` | `MMS_INBOUND` with an inline base64 attachment — captured 2026-08-06, **base64 shrunk**; see below |

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

## The link-hit run, 2026-08-05 — two findings the docs do not contain

One tracked SMS produced six deliveries. The arrival order is the point, so it is
recorded in full; **`timestamp` is when the event happened, and it is not the
order the events reach you in.**

| # | Arrived | Event `timestamp` | Event |
|---|---|---|---|
| 1 | 15:23:11 | 15:23:12Z | `SMS_STATUS` `SENT` |
| 2 | 15:23:14 | 15:23:14Z | `SMS_STATUS` `DELIVERED` |
| 3 | 15:23:14 | 15:23:14Z | `LINK_HIT` `hits: 1` |
| 4 | 15:23:30 | 15:23:30Z | `LINK_HIT` `hits: 2` |
| 5 | **15:24:11** | **15:23:12Z** | **`SMS_STATUS` `SENT` — the same event, redelivered** |
| 6 | 15:24:12 | 15:24:12Z | `LINK_HIT` `hits: 3` |

**1. Deliveries are at-least-once, and a stale status really does arrive after a
newer one.** Row 5 is the `SENT` event redelivered 60 seconds later, carrying its
original `15:23:12Z` timestamp and arriving **57 seconds after `DELIVERED`**. Its
body is byte-identical to row 1, so nothing *in the payload* distinguishes a
duplicate from the original — the guard has to compare against recorded state,
keyed on `status.id`, which is identical across every status event for the
message. This is the documented "a late `SENT` must not overwrite a recorded
`DELIVERED`" hazard, observed rather than assumed.

**2. `hits` counts machine fetches, not human taps.** Row 3 fired in the *same
second* as `DELIVERED`, roughly two seconds after the send — nobody reads an SMS
and taps a link that fast. It is an automated fetch, consistent with a
messaging-app link preview. Row 4, sixteen seconds later, is the real tap. Row 6
is unattributed.

So a `LINK_HIT` is **not** evidence a person clicked, and `hits` is not an
engagement count. Treating it as one over-reports engagement in the same shape as
treating `ACCEPTED` as `DELIVERED`. Anything user-facing should say "link fetched",
and dedupe or delay before inferring intent.

Two smaller things from the same run:

- **`source_message.message` carries the SHORTENED link, while `link_hit.url`
  carries the original destination.** The fixture shows
  `https://tapth.at/qK.LnvtM` in the message and `https://www.example.com/abc` in
  `url`. Code that expects to find the original URL in the message text will not.
- **The composite `message_ref` survived again** — `linkhit-8842:cust-4471`,
  intact through both the status events and the link hits. Note the colon: a
  helper that signs or parses a ref must split on the **last** colon, not the
  first.

## Delivery request shape

No signature. The complete header set observed was `accept-encoding`,
`content-length`, `content-type`, `host`, `sentry-trace`, `traceparent`, and
`user-agent: Go-http-client/2.0`. There is no HMAC, signature, or auth header of
any kind, so a receiver cannot verify a delivery came from Kudosity. Deliveries
originated from `35.197.178.201` (Google Cloud).

Re-confirmed unchanged on the 2026-08-05 link-hit run. That run also saw
`x-forwarded-for`, `x-forwarded-host` and `x-forwarded-proto` — **those are the
ngrok tunnel's, not Kudosity's.** Anything reading them is reading the test rig.

**Kudosity confirmed this in writing on 2026-08-06**, so it is no longer an
inference from one capture: `x-transmitsms-signature` is a V1 mechanism, it is
"currently unsupported with V2s", and it is on the roadmap. Their recommended
substitute is `message_ref` — which is what `SignedMessageRef` signs. Whether
they publish stable egress IP ranges was referred to their product team and is
still open, so `35.197.178.201` above is an observation, not a range to allowlist.

## The MMS run, 2026-08-06 — the inbound shape resembles nothing else

`mms-inbound-with-media.json` is a real `MMS_INBOUND`, captured during the Phase
5 live receiver verification. **Its base64 has been swapped for a 705-byte JPEG.**
The photo that actually arrived made the delivery **204,426 bytes**, of which
204,096 were the one `content` field — so the fixture keeps the shape and drops
the weight. Everything else is verbatim apart from the redacted numbers.

Five things about it contradict the outbound API it was modelled on:

- **Media arrives inline as base64, not as URLs.** The key is `mo.media[]`, whose
  entries are `{"content": "<base64>", "name": "image000000.jpg"}`. There is no
  `content_urls` on an inbound MMS — that is the *outbound* request shape. The
  SDK read `mo.content_urls` until this capture, so an inbound picture parsed
  cleanly and was silently dropped. `InboundEvent::$media` is the fix.
- **No content type anywhere.** A filename is all you get, which is why
  `InboundMedia::mimeType()` sniffs the decoded bytes and treats the extension
  as a fallback. The name comes from a stranger; the bytes do not.
- **`mo.id` is not a UUID.** It was `vj41WbAbHfzIjSMIfB91BH@mmsc.telstra.com` — a
  carrier MMSC identifier with an `@host` suffix. Anything that validates V2 ids
  as UUIDs rejects a real inbound MMS.
- **`mo.sender` carries a leading `+` and `mo.recipient` does not**, in the same
  payload. `SMS_INBOUND` had no `+` on either. Nothing normalises this,
  deliberately — matching numbers across channels has to cope with both forms.
- **No `last_message`, and no `message` key at all** for a picture-only reply.
  So `messageRef()` is null and `isCorrelated()` is false for an inbound MMS,
  while the `SMS_INBOUND` captured a day earlier correlated fine. An MMS reply
  cannot be routed on the correlation key.

## Webhook resource shape, from `POST /v2/webhook`

The create response carries four fields the skill does not document:

```json
{"id":"…","name":"…","url":"…","filter":{"event_type":["LINK_HIT","SMS_STATUS"]},
 "rate_limit":0,"is_sandbox":false,
 "created_at":"2026-08-05T15:23:11.730743151Z","updated_at":"2026-08-05T15:23:11.730743151Z"}
```

`is_sandbox`, `created_at`, `updated_at`, and `rate_limit` echoed back as `0`
meaning system default. Note the timestamps carry **nine** fractional digits, the
same format that defeats `DateTimeImmutable::createFromFormat(RFC3339_EXTENDED, …)`
elsewhere in this SDK.

## Not captured, and why

`OPT_OUT` is deliberately absent. Triggering it means replying STOP, which opts
the test handset out of receiving messages — a one-way door on the only handset
this account can test against.

`MMS_INBOUND` **was** missing, and is now captured — see the 2026-08-06 section.
The 2026-08-05 diagnosis was right: the number needed provisioning, not the SDK.
Kudosity replaced the account's virtual number for exactly this reason, and the
first reply to the replacement produced an event.

`WHATSAPP_*` and `RCS_*` are also absent: the account has neither a WhatsApp
sender nor an RCS agent provisioned.
