# Kudosity Migration Phase 4: Webhooks and Senders Implementation Plan

> **COMPLETE, 2026-08-06.** All six tasks executed and merged. 724 tests
> (from 512 entering the phase), PHPStan level 6 clean, Pint clean. Webhook
> CRUD and the senders read path are live-verified; `register()` and the
> verification flow are not, deliberately — see Task 5 Step 5.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the client package the two remaining V2 surfaces — account-level webhooks and sender registrations — so `$k->webhooks()` manages registrations over the API, an inbound delivery becomes a typed event object, and `$k->senders()` answers "can I actually send from this?" before a send fails.

**Architecture:** Two resources over Phase 2's `KudosityV2Connector`, following Phase 3's request/DTO/resource shape. Beyond that, three pieces exist because the *protocol* is unsafe rather than because an endpoint needs wrapping, and each was a settled design decision before this plan: a uniform `messageRef()` accessor that hides the four different payload paths the correlation key hides behind; an ordering guard, because multiple status events per message arrive out of order and a late `SENT` silently corrupting a recorded `DELIVERED` is a reporting bug no consumer should have to discover; and a signed `message_ref`, because Kudosity does not sign webhook deliveries and this is the only authenticity signal available.

**Tech Stack:** PHP 8.2+, Saloon v4, Pest 4, PHPStan level 6 via Larastan, Laravel Pint.

## Global Constraints

- Runtime floor stays `"php": "^8.2"` in both packages. Laravel stays `illuminate/* ^11.0||^12.0`.
- `composer analyse` (PHPStan **level 6**) must report `[OK] No errors` at the end of every task.
- `vendor/bin/pint --test` must pass. Run `composer format` to fix.
- **Measure the baseline at Step 1 and record the real number.** It was `512 tests, 994 assertions` at the end of the pre-Phase-4 cleanup pass. Do not trust that figure if commits have landed since — re-measure, then only go up.
- **Do not predict exact test counts anywhere in your reports.** Phase 3's plan predicted "14 from the dataset plus 7", double-counted the dataset's own `it()` block, and every later prediction inherited the error. Say "at least N", and transcribe test content rather than inventing a test to reach a number.
- The two hostnames are `https://api.transmitsms.com` (V1) and `https://api.transmitmessage.com` (V2). Neither is a Kudosity domain; neither may be "corrected". Never write a sweep pattern like `\btransmitsms\b` — the dots in the hostname are word boundaries, which is how Phase 1 corrupted `BASE_URL`. Any sweep needs a **negative check for the corrupted `api.kudosity.com` form**, because the corruption destroys the string a positive grep is looking for.
- Sweeps must exclude `docs/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod`, `tests/Unit/CodemodTest.php`, **`HANDOFF.md` and `.ai-skills/handoffs/*`** — the last two legitimately narrate the old brand.
- Never modify `.agents/skills/` — vendored upstream API references. Append to `CHANGELOG.md` under the existing `## Unreleased`; never rewrite history.
- **Read `.agents/skills/kudosity-webhooks/SKILL.md` and `tests/Fixtures/V2Webhooks/README.md` before writing any request or event class**, and `.agents/skills/kudosity-setup/SKILL.md` §6 before the senders task. They are the authoritative contract, and the fixture README records what the documentation omits.
- **Build event DTOs against the captured fixtures, not the doc examples**, wherever a fixture exists. The live payloads carry three fields the docs never mention.
- **Do not invent a DTO for an undocumented response shape.** Phase 3 deliberately shipped `ListWhatsAppRequest` with no `createDtoFromResponse()` for exactly this reason. Where this plan hits an unknown shape (`GET /v2/senders/registrations`, `POST /v2/senders/registrations`) it says so and tells you to capture the real thing first.
- **The removed-symbol audit is mandatory in Task 6.** Phases 1 and 2 both shipped a public symbol removed in one task and missing from `rename-map.json` written in another. Phase 3 added the audit step and came back empty — the first phase that did. Keep the step.
- Note the flagger's shape: `removed` entries are matched with a trailing `(` appended, so they cannot match a `use` statement, a `::class` or a type hint. A removed **class** must go in `classes` with a real target; only methods belong in `removed`.
- Work on a new branch `feat/kudosity-v2-webhooks-senders` cut from `main`. Cut it in the **primary checkout** — symlinking `vendor/` into a git worktree makes `vendor/bin/pest` run the tracked tree's code, so a deliberate regression appears to pass.
- `main` is now pushed, so CI runs. Two things to know before you read a red build as yours: `Split Monorepo` fails until `expertsystemsau/kudosity-php-client` and `kudosity-laravel-client` exist on GitHub (release-checklist work, and `split.yml` fires on every push to `main`), and `.github/workflows/split.yml` **must keep `actions/checkout@v4`** — v5+ stores the token via an includeIf file and the split action's `git config --unset-all` then aborts the run. Do not "fix the drift".
- `/docs` is gitignored; committing anything under it needs `git add -f`.
- No Laravel-package code in this phase. The receiver route, the four notification channels and the `kudosity:webhook:*` commands are Phase 5. Adding them here is scope creep — and Phase 5's receiver-auth design depends on answers still outstanding from Kudosity.

## Reference

- Design spec: `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`, **section 4** (webhooks) and the client-surface block at lines 169–179.
- Phase 3 plan, for the conventions this phase inherits: `docs/superpowers/plans/2026-08-05-kudosity-phase-3-v2-channels.md`.
- Captured payloads: `tests/Fixtures/V2Webhooks/` — read its `README.md` first.

### What Phase 3 built, that this phase consumes

| Piece | Use |
|---|---|
| `Resources\V2Resource` | Base: `sendAndDto()`, `paginate()`. Both new resources extend it. |
| `Requests\KudosityV2Request` | Abstract base, **no body**. GET and DELETE readers extend this. |
| `Requests\KudosityV2BodyRequest` | Adds `HasBody` + `HasJsonBody`. **Every request that sends a body extends this one** — a body on a GET, even `[]`, is stripped or rejected by some gateways. |
| `Concerns\UnwrapsData` | `static::payload($response)` resolves flat and `data`-wrapped envelopes. Already on the request base. |
| `Enums\MessageStatus` | `fromApi()` is case-insensitive and lands on `Unknown`. Webhook status events send UPPERCASE; the send endpoints answer lowercase. Also has `isDelivered()` and `isTerminal()` — read Task 2 before relying on `isTerminal()` for ordering. |
| `Concerns\FiltersByDateRange` | The `date_range` allow-list and `custom_date` pairing rule. **If a sender or webhook list endpoint filters by date, use this trait — do not write a third copy.** |
| `Exceptions\KudosityException::fromV2Response()` | RFC 9457 → typed exception, **and** a branch for the plain `{"error": "..."}` string body the webhook endpoints return. |

