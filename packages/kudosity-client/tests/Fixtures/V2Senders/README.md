# V2 sender fixtures — what is real and what is not

## `registrations-empty.json`

**A real response**, captured 2026-08-06 from `GET /v2/senders/registrations` on
the live account. It is the *envelope* evidence, and the envelope is the part that
matters, because it matches neither existing V2 shape:

- items are **`data`-wrapped and nested**, at `data.registrations`
- it is **page-based**, but reports its total as `meta.pagination.total_count` —
  an int, in the place the *cursor* paginator looks, under a different name from
  SMS's `total_records`
- it echoes back the `limit` it applied, and **defaults to 25** where SMS defaults
  to 100. Dividing a total by the wrong limit walks off the end of the results,
  so `V2PagedPaginator` prefers the reported limit.
- `meta.pagination.type: "page"` — the API names its own scheme.

## No populated fixture, and why

**The account holds zero sender registrations**, so no real registration row has
ever been observed. That is not an oversight in the capture; it is a fact about
what this endpoint covers.

`POST /v2/senders/registrations` accepts exactly one `type` —
`PERSONAL_MOBILE_NUMBER` — so it registers *a personal mobile number*, verified by
an SMS code. Alphanumeric sender IDs, WhatsApp Business senders and RCS agents are
not self-service and never appear here. The account's working AU number is a
**leased virtual number**, which is not a registration at all; V1's
`get-numbers.json?filter=owned` is where those live.

Completing a real registration would mean registering someone's personal mobile
and sending a live code to it, so it was deliberately not done.

Consequence for the code: `SenderRegistrationData` reads **every** field
defensively, requires none, and keeps the payload in `raw`. **If you have a real
registration in hand, dump `raw` and widen the DTO** — that is the intended next
step.

## The request schemas were not guessed

The skill documents these endpoints without their request bodies. Rather than
infer wire names — which is how a call that looks successful does nothing, since
an unsupported parameter is silently ignored — the schemas were read out of the
API's own validation errors:

| Probe | Response |
|---|---|
| `POST /v2/senders/registrations` `{}` | `sender is required`, `country is required`, `type is required` |
| …with an unrecognised `type` | `type must be one of: PERSONAL_MOBILE_NUMBER` |
| `POST …/registrations/{id}/verifications` `{}` | `method is required`, `originating_sender is required` |
| …with an unrecognised `method` | `method must be one of: SMS` |
| `POST …/verifications/confirmation` `{}` | `code is required` |
| `DELETE /v2/senders/phone-numbers/{number}` | 404 `sender not found` — so the endpoint exists and the number is the key |

Every probe used deliberately invalid values so it could only ever be rejected,
and the account was confirmed unchanged afterwards.

One field's *meaning* is still inferred rather than stated: `originating_sender`
is read as the number the code is sent **from**, since the number being registered
cannot send until the flow completes. Worth confirming on a real run.
