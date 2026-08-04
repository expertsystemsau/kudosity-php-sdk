# Kudosity V2 Migration — Design

**Date:** 2026-08-04
**Status:** Approved
**Target release:** 2.0.0 (breaking)

## Goal

Move this SDK onto the Kudosity V2 API (`api.transmitmessage.com`) as its primary
surface, add the channels V2 unlocks (MMS, WhatsApp, RCS), adopt API-managed
webhooks, and rebrand the packages from TransmitSMS to Kudosity.

## Why V2 cannot simply replace V1

The complete V2 endpoint inventory is:

| Area | Endpoints |
|---|---|
| SMS | `POST /v2/sms`, `GET /v2/sms`, `GET /v2/sms/{id}` |
| MMS | `POST /v2/mms`, `GET /v2/mms/{id}` |
| WhatsApp | `POST /v2/whatsapp/messages`, `GET /v2/whatsapp/messages`, `GET /v2/whatsapp/messages/{id}` |
| RCS | `POST /v2/rcs/messages`, `GET /v2/rcs/messages`, `GET /v2/rcs/messages/{id}`, `POST /v2/rcs/capabilities` |
| Webhooks | `POST /v2/webhook`, `GET /v2/webhook`, `GET /v2/webhook/{id}`, `PUT /v2/webhook/{id}`, `DELETE /v2/webhook/{id}` |
| Senders | `POST /v2/senders/registrations`, `GET /v2/senders/registrations`, `POST /v2/senders/registrations/{id}/verifications`, `POST /v2/senders/registrations/{id}/verifications/confirmation`, `DELETE /v2/senders/phone-numbers/{number}` |

V2 has **no equivalent** for contact lists, keywords, virtual-number leasing,
reporting/statistics, account balance, email-to-SMS, `send_at` scheduling,
multi-recipient/bulk sends, cancelling a scheduled send, or custom tracked-link
domains. Kudosity's own guidance is to "start on V2 unless you require
multi-recipient sends or custom link domains" — those are permanently V1
features.

The SDK is therefore dual-API: V2 is the default surface, and V1 remains for the
capabilities that exist nowhere else.

## Scope

In scope:

1. Full rebrand (repo, packages, namespace, config, env, facade, channel names) plus a codemod for consuming projects.
2. Dual-connector client architecture with V2 as the primary surface.
3. V2 SMS, MMS, WhatsApp, RCS resources with typed requests and DTOs.
4. V2 webhook CRUD, typed inbound payloads, Laravel receiver, and artisan commands.
5. V2 sender registrations.
6. Four Laravel notification channels.
7. A real test suite for the client package, plus CI that actually exercises the declared PHP floor.
8. README rewrites, `UPGRADING.md`, CHANGELOG 2.0.0, CLAUDE.md.

Out of scope:

- V1 XML response format (`.xml` suffix).
- WhatsApp template management — Kudosity exposes no API for it; templates are provisioned in the dashboard.
- RCS agent provisioning and carrier launch — account-managed, not API-managed.
- Renaming the GitHub repositories, registering the new Packagist entries, and marking the old packages abandoned. These are dashboard actions; the work delivers a release checklist instead.

---

## 1. Rebrand

### Renames