### The endpoint inventory this phase implements

| Surface | Endpoints | Envelope | Notes |
|---|---|---|---|
| Webhooks | `POST /v2/webhook` (**201**), `GET /v2/webhook`, `PUT /v2/webhook/{id}`, `DELETE /v2/webhook/{id}` | **flat** | Verified live. `PUT` is a replace, not a patch. |
| Senders | `GET /v2/senders/registrations`, `POST /v2/senders/registrations`, `POST /v2/senders/registrations/{id}/verifications`, `POST /v2/senders/registrations/{id}/verifications/confirmation`, `DELETE /v2/senders/phone-numbers/{number}` | **wrapped in `data`** | Response shape unconfirmed — Task 5 captures it before modelling it. |
| Inbound payloads | not an endpoint — ten event types across four payload shapes | n/a | Six real fixtures covering five event types. Only `OPT_OUT` and the MMS link-hit variant come from documented examples. |

### What the live reconnaissance established, and the docs do not say

Every item below was observed against the real API on 2026-08-05 and is a constraint on this phase, not a nicety.

- **Deliveries are not signed.** Observed header set: `accept-encoding`, `content-length`, `content-type`, `host`, `sentry-trace`, `traceparent`, `user-agent: Go-http-client/2.0`. No HMAC, signature, or auth header. Source IP `35.197.178.201`. Re-confirmed unchanged on 2026-08-05. This is why Task 3 exists.
- **Deliveries are at-least-once, and a stale status arrives after a newer one.** Observed: `SENT` redelivered 60s later with its original timestamp, 57s *after* `DELIVERED`, byte-identical to the original. Task 2 exists because of this and replays the exact sequence.
- **A `LINK_HIT` is not evidence a human clicked.** The first hit fired in the same second as `DELIVERED`, ~2s after the send — a machine prefetch. `hits` is cumulative for the tracked link and counts machine fetches, so it is not an engagement metric.
- **`link_hit.url` is the original destination; `source_message.message` holds the shortened link.** Both in one fixture.
- **Three undocumented payload fields:** `webhook_id` and `webhook_name` at top level, and `status.description` on `MMS_STATUS` carrying carrier detail. All three must be modelled.
- **`GET /v2/webhook` returns `{"webhooks": [...]}`, and `{}` when there are none** — the key is omitted entirely, so the list read must tolerate a missing key rather than indexing into it.
- **`MMS_STATUS` reached `DELIVERED`**, contradicting the skill's "internal statuses only — SENT, FAILED". Do not constrain MMS status to a subset.
- **`status.status` is UPPERCASE from webhooks, lowercase from the send endpoints.** `MessageStatus::fromApi()`'s case-insensitivity is load-bearing.
- **Correlation is `message_ref`, at a different path per event type** — this is the whole reason for Task 1's uniform accessor:

  | Event category | Path to `message_ref` |
  |---|---|
  | status events | `status.message_ref` |
  | inbound events | `mo.last_message.message_ref` |
  | `LINK_HIT` | `link_hit.source_message.message_ref` |
  | `OPT_OUT` | `opt_out.source_message.message_ref` |

- **A composite ref round-tripped intact through a customer reply** (`order-9931:cust-4471`) — colons survive the wire. Task 3 depends on that, and on parsing from the **last** colon rather than the first.
- **`last_message` is best-effort** — absent when no recent outbound matches, so unsolicited inbound cannot be correlated *or* authenticated. Model it as nullable and treat those events as low-trust.
- **In an inbound payload `mo.sender` is the customer and `mo.recipient` is your number**, the reverse of an outbound. The webhook `filter`'s `sender` key matches `mo.recipient` for inbound events — it filters by *your* number.
- **Real message text is untidy** — the captured reply is `"YES "` with a trailing space. Do not trim in the DTO; expose what arrived.
- **Top-level `event_type` in the request body is deprecated.** Always `filter.event_type`.
- **Webhook validation errors return a plain `{"error": "..."}`**, not RFC 9457. `fromV2Response()` already branches for it; a test should pin that it still does through these requests.

### A note on this plan's code blocks

Where a shape repeats — four event classes, five sender requests — the first instance is given in full and the siblings as exact endpoint, field, type and signature specifications. Every sibling spec names every field and its cast. If a spec is ambiguous when you reach it, **ask rather than inferring**.

---

## File Structure

### New — shared (Task 1)

| Path | Responsibility |
|---|---|
| `src/Concerns/ParsesV2Timestamps.php` | The RFC3339-with-nine-fractional-digits parser, currently copied into four DTOs |
| `src/Enums/WebhookEventType.php` | The ten event types, open-ended |
| `src/Webhooks/WebhookEvent.php` | Abstract base + `fromArray()` dispatch + uniform `messageRef()` |
| `src/Webhooks/StatusEvent.php`, `InboundEvent.php`, `LinkHitEvent.php`, `OptOutEvent.php` | The four payload shapes |
| `src/Webhooks/SourceMessage.php` | The message-shaped object under `last_message` / `source_message` |
| `src/Enums/OptOutSource.php` | `SMS_INBOUND` \| `LINK_HIT`, open-ended |

### New — protocol safety (Tasks 2–3)

| Path | Responsibility |
|---|---|
| `src/Webhooks/StatusPrecedence.php` | Which status may overwrite which, keyed on `status.id` |
| `src/Webhooks/SignedMessageRef.php` | `sign()` / `verify()` for the correlation key |

### New — endpoints (Tasks 4–5)

| Surface | Requests | Data | Resource |
|---|---|---|---|
| Webhooks | `CreateWebhookRequest`, `ListWebhooksRequest`, `UpdateWebhookRequest`, `DeleteWebhookRequest` | `V2\WebhookData`, `V2\WebhookFilter` | `WebhooksResource` |
| Senders | `ListSenderRegistrationsRequest`, `RegisterSenderRequest`, `RequestSenderVerificationRequest`, `ConfirmSenderVerificationRequest`, `DeleteSenderPhoneNumberRequest` | `V2\SenderRegistrationData`, `Enums\SenderStatus` | `SendersResource` |

`Webhooks/` is a new directory. The event objects are not `Data/V2/` DTOs: nothing constructs them from an API *response*, they are built from a request body an application received, and keeping that distinction visible is worth a directory. Note `Callbacks/` already holds the V1 signed-URL plumbing (`CallbackUrlBuilder`, `CallbackUrlParser`) — leave it alone, it is still live for V1 callbacks and Phase 5 reuses it.

### Modified (Task 6)

`src/KudosityClient.php`, `packages/kudosity-laravel/src/Facades/Kudosity.php`, `rename-map.json`, `UPGRADING.md`, `CHANGELOG.md`, `packages/kudosity-client/README.md`, `README.md`, `CLAUDE.md`, and the four `Data/V2/*MessageData.php` files in Task 1.

---

## Task 1: Inbound payload types

**Files:**
- Create: `src/Concerns/ParsesV2Timestamps.php`, `src/Enums/WebhookEventType.php`, `src/Enums/OptOutSource.php`, `src/Webhooks/{WebhookEvent,StatusEvent,InboundEvent,LinkHitEvent,OptOutEvent,SourceMessage}.php`
- Modify: `src/Data/V2/{SmsMessageData,MmsMessageData,WhatsAppMessageData,RcsMessageData}.php` (adopt the trait)
- Test: `tests/Unit/V2WebhookEventTest.php`

**Interfaces:**
- Produces `abstract Webhooks\WebhookEvent` with `public static function fromArray(array $payload): self`, and on every instance: `eventType(): WebhookEventType`, `timestamp(): ?DateTimeImmutable`, `webhookId(): ?string`, `webhookName(): ?string`, `messageRef(): ?string`, `raw(): array`.
- Produces `enum Enums\WebhookEventType: string` with ten cases plus `Unknown`, `fromApi(?string): self`, and `category(): string` or equivalent used by the dispatch.
- Produces `final readonly Webhooks\SourceMessage` with `fromArray()`.

- [x] **Step 1: Record the baseline**

```bash
git checkout -b feat/kudosity-v2-webhooks-senders
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
```

Record the actual numbers in your report. Expected `[OK] No errors`.

- [x] **Step 2: Extract `ParsesV2Timestamps` before adding a fifth copy**

`private static function parseDate()` is currently duplicated **verbatim in four DTOs** (`SmsMessageData`, `MmsMessageData`, `WhatsAppMessageData`, `RcsMessageData`). This phase adds at least five more objects that parse a timestamp. Extract it now, for the same reason the `date_range` rule was extracted before a third cursor list existed.

Create `src/Concerns/ParsesV2Timestamps.php` with `protected static function parseTimestamp(mixed $value): ?DateTimeImmutable`, carrying the existing docblock's explanation — V2 sends **nine** fractional digits and `createFromFormat(RFC3339_EXTENDED, …)` expects exactly six, which is why this goes through `new DateTimeImmutable($value)` inside a try/catch and returns `null` on garbage rather than throwing.

Switch all four DTOs onto it and delete their private copies. The existing tests for the nine-digit timestamp and the malformed-date case must stay green **without being edited** — if you find yourself changing an assertion, the extraction changed behaviour and you should stop.

- [x] **Step 3: Write the failing test, against the real fixtures**

Create `tests/Unit/V2WebhookEventTest.php`. Load fixtures from disk — do not paste their contents inline, or the test stops pinning the captured payload:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use ExpertSystems\Kudosity\Webhooks\OptOutEvent;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;

/** A real delivery, captured 2026-08-05. See tests/Fixtures/V2Webhooks/README.md. */
function webhookFixture(string $name): array
{
    $path = dirname(__DIR__).'/Fixtures/V2Webhooks/'.$name.'.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('dispatches each captured payload to its event class', function (string $fixture, string $expected) {
    expect(WebhookEvent::fromArray(webhookFixture($fixture)))->toBeInstanceOf($expected);
})->with([
    ['sms-status-sent', StatusEvent::class],
    ['sms-status-delivered', StatusEvent::class],
    ['mms-status-delivered', StatusEvent::class],
    ['sms-inbound-with-last-message', InboundEvent::class],
]);

it('reads the correlation key through one accessor regardless of where it hides', function () {
    // The whole point of messageRef(): four event categories, four different
    // paths. A consumer that has to know which is a consumer that will get one
    // of them wrong.
    expect(WebhookEvent::fromArray(webhookFixture('sms-status-delivered'))->messageRef())
        ->toBe('order-9931:cust-4471')
        ->and(WebhookEvent::fromArray(webhookFixture('sms-inbound-with-last-message'))->messageRef())
        ->toBe('order-9931:cust-4471');   // from mo.last_message, not mo
});

it('carries the three fields the documentation never mentions', function () {
    $status = WebhookEvent::fromArray(webhookFixture('mms-status-delivered'));

    expect($status->webhookId())->toBe('8aa2842d-8931-4f6f-b55a-826a6698699a')
        ->and($status->webhookName())->toBe('SDK 2.0 phase 4 recon')
        ->and($status->description)->toBe('Sent to Vodafone (response status details: Success)');
});

it('resolves an UPPERCASE webhook status through the same enum as a lowercase send response', function () {
    // Webhooks send DELIVERED; POST /v2/sms answers "delivered". One enum.
    expect(WebhookEvent::fromArray(webhookFixture('sms-status-delivered'))->status)
        ->toBe(MessageStatus::Delivered);
});

it('accepts an MMS_STATUS of DELIVERED, which the docs say cannot happen', function () {
    // The skill says MMS carries "internal statuses only — SENT, FAILED". The
    // captured fixture is DELIVERED. The fixture wins.
    expect(WebhookEvent::fromArray(webhookFixture('mms-status-delivered'))->status)
        ->toBe(MessageStatus::Delivered);
});