| Now | After |
|---|---|
| repo `expertsystemsau/transmitsms-php-sdk` | `expertsystemsau/kudosity-php-sdk` |
| `packages/transmitsms-client` | `packages/kudosity-client` |
| `packages/transmitsms-laravel` | `packages/kudosity-laravel` |
| package `expertsystemsau/transmitsms-php-client` | `expertsystemsau/kudosity-php-client` |
| package `expertsystemsau/transmitsms-laravel-client` | `expertsystemsau/kudosity-laravel-client` |
| namespace `ExpertSystems\TransmitSms\` | `ExpertSystems\Kudosity\` |
| namespace `ExpertSystems\TransmitSms\Laravel\` | `ExpertSystems\Kudosity\Laravel\` |
| namespace `ExpertSystems\TransmitSms\Tests\` | `ExpertSystems\Kudosity\Tests\` |
| `TransmitSmsClient` | `KudosityClient` |
| `TransmitSmsConnector` | `KudosityV1Connector` + `KudosityV2Connector` |
| `TransmitSmsRequest` | `KudosityV1Request` + `KudosityV2Request` |
| `TransmitSmsException` | `KudosityException` |
| `TransmitSmsPaginator` | `V1PagedPaginator` |
| `TransmitSmsServiceProvider` | `KudosityServiceProvider` |
| `TransmitSmsChannel` | `KudosityChannel` |
| `TransmitSmsMessage` | `KudosityMessage` |
| facade `TransmitSms` | `Kudosity` |
| `config/transmitsms.php` | `config/kudosity.php` |
| env `TRANSMITSMS_*` | `KUDOSITY_*` |
| `toTransmitSms()` | `toKudosity()` |
| channel string `'transmitsms'` | `'kudosity'` |
| `routeNotificationForTransmitsms()` | `routeNotificationForKudosity()` |

The API hostnames stay `api.transmitsms.com` and `api.transmitmessage.com` —
Kudosity has not renamed them — so those string constants keep their real
values under new constant names (`KudosityV1Connector::BASE_URL`,
`KudosityV2Connector::BASE_URL`).

### Codemod

`bin/kudosity-codemod` — a dependency-free PHP script, plus `rename-map.json`
holding the mapping table as data. Given a target project directory it rewrites:

- FQCNs and `use` statements for every renamed class.
- `toTransmitSms()` → `toKudosity()`.
- The `'transmitsms'` channel string in `via()` arrays and `routeNotificationFor*` methods.
- `config('transmitsms.…')` / `Config::get('transmitsms.…')` → `kudosity.…`.
- `TRANSMITSMS_*` env keys in `.env`, `.env.example` and `config/*.php`.
- The `require` entries in `composer.json`.

Defaults to `--dry-run` and prints a diff; `--write` applies. `UPGRADING.md`
additionally ships a Rector `RenameClassRector` config generated from the same
`rename-map.json`, for projects that prefer Rector.

### Release checklist (manual, for the user)

1. Rename the GitHub monorepo to `kudosity-php-sdk`.
2. Create `expertsystemsau/kudosity-php-client` and `expertsystemsau/kudosity-laravel-client` as split targets.
3. Register both on Packagist.
4. Mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned, pointing at the replacements.
5. Tag `v2.0.0` — the `v` prefix is required or `split.yml` never fires.

---

## 2. Client architecture

### Connectors

Two connectors, because nothing about the transport is shared:

| | `KudosityV1Connector` | `KudosityV2Connector` |
|---|---|---|
| Base URL | `https://api.transmitsms.com` | `https://api.transmitmessage.com` |
| Auth | `BasicAuthenticator(key, secret)` | header `x-api-key: {key}` |
| Body | `application/x-www-form-urlencoded` | `application/json` |
| Paths | `*.json` suffix | `/v2/…`, no suffix |
| Failure detection | `error.code !== 'SUCCESS'` on a 200 | HTTP status only |

Retry configuration, timeout and the `handleRetry()` policy are identical for
both, so they move into a `Concerns\HasRetryPolicy` trait rather than being
duplicated. The existing behaviour is preserved: retry on connection failure,
429 and 5xx; never on other 4xx.

The V2 API never uses the API secret. A client constructed with only a key is
valid for V2 and will fail on V1 — the V1 connector throws a
`KudosityException` with a clear message when the secret is missing, rather than
letting a 401 surface from the wire.

### Client surface

`KudosityClient` owns both connectors and lazily constructs resources. V2 is the
default surface; V1 appears only where the capability exists nowhere else, and
is named by capability (`bulk()`, `lists()`, `reporting()`) rather than by
version, so a call site reads as what it does.

```php
// ---- V2 ----
$k->sms()->send('Hi', to: '61400000000', from: 'MyBrand');   // exactly one recipient
$k->sms()->get($id);
$k->sms()->list(status: MessageStatus::Delivered);           // paginator

$k->mms()->send('Look', to: '…', from: '…', contentUrls: ['https://…/x.jpg']);
$k->mms()->get($id);

$k->whatsapp()->text('Thanks', to: '…');                     // 24h window only
$k->whatsapp()->template('order_update', ['#123', 'shipped'], to: '…');
$k->whatsapp()->custom($payload, to: '…');
$k->whatsapp()->get($id);
$k->whatsapp()->list();

$k->rcs()->send('Shipped', to: '…', from: 'DemoSender', fallback: $smsFallback);
$k->rcs()->capabilities(['61400000000'], sender: 'DemoSender');
$k->rcs()->get($id);
$k->rcs()->list();

$k->webhooks()->create('SMS Events', $url, [WebhookEventType::SmsStatus]);
$k->webhooks()->all();
$k->webhooks()->get($id);
$k->webhooks()->update($id, $webhook);
$k->webhooks()->delete($id);

$k->senders()->registrations();
$k->senders()->register(...);
$k->senders()->requestVerification($registrationId);
$k->senders()->confirmVerification($registrationId, $code);
$k->senders()->deleteByPhoneNumber($number);

// ---- V1, only because V2 has no equivalent ----
$k->bulk()->send('Hi', $upTo500Numbers);
$k->bulk()->sendToList('Hi', listId: 4213644);
$k->bulk()->schedule('Hi', $to, at: $when);
$k->bulk()->cancel($messageId);
$k->lists()      // contact lists and members
$k->reporting()  // sent, responses, stats, message report, delivery status
$k->numbers()    // leasing, options, API-backed formatNumber()
$k->keywords()
$k->account()    // balance
$k->emailSms()

// ---- escape hatches ----
$k->v2()->send($request);
$k->v1()->send($request);
```

### V1 surface changes

The V1 code is otherwise a near-mechanical rename, with three deliberate moves:

- Today's `SmsResource` splits. The V2 `sms()` resource takes the name; the V1 send surface becomes `bulk()` / `BulkSmsResource`, keeping `send()` (multi-recipient), `sendToList()`, `cancel()` and the `configure` closure that reaches every V1 option.
- `getResponses()`, `getResponsesByKeyword*()`, `getAllResponses()` move from the send resource to `reporting()`, where the rest of the read endpoints already live.
- The API-backed `formatNumber()` (`format-number.json`) moves to `numbers()`. The local, offline helpers (`formatNumberLocal()`, `isValidNumber()`, `validateNumbers()`, `isValidSenderId()`) become a `Concerns\FormatsPhoneNumbers` trait used by both send resources, so they are reachable from wherever you are sending.

`Support\PhoneNumber`, `Support\CountryCodes` and `Support\Url` carry over unchanged apart from the namespace.

**Breaking consequence worth calling out in UPGRADING.md:** today
`sms()->send($msg, $to)` accepts up to 500 comma-separated numbers. V2's
`POST /v2/sms` takes exactly one `recipient`, so multi-recipient sends move to
`bulk()->send()`, and scheduled sends to `bulk()->schedule()`.

---

## 3. The three inconsistencies V2 has, and where each is handled

These are the documented sources of integration bugs. Each gets one named seam
instead of being handled ad hoc per endpoint.

### Response envelope

SMS and MMS return the object flat. WhatsApp, RCS, RCS capabilities and sender
registrations wrap it as `{ "data": {…}, "request": {}, "meta": {} }`. Code
written against one shape and reused for the other silently reads null.

`Concerns\UnwrapsData::payload(Response $response): array` returns
`$json['data'] ?? $json`, and every DTO factory goes through it. The per-endpoint
envelope is recorded in a table in the client README so the asymmetry is visible
rather than folklore.

### Pagination

Three schemes, three paginators, selected per request:

| Paginator | Used by | Params | Items at | Terminates on |
|---|---|---|---|---|
| `V1PagedPaginator` | all V1 list endpoints | `page` (1-indexed), `max` | per-request key (`numbers`, `lists`, `keywords`, `recipients`, `messages`, `members`, `responses`) | `page.number >= page.count` |
| `V2PagedPaginator` | `GET /v2/sms` | `page`, `limit` (default 100) | `smses` | `total_records` exhausted, else a short/empty page |
| `V2CursorPaginator` | `GET /v2/whatsapp/messages`, `GET /v2/rcs/messages` | `limit` (1–100, default 25), `cursor`, `direction` | `data.messages` | `meta.pagination.has_next === false` |

The existing `Contracts\PaginatesResults` (declaring `paginationItemsKey()`) is
kept and extended with the paginator kind, so `paginate()` dispatches on what
the request declares. This preserves the 1.9.0 fix rather than reworking it.

`V2PagedPaginator` must not trust `total_records` blindly — it is a **string**,
and cast before arithmetic.

### Errors

Three response shapes:

| Shape | Where | Handling |
|---|---|---|
| `{"error": {"code": "…", "description": …}}` | all V1 | `KudosityException::fromV1Response()` — existing code map, unchanged |
| RFC 9457 Problem Details under `error`, with `issues[]` | V2 messaging endpoints | `KudosityException::fromV2Response()` |
| `{"error": "plain string"}` | V2 webhook endpoints, and `GET /v2/sms/{id}` 404 | `fromV2Response()`, string branch |

`fromV2Response()` reads `error.type`, `error.title`, `error.detail`,
`error.status` and `error.issues[]`, exposes `getIssues(): ProblemIssue[]`
(each with `name` and `message`, so every failed field is reported at once), and
maps status to a subclass:

| Status | Exception |
|---|---|
| 400, 422 | `ValidationException` |
| 401 | `AuthenticationException` |
| 403 | `AccessDeniedException` (reused from V1) |
| 404 | `NotFoundException` *(new)* |
| 429 | `RateLimitException` |
| 5xx | `ServerException` *(new)* |

Both 400 and 422 map to `ValidationException` on purpose: the error registry
documents `InputValidationProblem` as 422, while the RCS and WhatsApp endpoint
references show 400 for the same condition. Handling both avoids depending on
which is true today.

The V1-specific `InsufficientFundsException`, `InvalidSenderException` and
`InvalidRecipientsException` are retained and stay reachable only from the V1
map. `AccessDeniedException` is shared.

### Smaller documented traps, each encoded in the design

- `sms_count`, `total_records` and `total_segments` arrive as **strings**; DTOs cast them to `int`.
- `POST /v2/webhook` returns **201**, not 200.
- `PUT /v2/webhook/{id}` **resets any field you omit** to its default. `webhooks()->update()` therefore takes a complete `WebhookData`, not a partial patch, and the README says why.
- `MMS status: pending` on the immediate response is a submission status, not a failure.
- Enums are treated as open: `WebhookEventType` (10 values), `MessageStatus`, `RcsCapabilityCode` and `SenderStatus` are all resolved with `tryFrom(…) ?? Unknown`, because the docs state these lists will grow.
- **`MessageStatus` is the union of three different documented vocabularies**, which do not agree: the `GET /v2/sms` `status` filter accepts 13 values (`PENDING`, `SENT`, `FAILED`, `DELIVERED`, `ACCEPTED`, `SOFT_BOUNCE`, `HARD_BOUNCE`, `OTHER`, `REJECTED`, `PENDING_APPROVAL`, `SUBMITTED`, `UNDELIVERABLE`, `READ`); webhook status events document 8 (`SENT`, `ACCEPTED`, `DELIVERED`, `FAILED`, `SOFT_BOUNCE`, `HARD_BOUNCE`, `READ`, `OTHER`); WhatsApp and RCS list responses document 5, one of which — `QUEUED` — appears nowhere else. One enum holds the union of all 14; the README records which subset each endpoint actually emits, and `Unknown` covers the rest.
- `SenderStatus::isReadyToUse()` exists because `VERIFIED` means *provisioning* and sending on it fails; only `READY_TO_USE` can send.
- WhatsApp content is expressed as value objects — `TextContent`, `TemplateContent`, `CustomContent` — so the `content.template.name` / `content.text.message` nesting cannot be accidentally flattened. That flattening is the single most common WhatsApp integration error.
- `SmsFallback` is one value object shared by WhatsApp and RCS, with `message` required whenever it is present. RCS `sender` is an **agent ID**, and `RcsResource` rejects a value that looks like a phone number before the request leaves the process.
- `rcs()->capabilities()` accepts up to 100 numbers but the docs recommend batches of 1–10 for routing-time latency; the method documents that and does not treat the result as a send gate.

---

## 4. Webhooks

### Client package — framework-agnostic payloads

`Webhooks\WebhookEvent::fromArray(array $payload)` dispatches on `event_type`
into one of four typed events:

| Class | Event types | Payload root |
|---|---|---|
| `StatusEvent` | `SMS_STATUS`, `MMS_STATUS`, `WHATSAPP_STATUS`, `RCS_STATUS` | `status` |
| `InboundEvent` | `SMS_INBOUND`, `MMS_INBOUND`, `WHATSAPP_INBOUND`, `RCS_INBOUND` | `mo` |
| `LinkHitEvent` | `LINK_HIT` | `link_hit` |
| `OptOutEvent` | `OPT_OUT` | `opt_out` |

`InboundEvent` exposes `last_message` (optional — it is best-effort) because
`last_message.message_ref` is the documented join key for two-way messaging.
`routed_via` is exposed on both status and inbound events, since when a shared
number delivered the message the recipient did not reply to your sender, and
pairing on `sender`/`recipient` breaks.

`MMS` link-hit `source_message` additionally carries `subject` and
`content_urls`; the DTO models that.

### Laravel package — receiving

- One route, `POST {prefix}/events`, handles all ten event types and dispatches a typed Laravel event per category, plus the existing signed-handler job dispatch.
- Authenticity: V2 documents no signature header, so the existing signed-URL scheme is retained — `CallbackUrlBuilder` puts the signature in the query string of the URL we register with `POST /v2/webhook`, and V2 preserves the query string when it POSTs. `CallbackUrlParser` continues to verify it. This is the only authenticity check available and the README states that plainly.
- The three V1 GET routes (`dlr`, `reply`, `link-hits`) remain, because V1 sends still deliver callbacks that way and V1 callbacks do not fire for V2 messages.
- Handling is documented as idempotent on `status.id`: multiple status events per message, arrival order not guaranteed, so a late `SENT` must not overwrite a recorded `DELIVERED`. `ACCEPTED` is not `DELIVERED`.

### Laravel package — managing

Artisan commands, the ergonomic payoff of API-managed webhooks:

- `kudosity:webhook:list`
- `kudosity:webhook:install` — registers a webhook pointing at this app's receiver route, with chosen event types and optional filters
- `kudosity:webhook:delete`

---

## 5. Laravel integration

### Channels

Four channels, four message builders:

| Channel | Builder | Endpoint |
|---|---|---|
| `kudosity` | `KudosityMessage` | `POST /v2/sms`, or V1 `send-sms.json` when a V1-only option is used |
| `kudosity-mms` | `KudosityMmsMessage` | `POST /v2/mms` |
| `kudosity-whatsapp` | `KudosityWhatsAppMessage` | `POST /v2/whatsapp/messages` |
| `kudosity-rcs` | `KudosityRcsMessage` | `POST /v2/rcs/messages` |

### SMS channel routing

`KudosityMessage` sends over V2 by default and falls back to V1 only when the
message uses something V2 cannot do:

- `toList()`
- `sendAt()`
- `validity()`
- `repliesToEmail()`
- `dlrCallback()` / `replyCallback()` / `linkHitsCallback()` (per-send callback URLs)
- a multi-recipient `to`

`forceV1()` and `forceV2()` override the decision; `apiVersion()` reports which
will be used, so the routing is inspectable rather than magic, and `forceV2()`
with a V1-only option set throws rather than silently dropping it.

The two send responses have different shapes, so both DTOs implement a shared
`Contracts\SentMessage` interface and the channel's return type stays stable
across the routing decision:

```php
id(): string             // V2 UUID, or the V1 message_id cast to string
recipientCount(): int    // 1 for V2, the V1 `recipients` count otherwise
status(): ?MessageStatus // V2 only — the V1 send response carries no status
```

### Config

`config/kudosity.php`:

```php
'api_key'      => env('KUDOSITY_API_KEY', ''),
'api_secret'   => env('KUDOSITY_API_SECRET', ''),   // V1 only
'timeout'      => env('KUDOSITY_TIMEOUT', 30),
'from'         => env('KUDOSITY_FROM', ''),
'country_code' => env('KUDOSITY_COUNTRY_CODE'),
'base_url'     => [
    'v1' => env('KUDOSITY_BASE_URL_V1', 'https://api.transmitsms.com'),
    'v2' => env('KUDOSITY_BASE_URL_V2', 'https://api.transmitmessage.com'),
],
'mms'      => ['sender'   => env('KUDOSITY_MMS_SENDER')],
'whatsapp' => ['sender'   => env('KUDOSITY_WHATSAPP_SENDER')],
'rcs'      => ['agent_id' => env('KUDOSITY_RCS_AGENT_ID')],
'webhooks' => [ /* existing keys, plus the V2 events receiver path and rate_limit */ ],
```

The single `base_url` key is replaced by a `v1`/`v2` pair. The old
`useSmsUrl()` / `useMmsUrl()` toggles on the client and connector are removed —
they existed to swap one connector between hosts, which the two-connector
design makes unnecessary.

`KudosityServiceProvider` registers both connectors and the client as singletons
and extends the notification channel manager with all four channels.

---

## 6. Testing, CI and docs

### The PHP 8.2 problem

The declared floor is `php: ^8.2`, but Pest 4.7 requires `^8.3.0` and PHPUnit 12
requires `>=8.3` — so today nothing proves 8.2 works, and the client package
ships an empty `tests/` directory exercised only through the root Laravel suite.

Resolution, which also closes the empty-test-directory gap:

- **`packages/kudosity-client/tests/`** gets a real PHPUnit 11 suite (PHPUnit 11 supports PHP 8.2). It is framework-agnostic, installed and run standalone in CI — `cd packages/kudosity-client && composer install && vendor/bin/phpunit` — on **PHP 8.2, 8.3 and 8.4**. Running it standalone also proves the client package genuinely installs without Laravel.
- **The root Pest 4 suite** keeps covering the Laravel package on **8.3/8.4 × Laravel 11/12**.

This reinstates a `test-client` CI job, which 1.9.0 removed for running nothing.
It is justified now because it will run real tests.

### Coverage

Client package (PHPUnit, Saloon `MockClient` + JSON fixtures per endpoint):

- Every V2 request: URL, method, headers (`x-api-key` present, secret absent), JSON body shape.
- Every DTO: both envelope shapes, string-to-int casts, absent optional fields.
- Error mapping: all three error shapes, every status→exception mapping, `issues[]` extraction.
- All three paginators, including termination conditions and a short final page.
- WhatsApp content value objects producing the correct nesting; RCS rejecting a phone-number sender.
- Webhook payload parsing for all ten event types, including a missing `last_message` and a `routed_via` present.
- Enum `Unknown` fallback for an unrecognised value.

Root Pest suite:

- All four channels, including the SMS V1/V2 routing decision and `forceV2()` conflicting with a V1-only option.
- Service provider bindings, config publishing, facade.
- Webhook receiver route: valid signature, invalid signature, each event type, handler job dispatch.
- The three artisan commands.
- Arch tests carried over.

### Docs

- Both package READMEs rewritten around the V2-first surface, including the per-endpoint envelope table and the V1-only capability list.
- `UPGRADING.md`: the rename table, codemod usage, Rector config, config/env mapping, V1→V2 method equivalents, changed DTO shapes, the new exception classes, and webhook migration steps.
- `CHANGELOG.md`: a `2.0.0` entry.
- `CLAUDE.md`: updated architecture, commands and namespace sections.
- `split.yml`: matrix repointed at the new sub-repo targets. **`actions/checkout@v4` stays pinned** — `splitsh-action@v1.0.0` unsets the inline `http.extraheader` token that v4 writes, and checkout v5+ stores credentials differently, which aborts the split before any push.

---

## Sequencing

Each step is independently reviewable and leaves the suite green.

1. **Rebrand.** Directory moves, namespace and class renames, config/env keys, `composer.json`, `split.yml`, autoload paths. Touches nearly every file but is mechanical. Ships the codemod and `rename-map.json`.
2. **V2 core.** `KudosityV2Connector`, `KudosityV2Request`, `UnwrapsData`, `HasRetryPolicy`, the V2 error path with `NotFoundException`/`ServerException`, `V2PagedPaginator`, `V2CursorPaginator`. Split the V1 `SmsResource` into `bulk()` and move the read methods to `reporting()`.
3. **V2 channels.** SMS, MMS, WhatsApp, RCS resources, requests, DTOs, enums, content value objects, `SmsFallback`.
4. **Webhooks and senders.** Webhook CRUD resource, `WebhookFilter`, the typed inbound payload DTOs, the senders resource and `SenderStatus`.
5. **Laravel integration.** Four channels and builders, service provider, config, the events receiver route, the three artisan commands.
6. **Tests, CI, docs, release.** Client PHPUnit suite and the 8.2/8.3/8.4 job, root Pest additions, README/UPGRADING/CHANGELOG/CLAUDE.md, release checklist.

## Assumptions

- The V2 API is reachable with the existing account's API key; no new credential is needed for V2 (the key is shared, the secret is V1-only).
- Endpoint contracts are taken from `developers.kudosity.com` and the vendored `.agents/skills/` references as of 2026-08-04. Where the two disagree — the 400/422 validation status — both are handled.
- RCS endpoints are marked beta upstream and may change; the RCS resource is built to the documented shape and its DTOs tolerate unknown fields.
- No external consumers exist outside internal projects, so a clean break with a documented codemod is acceptable and no compatibility shims are shipped.