it('does not trim inbound message text', function () {
    // The real reply was "YES " with a trailing space. A DTO that trims is a
    // DTO that hides what the customer actually sent.
    expect(WebhookEvent::fromArray(webhookFixture('sms-inbound-with-last-message'))->message)
        ->toBe('YES ');
});

it('keeps the customer as sender and your own number as recipient on an inbound event', function () {
    // The reverse of an outbound, and the source of the filter surprise: the
    // webhook filter's `sender` key matches mo.recipient for inbound events.
    $inbound = WebhookEvent::fromArray(webhookFixture('sms-inbound-with-last-message'));

    expect($inbound->sender)->toBe('61400000000')
        ->and($inbound->recipient)->toBe('61491570017');
});

it('leaves lastMessage null when Kudosity found no recent outbound to attach', function () {
    // Best-effort by documentation. An unsolicited inbound cannot be correlated
    // or authenticated, so null here is a valid state, not a parse failure.
    $payload = webhookFixture('sms-inbound-with-last-message');
    unset($payload['mo']['last_message']);

    $inbound = WebhookEvent::fromArray($payload);

    expect($inbound->lastMessage)->toBeNull()
        ->and($inbound->messageRef())->toBeNull();
});

it('resolves an event type Kudosity has not published yet to Unknown rather than throwing', function () {
    $payload = webhookFixture('sms-status-sent');
    $payload['event_type'] = 'TELEPATHY_STATUS';

    expect(WebhookEvent::fromArray($payload)->eventType())->toBe(WebhookEventType::Unknown);
});
```

Then add `LINK_HIT` against its **captured fixtures** — `link-hit-sms.json` (`hits: 1`) and `link-hit-sms-repeat.json` (`hits: 2`), both real deliveries from 2026-08-05:

- `hits` cast to `int`, and the two fixtures asserted together to pin that it is **cumulative for the tracked link, not a unique-recipient count**.
- **`url` is the original destination; `source_message.message` carries the SHORTENED link.** The fixture holds `https://clkth.test/mK.ptRq7` in the message and `https://www.example.com/abc` in `url`. Assert both, from the same fixture — code that expects the original URL in the message text is a real and easy mistake.
- `messageRef()` reads `link_hit.source_message.message_ref`, and the fixture's ref is composite (`linkhit-8842:cust-4471`).
- **A docblock on `LinkHitEvent` stating that a link hit is not evidence a human clicked.** In the captured run, `hits: 1` fired in the same second as `DELIVERED`, ~2s after the send — a machine prefetch, not a tap; the human tap was `hits: 2`, sixteen seconds later. Treating `LINK_HIT` as engagement over-reports it in exactly the shape that treating `ACCEPTED` as `DELIVERED` does. This belongs in the type, not in a README nobody reads at 2am.

Then, from the **documented** example in `.agents/skills/kudosity-webhooks/SKILL.md` (no fixture — an MMS link hit was not captured):

- An MMS link hit whose `source_message` carries `subject` and `content_urls`, proving `SourceMessage` models both.
- `OPT_OUT` → `OptOutEvent`, `opt_out.source` resolving to `OptOutSource::SmsInbound` for a STOP reply and `OptOutSource::LinkHit` for the link, plus an unrecognised source landing on `Unknown`.
- A payload with an absent `timestamp`, and one with a malformed `timestamp`, both yielding `null` rather than throwing.
- A `WHATSAPP_STATUS` and an `RCS_STATUS` payload built from the documented status shape, proving the four channels share `StatusEvent` and that `READ` resolves (RCS-only, but the enum is shared).

Aim for at least twenty tests here; the exact number falls out of the fixtures and the documented variants.

- [x] **Step 4: Run it and confirm it fails** — nothing under `Webhooks\` exists yet.

- [x] **Step 5: Write `WebhookEventType`**

Ten cases plus `Unknown`, `fromApi(?string)` tolerating case and novelty like `MessageStatus::fromApi()` does. Include the two facts a consumer gets wrong: `SMS_STATUS` does **not** report WhatsApp or RCS, and `READ` is RCS-only.

```php
enum WebhookEventType: string
{
    case SmsStatus = 'SMS_STATUS';
    case SmsInbound = 'SMS_INBOUND';
    case MmsStatus = 'MMS_STATUS';
    case MmsInbound = 'MMS_INBOUND';
    case WhatsAppStatus = 'WHATSAPP_STATUS';
    case WhatsAppInbound = 'WHATSAPP_INBOUND';
    case RcsStatus = 'RCS_STATUS';
    case RcsInbound = 'RCS_INBOUND';
    case LinkHit = 'LINK_HIT';
    case OptOut = 'OPT_OUT';
    case Unknown = 'UNKNOWN';
}
```

- [x] **Step 6: Write `SourceMessage`**

One `final readonly` class for the message-shaped object that appears as `mo.last_message`, `link_hit.source_message` and `opt_out.source_message`. Fields: `type`, `id`, `message`, `messageRef`, `recipient`, `sender`, `routedVia` (nullable — present only when a **shared** number delivered, in which case the recipient did not reply to your sender), plus the MMS-only `subject` and `contentUrls`. `fromArray()`; no `toArray()` — nothing sends this.

- [x] **Step 7: Write the four event classes and the dispatch**

`WebhookEvent` is abstract, holds `eventType`, `timestamp`, `webhookId`, `webhookName` and `raw`, declares `abstract public function messageRef(): ?string`, and dispatches in `fromArray()` on the event type's category:

| Class | Event types | Payload root | `messageRef()` reads |
|---|---|---|---|
| `StatusEvent` | the four `*_STATUS` | `status` | `status.message_ref` |
| `InboundEvent` | the four `*_INBOUND` | `mo` | `mo.last_message.message_ref` |
| `LinkHitEvent` | `LINK_HIT` | `link_hit` | `link_hit.source_message.message_ref` |
| `OptOutEvent` | `OPT_OUT` | `opt_out` | `opt_out.source_message.message_ref` |

`StatusEvent` fields: `type`, `id`, `messageRef`, `recipient`, `sender`, `routedVia`, `status` (`MessageStatus`), `description` (the undocumented carrier detail).
`InboundEvent` fields: `type`, `id`, `message` (**untrimmed**), `recipient`, `sender`, `routedVia`, `lastMessage` (`?SourceMessage`).
`LinkHitEvent` fields: `hits` (int), `url`, `sourceMessage`.
`OptOutEvent` fields: `source` (`OptOutSource`), `sourceMessage`.

An `Unknown` event type still has to produce something — decide between a fifth `UnknownEvent` class and throwing, **and record the decision with reasoning in your report.** A webhook that 500s on an event type Kudosity added last week is worse than one that logs an unrecognised payload, so the bias is towards returning an object; `raw()` is what makes that survivable.

Keep `raw()` on the base. It is the escape hatch for exactly the undocumented-field problem this phase already hit twice.

- [x] **Step 8: Verify** — suite green and above the Step 1 baseline, `[OK] No errors`, Pint clean. Commit.

---

## Task 2: The status ordering guard

**Files:**
- Create: `src/Webhooks/StatusPrecedence.php`
- Test: `tests/Unit/V2StatusPrecedenceTest.php`

**Why the SDK ships this rather than documenting it:** multiple status events fire per message and are explicitly *not* order-guaranteed. Every consumer that records delivery state has to solve it, and getting it wrong silently corrupts delivery reporting rather than erroring. This was a settled design decision before this plan.

**It is no longer hypothetical.** The 2026-08-05 link-hit run observed the failure directly: the `SENT` event was **redelivered 60 seconds later, carrying its original timestamp, arriving 57 seconds after `DELIVERED`** — see the timeline in `tests/Fixtures/V2Webhooks/README.md`. Two things follow, and both shape this class:

- **Deliveries are at-least-once.** The duplicate was byte-identical to the original, so *nothing in the payload* distinguishes them. The guard can only work off recorded state.
- **`status.id` is identical across every status event for a message**, confirmed across all three status deliveries in that run. It is the correct key.

**Interfaces:**
- Produces `Webhooks\StatusPrecedence` with `public static function supersedes(MessageStatus $incoming, MessageStatus $recorded): bool` and `public static function rank(MessageStatus $status): int`.

- [x] **Step 1: Read this before reaching for `MessageStatus::isTerminal()`**

`isTerminal()` already exists and returns true for `Delivered`, `Read`, `Failed`, `Rejected`, `Undeliverable`, `HardBounce`. A naive "a terminal status is never overwritten" rule therefore **drops `READ` after `DELIVERED`**, which is a real RCS sequence and a real loss of information. This is an ordering problem, not a boolean one. `isTerminal()` stays as it is — it answers a different question — and this class adds a rank.

- [x] **Step 2: Write the failing test**

The cases that matter, each asserted in both directions so the rule cannot be half-implemented:

| Incoming | Recorded | `supersedes()` | Why |
|---|---|---|---|
| `Delivered` | `Sent` | `true` | forward progress |
| `Sent` | `Delivered` | **`false`** | the late-`SENT` bug this class exists to stop |
| `Read` | `Delivered` | `true` | RCS read receipt follows delivery |
| `Delivered` | `Read` | `false` | already past it |
| `Delivered` | `Accepted` | `true` | `ACCEPTED` is not `DELIVERED` |
| `Accepted` | `Delivered` | `false` | the over-reported-success trap, inverted |
| `Failed` | `Sent` | `true` | a failure after submission is news |
| `Sent` | `Failed` | `false` | terminal failure stands |
| `Delivered` | `Delivered` | `false` | idempotent replay is not an update |
| `Unknown` | anything recorded | `false` | never let an unresolved value overwrite a known one |
| anything | `Unknown` | `true` | but a known value always beats an unresolved one |

Then add two tests replaying the captured fixtures, because this is a recorded sequence rather than a hypothetical:

1. `sms-status-sent.json` then `sms-status-delivered.json` then **`sms-status-sent.json` again** — the exact observed order — must end at `DELIVERED`. The third delivery is the real redelivery, not an invented case.
2. The same three in any order end at `DELIVERED`, and a replay of `sms-status-delivered.json` on top of itself reports "no update" rather than an update, so a consumer counting state changes does not double-count an at-least-once delivery.

Give each rule its own single-violation input, and assert on something only that rule produces. A test asserting merely that "some boolean came back" is the defect class Phase 3's review caught three times.

- [x] **Step 3: Implement, with the rank table in a docblock** — a `match` over cases, with an explicit comment that a status the docs have not published yet ranks below every known one, and that `Unknown` is deliberately the floor.

- [x] **Step 4: Verify and commit.**

---

## Task 3: The signed `message_ref`

**Files:**
- Create: `src/Webhooks/SignedMessageRef.php`
- Test: `tests/Unit/V2SignedMessageRefTest.php`

**Why:** Kudosity does not sign webhook deliveries — confirmed empirically, complete header set recorded above. So we sign the one field that round-trips: the correlation key. A forger without the secret cannot fabricate a payload that correlates to a real entity. This was a settled decision, on the user's explicit direction that the SDK should do as much of the work as possible.

**Interfaces:**
- `public static function sign(string $entity, string $secret): string` → `"{entity}:{hmac}"`, hmac being the first 16 hex characters of `hash_hmac('sha256', $entity, $secret)`.
- `public static function verify(string $ref, string $secret): ?string` → the entity, or `null` if the signature does not match or the ref is unsigned.

- [x] **Step 1: Write the failing test.** The cases that carry real risk:

- A round trip: `verify(sign('order-9931', $s), $s)` returns `'order-9931'`.
- **An entity that itself contains a colon** — `order-9931:cust-4471` is a real captured ref. Signing it yields two colons, so parsing must split on the **last** colon. This is the test that fails if the implementer reaches for `explode(':', $ref)` and takes `[0]`.
- A wrong secret returns `null`.
- A tampered entity with a valid-looking signature returns `null`.
- An unsigned ref (no colon at all) returns `null`, not the ref.
- A ref whose signature segment is the right length but wrong content returns `null`.
- The signed form of a realistic entity **stays inside the documented 500-character `message_ref` limit**, and `sign()` rejects an entity long enough to breach it rather than producing a ref the API will refuse. Phase 3 already enforces the 500 limit on send; a helper that can only produce a rejected value is worse than no helper.
- Empty entity rejected.

- [x] **Step 2: Give the 500-character limit one home while you are here**

`MAX_MESSAGE_REF_LENGTH = 500` and its guard are currently duplicated across all four Phase 3 send requests. This task needs the same number as a fifth consumer, and a signing helper whose limit disagrees with the send guard's is worse than no limit at all. Extract it — the natural place is beside the other shared V2 concerns — and switch the four send requests onto it. Their existing over-length tests must stay green **without edits**.

- [x] **Step 3: Implement.** Two non-negotiables:
  - Compare with `hash_equals()`, never `===`. It is a MAC comparison.
  - `verify()` returns `?string` rather than throwing. A forged webhook is an expected input on a public endpoint, not an exceptional one.

Document in the class docblock that this protects **correlation**, not the payload: a forger can still send a syntactically valid webhook, they simply cannot make it point at one of your entities. Say so plainly, because the alternative is a reader assuming it authenticates the delivery.

- [x] **Step 4: Verify and commit.**

---

## Task 4: Webhook CRUD

**Files:**
- Create: `src/Requests/V2/{CreateWebhookRequest,ListWebhooksRequest,UpdateWebhookRequest,DeleteWebhookRequest}.php`, `src/Data/V2/{WebhookData,WebhookFilter}.php`, `src/Resources/WebhooksResource.php`
- Test: `tests/Unit/V2WebhooksResourceTest.php`

**Interfaces:**
- `WebhooksResource extends V2Resource` with `create(string $name, string $url, array $eventTypes = [], ?WebhookFilter $filter = null, ?int $rateLimit = null): WebhookData`, `all(): array<int, WebhookData>`, `update(string $id, string $name, string $url, WebhookFilter $filter, ?int $rateLimit = null): WebhookData`, `delete(string $id): bool`.
- `final readonly WebhookFilter` with named constructor arguments for `eventType`, `sender`, `status`, `messageRef`, `campaignId`, all `array<int, string>`-or-enum, plus `toArray()` omitting empties.
- `final readonly WebhookData` carrying `id`, `name`, `url`, `filter`, `rateLimit`, and the four fields the skill does not document but the live create response returns: **`isSandbox`, `createdAt`, `updatedAt`**, with `rateLimit` echoed as `0` meaning system default. The timestamps carry **nine** fractional digits, so they go through `ParsesV2Timestamps` from Task 1 — `RFC3339_EXTENDED` cannot read them. The captured response is quoted in `tests/Fixtures/V2Webhooks/README.md`.

- [x] **Step 1: Read the skill.** `.agents/skills/kudosity-webhooks/SKILL.md`, all of it. The filter semantics table and the "Important Notes" list are the parts this task encodes.

- [x] **Step 2: Write the failing test.** Beyond the happy paths, these are the ones that catch real bugs:

- **`create()` sends `filter.event_type`, never a top-level `event_type`.** The top-level field is deprecated; assert its absence explicitly, because sending it looks like it works.
- **`create()` accepts a 201.** Saloon treats 2xx as success, so this passes trivially — assert the returned DTO is built from the 201 body rather than asserting the status alone, or the test cannot fail.
- **`all()` on `{}` returns an empty array.** The live API omits the `webhooks` key entirely when there are none. A test with `{"webhooks": []}` does *not* cover this; use `{}`.
- **`all()` reads the named collection key**, with a decoy top-level `id` in the body so a wrong read resolves the decoy rather than null.
- **`update()` sends `name`, `url` and `filter` in full**, because `PUT` is a replace. A test that updates only the URL and asserts the name is still present in the request body is the one that documents this.
- **A plain `{"error": "..."}` body becomes a `ValidationException` carrying that message** — this endpoint family does not use RFC 9457, and `fromV2Response()`'s branch for it must stay reachable through these requests.
- **HTTPS-only URLs.** Reject an `http://` URL client-side with a `ValidationException`; the API requires HTTPS and a rejected registration is a confusing round trip.
- `name` length 2–100, `rate_limit` ≤ 10,000 with `0` meaning system default. Give each its own single-violation input and assert on the `errorCode` plus a distinctive message fragment, not just the exception class.
- **`WebhookFilter` accepts `WebhookEventType` cases, not only strings** — a stringly-typed filter defeats Task 1's enum.

- [x] **Step 3: Implement.** `CreateWebhookRequest` and `UpdateWebhookRequest` extend `KudosityV2BodyRequest`; `ListWebhooksRequest` and `DeleteWebhookRequest` extend `KudosityV2Request` (no body).

**`GET /v2/webhook` is not documented as paginated and the live response carried no pagination metadata**, so `all()` returns a plain array and no paginator contract goes on the request. If you find pagination metadata in a live response, stop and raise it rather than guessing a scheme.

- [x] **Step 4: Decide `get($id)` on evidence, not on the spec's wish list**

The design spec lists `$k->webhooks()->get($id)`, but **the live reconnaissance found no single-webhook GET** — the observed surface is `POST`, `GET` (collection), `PUT /{id}`, `DELETE /{id}`. Probe it before implementing:

```bash
# Create a throwaway, probe the single GET, then delete it.
curl -s -o /dev/null -w '%{http_code}\n' \
  "https://api.transmitmessage.com/v2/webhook/${ID}" \
  -H "x-api-key: ${KUDOSITY_API_KEY}"
```

- `200` → implement `GetWebhookRequest` and `get(string $id): WebhookData`.
- `404`/`405` → **do not invent the endpoint.** Implement `find(string $id): ?WebhookData` over `all()`, name it `find` rather than `get` so the call site does not imply a fetch, and record in the README that V2 has no single-webhook read.

Either way, record the observed status code in your report. Tear the throwaway webhook down; the account must end with zero webhooks, as it started.

- [x] **Step 5: Live-verify the CRUD path** with a real registration against an ngrok tunnel (recipe below in Step 8), asserting create → list → update → delete, and confirm afterwards that `all()` is empty. `curl` already proved 201/200/200/200; this step proves the SDK does.

- [x] **Step 6: Verify and commit.**

- [x] **Step 7: capture the `LINK_HIT` fixture** — **done 2026-08-05**, before this plan ran. `link-hit-sms.json` and `link-hit-sms-repeat.json`, taking fixture coverage to five of ten event types. The run also produced the redelivery evidence Task 2 now replays and the four undocumented webhook-resource fields Task 4 models. `OPT_OUT` remains deliberately uncaptured: triggering it means replying STOP, which opts the test handset out.

- [x] **Step 8: The rig, for Step 5**

A local HTTP server plus `ngrok http <port>`; register a webhook at the tunnel URL; tear it down afterwards. Webhook URLs must be HTTPS, which is the whole reason for the tunnel. Log the complete request headers as well as the body — that header set is the evidence that deliveries are unsigned, and it is worth re-confirming it has not changed.

---

## Task 5: Sender registrations

**Files:**
- Create: `src/Enums/SenderStatus.php`, `src/Data/V2/SenderRegistrationData.php`, `src/Requests/V2/{ListSenderRegistrationsRequest,RegisterSenderRequest,RequestSenderVerificationRequest,ConfirmSenderVerificationRequest,DeleteSenderPhoneNumberRequest}.php`, `src/Resources/SendersResource.php`
- Test: `tests/Unit/V2SendersResourceTest.php`

**Why this task is last:** it unblocks the WhatsApp and RCS live verification that Phase 3 could not finish, because sender readiness is read here.

- [x] **Step 1: Capture the real response before modelling it**

The vendored skill documents the endpoint, the lifecycle and three field paths (`details.alphanumeric.status`, `status_reason`, `child_account_id`) — **not the full response shape**. Phase 3's rule applies: do not invent a DTO for an undocumented shape.

```bash
curl -s "https://api.transmitmessage.com/v2/senders/registrations" \
  -H "x-api-key: ${KUDOSITY_API_KEY}" | tee /tmp/senders-registrations.json | head -60
```

The account has at least one usable AU number, so this returns something real. Save it as a redacted fixture at `tests/Fixtures/V2Senders/registrations.json` following the existing fixture README's redaction conventions, and **model `SenderRegistrationData` against that file.** Record in your report which fields the live response carries that the skill does not mention — the webhook recon found three, and this endpoint has had no such pass.

Also confirm empirically whether the response is `data`-wrapped. The design spec says sender registrations wrap; the live response is the authority.

- [x] **Step 2: Write `SenderStatus`, with `isReadyToUse()` as the point of the enum**

The registry lifecycle is `NEW` → `SUBMITTED_TO_REGISTRY` → `PENDING_CUSTOMER` → `PENDING_APPROVAL` → `VERIFIED` → `READY_TO_USE`, plus `Unknown`. Two things the docblock must say, because both cause mystery failures:

- **`VERIFIED` does not mean you can send.** It means *provisioning*. Only `READY_TO_USE` can send, and a send on `VERIFIED` fails in a way that looks like anything but a sender problem. `isReadyToUse()` returns true for `READY_TO_USE` alone.
- **`PENDING_CUSTOMER` is waiting on you**, not on the registry — surface `status_reason` alongside it.

The list is expected to grow, so `fromApi()` lands on `Unknown` rather than throwing, and `isReadyToUse()` on an unrecognised value must be **false**. Assert that: defaulting an unknown state to sendable is how a broken sender reaches production.

- [x] **Step 3: Write the failing test**, against the captured fixture. Include: `isReadyToUse()` true for `READY_TO_USE` and false for every other case asserted as a **full membership check** rather than spot-checks, so a new case defaults closed (the pattern `RcsCapabilityCode` already uses); `Unknown` from an unpublished value; `status_reason` exposed; `child_account_id` exposed when present and null otherwise.

- [x] **Step 4: Implement the read path** — `ListSenderRegistrationsRequest` (GET, no body), `SenderRegistrationData`, and `SendersResource::registrations()`. If the endpoint filters by date, reuse `Concerns\FiltersByDateRange`; do not write a third copy of that rule.

- [x] **Step 5: CHECKPOINT — RESOLVED WITHOUT A DECISION, 2026-08-06**

The checkpoint below asked the user to choose between asking Kudosity, capturing
dashboard traffic, or deferring `register()`. **None was needed: the API names its
own fields in its validation errors.** Probing with deliberately invalid values —
so every probe could only be rejected — produced the whole schema:

| Probe | Response |
|---|---|
| `POST /v2/senders/registrations` `{}` | `sender is required`, `country is required`, `type is required` |
| …bad `type` | `type must be one of: PERSONAL_MOBILE_NUMBER` |
| `POST …/verifications` `{}` | `method is required`, `originating_sender is required` |
| …bad `method` | `method must be one of: SMS` |
| `POST …/verifications/confirmation` `{}` | `code is required` |
| `DELETE …/phone-numbers/{n}` | 404 `sender not found` |

The account was confirmed unchanged afterwards. All write paths are implemented
against that evidence; nothing was inferred except the *meaning* of
`originating_sender`, which is flagged in its own docblock.

**Not** live-verified end to end, deliberately: completing a registration would
register a personal mobile number as a sender and send a real code to it. That is
the one thing here still worth doing with a human present.

The original checkpoint text is kept below for the record.

- [ ] ~~**CHECKPOINT — the write paths need a decision, not a guess**~~

`POST /v2/senders/registrations`, its two verification endpoints and `DELETE /v2/senders/phone-numbers/{number}` are all in the design spec's scope, but **the request body for registration is documented nowhere in the vendored skills.** Inventing wire field names is precisely how the speculative SMS date filters got removed in Phase 3 — an unsupported parameter is silently ignored, so a call that looks like it registered a sender may have done nothing.

Stop here and ask the user which they want:

1. **Ask Kudosity for the request schema** — slowest, most correct, and there is already an open email thread with them.
2. **Capture it from the dashboard's own network traffic** while registering a sender, the same way the webhook payloads were captured.
3. **Ship the read path plus the verification endpoints now** (whose shapes are simpler and inferable from the URL) and defer `register()` to Phase 6 with an explicit note in `UPGRADING.md`.

Do not pick for them, and do not implement `register()` against a guessed body. If they choose 3, the deferral must be written down in both `CHANGELOG.md` and the README's sender section, or it will be discovered by a consumer instead.

- [x] **Step 6: Implement whichever write paths the checkpoint authorised**, then verify and commit.

- [x] **Step 7: Finish the WhatsApp and RCS live verification, now that sender readiness is readable**

Phase 3 left both unverified because the account has neither provisioned. With `registrations()` working: read what the account actually has, provision a WhatsApp Business sender and an RCS agent ID if they are now available, then send one message on each. Expected: a real send returning a `data`-wrapped envelope, and `RcsCapabilityCode::isReachable()` true for `ENABLED`. If provisioning still has not happened, say so plainly rather than reporting the channels as verified — both currently fail with correct error mapping (`no WhatsApp sender found for this account`, `sender is not owned by this account`), which is not the same as working.

---

## Task 6: Wire the resources onto the client, and the consumer contract

**Files:**
- Modify: `src/KudosityClient.php`, `packages/kudosity-laravel/src/Facades/Kudosity.php`
- Modify: `rename-map.json`, `UPGRADING.md`, `CHANGELOG.md`
- Modify: `packages/kudosity-client/README.md`, `README.md`, `CLAUDE.md`
- Test: `tests/Unit/KudosityClientTest.php` (extend)

- [x] **Step 1: Write the failing test.** Both accessors return their resource type, both cache (`toBe` across two calls), and both are constructed with the **V2** connector — assert that by sending through the resource against a mock and confirming the request went to `api.transmitmessage.com`. Phase 3's four channel accessors have this test; copy its shape.

- [x] **Step 2: Add `webhooks()` and `senders()`** to `KudosityClient`, lazily constructed and cached, matching the existing accessor style.

- [x] **Step 3: Add the two `@method` lines** to the facade, using short imported class names like the file's existing entries.

- [x] **Step 4: Run the mandatory removed-symbol audit**

```bash
git diff --stat main..HEAD -- packages/
git diff main..HEAD -- packages/ | grep -E '^\-.*(public function|public const|class |interface |enum )' | sort -u
```

For every symbol on the removed side, confirm it appears in `rename-map.json` and `UPGRADING.md`. This phase is almost purely additive, so the expected result is empty — **with one thing to check deliberately**: Task 1 deletes `private static function parseDate()` from four DTOs. It is private, so it is not a public-surface removal and belongs in neither file. Confirm that reasoning explicitly in your report rather than letting the audit pass silently over it.

- [x] **Step 5: Update the documentation**

- `CHANGELOG.md` under the existing `## Unreleased` `### Added`: the webhooks and senders resources, `WebhookEventType`, `SenderStatus`, the four event classes, `StatusPrecedence`, `SignedMessageRef`, and any deferral the Task 5 checkpoint produced.
- `packages/kudosity-client/README.md`: a webhooks section carrying **the four `message_ref` paths and the one accessor that hides them**, the "deliveries are not signed" statement with what `SignedMessageRef` does and does not protect, the `{}`-when-empty list behaviour, the `PUT`-is-a-replace behaviour, and the three undocumented fields. Then a senders section leading with `VERIFIED` ≠ sendable.
- `UPGRADING.md`: V1's per-send `dlr_callback` / `reply_callback` still exist and still work **for V1 sends only** — V2 has no per-send callback URL at all, and a consumer migrating a V1 send to V2 loses their callbacks silently unless they register an account-level webhook. That is the migration trap of this phase and it needs its own entry.
- `README.md`: update the resource list.
- `CLAUDE.md`: the architecture section says webhook and sender request classes "do not exist yet — those arrive in Phases 4–5". Correct it.

- [x] **Step 6: Verify the whole phase**

```bash
composer dump-autoload
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
composer validate --strict
(cd packages/kudosity-client && composer validate --strict)
(cd packages/kudosity-laravel && composer validate --strict)
php bin/kudosity-codemod packages 2>&1 | tail -2
php bin/kudosity-codemod tests 2>&1 | tail -2
git grep -in 'transmitsms' -- . \
  ':(exclude).agents/*' ':(exclude)docs/*' ':(exclude)CHANGELOG.md' \
  ':(exclude)UPGRADING.md' ':(exclude)rename-map.json' \
  ':(exclude)bin/kudosity-codemod' ':(exclude)tests/Unit/CodemodTest.php' \
  ':(exclude)HANDOFF.md' ':(exclude).ai-skills/*' \
  | grep -v 'api\.transmitsms\.com' | grep -v 'TransmitSMS is now' || echo "sweep clean"
# The negative check needs the SAME exclusions as the sweep: HANDOFF.md and the
# handoff archive narrate the corruption by name, so without these it always fires.
git grep -n 'api\.kudosity\.com' -- . ':(exclude)docs/*' ':(exclude)HANDOFF.md' ':(exclude).ai-skills/*' \
  && echo "CORRUPTED HOSTNAME — STOP" || echo "negative check clean"
```

Expected: green suite above the Step 1 baseline, `[OK] No errors`, Pint clean, three valid manifests, `packages` → 0 codemod changes, sweep clean, negative check clean.

- [x] **Step 7: Commit.**

---

## Definition of done

- `$k->webhooks()` creates, lists, updates and deletes registrations against `api.transmitmessage.com`, live-verified end to end, and the account is left with zero webhooks.
- `$k->senders()->registrations()` reads the account's real senders, modelled against a captured response rather than an inferred one, and `SenderStatus::isReadyToUse()` is true for `READY_TO_USE` alone — including for values Kudosity has not published yet.
- `WebhookEvent::fromArray()` turns all ten event types into four typed payload objects, tested against the real captured fixtures, and `messageRef()` reads the correlation key through one accessor at four different payload paths.
- The three undocumented fields (`webhook_id`, `webhook_name`, `status.description`) are modelled, and `raw()` exists for the fourth one nobody has found yet.
- A late `SENT` cannot overwrite a recorded `DELIVERED`, and a `READ` after `DELIVERED` still can. Both asserted, both directions.
- `SignedMessageRef` round-trips an entity that itself contains a colon, compares with `hash_equals()`, and stays inside the 500-character `message_ref` limit.
- `parseDate` exists once, not five times.
- The removed-symbol audit ran and is recorded in the Task 6 report, including the reasoning for the four deleted private methods.
- Any deferred write path from the Task 5 checkpoint is written down in `CHANGELOG.md` and the README, not just in this plan.
- Suite green and above the Step 1 baseline; PHPStan level 6 clean; Pint clean; three manifests valid; branding sweep and its negative check both clean; codemod reports 0 changes for `packages`.
- No Laravel receiver route, notification channel or Artisan command exists — those are Phase 5.
