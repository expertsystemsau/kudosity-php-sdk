# Kudosity Migration Phase 5: Laravel Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Laravel package speak V2. Four notification channels instead of one, a receiver for the account-level webhooks Phase 4 introduced, Artisan commands to manage those registrations, and a config shape that admits two APIs exist.

**Architecture:** Everything here sits on top of Phases 2–4 and adds no new HTTP surface. The two pieces of real design are the **SMS channel's routing decision** — V2 by default, V1 only when the message uses something V2 cannot do — and the **receiver's authenticity story**, which is an unguessable URL because V2 deliveries carry no signature.

**Tech Stack:** PHP 8.2+, Laravel 11/12, Saloon v4, Pest 4, Orchestra Testbench, PHPStan level 6 via Larastan, Laravel Pint.

## Global Constraints

- Runtime floor stays `"php": "^8.2"` in both packages. Laravel stays `illuminate/* ^11.0||^12.0`.
- `composer analyse` (PHPStan **level 6**) must report `[OK] No errors` at the end of every task.
- `vendor/bin/pint --test` must pass. Run `composer format` to fix.
- **Measure the baseline at Step 1 and record the real number.** It was `724 tests, 1386 assertions` at `4b461b1`. Re-measure; do not trust that figure if commits have landed.
- **Do not predict exact test counts.** Say "at least N".
- The two hostnames are `https://api.transmitsms.com` (V1) and `https://api.transmitmessage.com` (V2). Never write a sweep pattern like `\btransmitsms\b` — the dots are word boundaries. **Any sweep needs a negative check for the corrupted `api.kudosity.com` form, with the same exclusions as the positive sweep** (`docs/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod`, `tests/Unit/CodemodTest.php`, `HANDOFF.md`, `.ai-skills/*`) — otherwise it fires on prose that narrates the bug.
- Never modify `.agents/skills/`. Append to `CHANGELOG.md` under `## Unreleased`.
- **Read `tests/Fixtures/V2Webhooks/README.md` before designing the receiver.** It records behaviours the upstream docs contradict, and the receiver is where every one of them lands.
- **The removed-symbol audit is mandatory in the final task.** This phase is the one most likely to need it: unlike Phases 3 and 4 it *changes existing public API* rather than only adding. `base_url` becoming `base_url.v1`/`base_url.v2` is a consumer-visible config break and belongs in `rename-map.json` and `UPGRADING.md`.
- Work on a branch `feat/kudosity-laravel-v2` cut from `main`, in the **primary checkout** — symlinking `vendor/` into a worktree makes `vendor/bin/pest` run the tracked tree's code.
- `Split Monorepo` failing on every push is expected until the two split-target repos exist. Do not read it as yours. `.github/workflows/split.yml` **must keep `actions/checkout@v4`**.
- `/docs` is gitignored; committing anything under it needs `git add -f`.

## Reference

- Design spec: `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`, **sections 4 (Laravel receiving/managing) and 5**.
- Phase 4 plan, for conventions and the fixture-first discipline: `docs/superpowers/plans/2026-08-06-kudosity-phase-4-webhooks-senders.md`.

### What Phase 4 built that this phase consumes

| Piece | Use |
|---|---|
| `Webhooks\WebhookEvent::fromArray()` | The receiver's whole parse step. Returns `StatusEvent`, `InboundEvent`, `LinkHitEvent`, `OptOutEvent` or `UnknownEvent`. |
| `Webhooks\WebhookEvent::messageRef()` | One accessor for the correlation key, which sits at a different path per event type. |
| `Webhooks\StatusPrecedence` | The idempotency guard. Status events are unordered **and at-least-once** — observed live. |
| `Webhooks\SignedMessageRef` | The only authenticity signal available. Sign on the way out, verify on the way in. |
| `Resources\WebhooksResource` | What the Artisan commands drive. |
| `Resources\SendersResource` | `kudosity:sender:*`, if Task 5 gets that far. |
| `Enums\WebhookEventType` | The ten event types the install command offers. |

### What already exists in the Laravel package, and must keep working

| File | Status after this phase |
|---|---|
| `Notifications/KudosityChannel.php` | Rewritten to route V1/V2. Currently V1-only via `SendSmsRequest`. |
| `Notifications/KudosityMessage.php` | Extended with the routing accessors; existing setters unchanged. |
| `Http/Controllers/WebhookController.php` | **Keeps its three V1 GET routes** (`dlr`, `reply`, `linkHits`) and gains one V2 `POST`. V1 sends still deliver callbacks that way. |
| `Contracts/Handles*Callback.php`, `Events/*Received.php` | Unchanged, still V1. New V2 events sit beside them. |
| `Callbacks/CallbackUrlBuilder` / `Parser` (client pkg) | **Reused** for the V2 receiver's unguessable URL. Do not duplicate. |
| `KudosityServiceProvider.php` | Gains a `KudosityV2Connector` singleton and three more channel registrations. |

---

## Task 1: Config, provider, and the V2 connector singleton

**Files:**
- Modify: `packages/kudosity-laravel/config/kudosity.php`, `packages/kudosity-laravel/src/KudosityServiceProvider.php`
- Test: `tests/Unit/ServiceProviderTest.php` (extend, or create)

- [ ] **Step 1: Record the baseline**

```bash
git checkout -b feat/kudosity-laravel-v2
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 2: Write the failing test.** Four things, each of which is currently broken or absent:

- `KudosityV2Connector::class` resolves from the container. **It cannot autowire** — `$apiKey` has no default — so without an explicit singleton this throws, and every consumer injecting it fails.
- The resolved V2 connector honours `kudosity.timeout`. Today the provider builds the client via `fromConnector()`, so the *derived* V2 connector gets a hardcoded 30s regardless of config. Assert a non-default timeout reaches it.
- `config('kudosity.base_url.v1')` and `.v2` both resolve, and each reaches its connector.
- A config still carrying the old flat `base_url` string **fails loudly rather than silently sending to the wrong host.** This is the consumer-visible break; decide between throwing on boot and logging a deprecation, and record the choice with reasoning. Silently ignoring it is not an option — a stale `base_url` pointing at `api.transmitsms.com` would send V2 traffic to V1.

- [ ] **Step 3: Split the config key** as the spec specifies, adding `country_code`, `mms.sender`, `whatsapp.sender`, `rcs.agent_id`, and the webhook receiver's V2 path and `rate_limit`. Keep every existing key.

- [ ] **Step 4: Register the singletons.** `KudosityV2Connector` explicitly, and build `KudosityClient` from **both** connectors via `fromConnectors()` so the V2 one is the configured instance rather than a derived default.

- [ ] **Step 5: Verify and commit.**

---

## Task 2: `KudosityMessage` routing, and the shared response contract

**Files:**
- Create: `packages/kudosity-client/src/Contracts/SentMessage.php`
- Modify: `packages/kudosity-client/src/Data/SmsData.php` and `Data/V2/SmsMessageData.php` (implement the contract)
- Modify: `packages/kudosity-laravel/src/Notifications/KudosityMessage.php`
- Test: `tests/Unit/KudosityMessageRoutingTest.php`

**Why a contract:** the two send responses have genuinely different shapes, and the channel's return type must not change depending on a routing decision the caller did not make.

```php
interface SentMessage
{
    public function id(): string;              // V2 UUID, or the V1 message_id cast to string
    public function recipientCount(): int;      // 1 for V2; the V1 `recipients` count otherwise
    public function status(): ?MessageStatus;   // V2 only — the V1 send response carries no status
}
```

`status()` returning null for V1 is the honest answer, not a gap: the V1 send response really does not carry one. **Do not invent `MessageStatus::Pending` for it** — a caller cannot distinguish an invented status from a real one, and the whole point of Phase 3's enum work was to stop that.

- [ ] **Step 1: Write the failing test** for the routing decision. `apiVersion()` must report V1 for **each** V1-only trigger, one test per trigger with a **single** trigger set, so a passing test names which rule fired:

| Trigger | Why V2 cannot do it |
|---|---|
| `toList()` | V2 has no list send |
| `sendAt()` | `POST /v2/sms` cannot schedule |
| `validity()` | V1-only option |
| `repliesToEmail()` | V1-only option |
| `dlrCallback()` / `replyCallback()` / `linkHitsCallback()` | **V2 has no per-send callback URL at all** |
| a multi-recipient `to` | `POST /v2/sms` takes exactly one recipient |

Then: a plain message routes V2; `forceV1()` and `forceV2()` override; and **`forceV2()` with a V1-only option set throws** rather than silently dropping it. That last one is the test that matters — silently dropping a `sendAt()` turns a scheduled send into an immediate one, which is a wrong send rather than a failed one.

- [ ] **Step 2: Implement**, then verify and commit.

---

## Task 3: The four channels

**Files:**
- Modify: `packages/kudosity-laravel/src/Notifications/KudosityChannel.php`
- Create: `KudosityMmsMessage`, `KudosityWhatsAppMessage`, `KudosityRcsMessage` and their channels
- Modify: `KudosityServiceProvider` (register `kudosity-mms`, `kudosity-whatsapp`, `kudosity-rcs`)
- Test: `tests/Unit/NotificationChannelsTest.php`

- [ ] **Step 1: Write the failing tests.** Per channel: the notification's builder method is called, the right resource is used, the configured default sender applies when the message omits one, and `routeNotificationFor()` supplies the recipient. Then the cases that are easy to get wrong:

- The SMS channel returns a `SentMessage` **whichever way it routed**, and the existing V1-path tests still pass unedited.
- A notification with no resolvable recipient and no list returns null rather than throwing — current behaviour, keep it.
- **RCS uses the configured `rcs.agent_id`, and a phone-number-shaped value is rejected** before the request leaves the process (Phase 3's guard — assert it still fires through the channel).
- **WhatsApp free-form text only delivers inside the 24-hour service window.** The channel cannot detect that; its docblock must say so and point at `template()`.

- [ ] **Step 2: Implement.** Keep `KudosityChannel::send()`'s existing null-recipient behaviour and its `ValidationException` handling.

- [ ] **Step 3: Verify and commit.**

---

## Task 4: The V2 webhook receiver

**Files:**
- Modify: `packages/kudosity-laravel/src/Http/Controllers/WebhookController.php`, the route registration
- Create: `Events/KudosityStatusReceived`, `KudosityInboundReceived`, `KudosityLinkHitReceived`, `KudosityOptOutReceived`
- Test: `tests/Unit/V2WebhookReceiverTest.php`

**This is the task where Phase 4's findings all land. Read `tests/Fixtures/V2Webhooks/README.md` first, and test against those fixtures rather than hand-written payloads.**

- [ ] **Step 1: Write the failing tests.** One route, `POST {prefix}/events`, handling all ten event types:

- Each of the six captured fixtures dispatches the right typed Laravel event.
- **An unrecognised `event_type` returns 200, not 500.** `UnknownEvent` exists precisely so a receiver degrades. A 500 reads as a dead endpoint from Kudosity's side and triggers a retry into the same 500.
- **A malformed or truncated body returns 200 and logs**, rather than throwing. This is a public endpoint.
- **Replaying the same `SMS_STATUS` fixture twice dispatches the state change once.** Deliveries are at-least-once — the fixture README records a `SENT` redelivered 57 seconds *after* `DELIVERED`. Use `StatusPrecedence`.
- **Replaying `sms-status-sent` after `sms-status-delivered` does not regress the recorded status.** Assert on recorded state, not on the dispatched event.
- The three V1 GET routes still work, tested unedited.
- The receiver is reachable only at its unguessable path: a request to the prefix without the signature is rejected. **Reuse `CallbackUrlParser`; do not write a second verifier.**

- [ ] **Step 2: Decide and document how a consumer gets the entity back.** The receiver should expose the verified entity where a listener can use it — `SignedMessageRef::verify($event->messageRef(), $secret)` — and the docblock must state plainly that a null result means the delivery is **not** correlated and should not be trusted to refer to anything. Note that an unsolicited inbound has no `last_message` and therefore no ref at all, which is a legitimate state rather than an error.

- [ ] **Step 3: Implement**, then verify and commit.

---

## Task 5: Artisan commands

**Files:**
- Create: `Console/Commands/WebhookListCommand`, `WebhookInstallCommand`, `WebhookDeleteCommand`
- Modify: `KudosityServiceProvider` (register commands when running in console)
- Test: `tests/Unit/WebhookCommandsTest.php`

- [ ] **Step 1: Write the failing tests.**

- `kudosity:webhook:list` renders the account's registrations, and says so plainly when there are none — the API answers `{}`, and "no webhooks registered" beats an empty table.
- `kudosity:webhook:install` registers a webhook pointing at **this app's own receiver route**, with chosen event types. It must build the URL through `CallbackUrlBuilder` so the signature is present — a receiver installed without it is unreachable by design.
- **`install` refuses a non-HTTPS app URL** with an explanation, rather than letting the API accept a plaintext registration. Phase 4's guard already throws; assert the command surfaces it usefully instead of dumping a stack trace.
- `kudosity:webhook:delete` takes an id, confirms destructively, and supports `--force`.
- Each command exits non-zero on failure.

- [ ] **Step 2: Implement**, then verify and commit.

---

## Task 6: Docs, audit, and the consumer contract

**Files:**
- Modify: `rename-map.json`, `UPGRADING.md`, `CHANGELOG.md`, `packages/kudosity-laravel/README.md`, root `README.md`, `CLAUDE.md`

- [ ] **Step 1: Run the removed-symbol audit**

```bash
git diff --stat main..HEAD -- packages/
git diff main..HEAD -- packages/ | grep -E '^\-.*(public function|public const|class |interface |enum |trait )' | sort -u
```

**Expect this one to be non-empty**, unlike Phases 3 and 4 — this phase changes existing API. For each entry, decide whether it is a genuine removal or a relocation that preserves access, and **prove it** (Phase 4 proved `MAX_MESSAGE_REF_LENGTH` was still reachable with a runtime `defined()` check rather than asserting it). Genuine removals go in `rename-map.json` **and** `UPGRADING.md`. Remember a removed *class* goes in `classes` with a real target; only methods belong in `removed`.

- [ ] **Step 2: The config break needs a `rename-map.json` entry.** `base_url` → `base_url.v1` / `base_url.v2` is consumer-visible and mechanical enough for the codemod to flag. `KUDOSITY_BASE_URL` → `KUDOSITY_BASE_URL_V1` belongs in the `env` group.

- [ ] **Step 3: Documentation.**

- `UPGRADING.md`: the config split, and the notification-channel changes. The V1-callbacks-don't-fire-for-V2 section already exists from Phase 4 — **link the receiver route to it** rather than restating it.
- `packages/kudosity-laravel/README.md`: the four channels with one example each, the SMS routing table, the receiver route with its unguessable-URL rationale, and the three commands.
- `CHANGELOG.md` under `## Unreleased`.
- `CLAUDE.md`: the Laravel section currently describes one channel and V1 callbacks.

- [ ] **Step 4: Verify the whole phase**

```bash
composer dump-autoload
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
composer validate --strict
(cd packages/kudosity-client && composer validate --strict)
(cd packages/kudosity-laravel && composer validate --strict)
php bin/kudosity-codemod packages 2>&1 | tail -2
git grep -in 'transmitsms' -- . \
  ':(exclude).agents/*' ':(exclude)docs/*' ':(exclude)CHANGELOG.md' \
  ':(exclude)UPGRADING.md' ':(exclude)rename-map.json' \
  ':(exclude)bin/kudosity-codemod' ':(exclude)tests/Unit/CodemodTest.php' \
  ':(exclude)HANDOFF.md' ':(exclude).ai-skills/*' \
  | grep -v 'api\.transmitsms\.com' | grep -v 'TransmitSMS is now' || echo "sweep clean"
git grep -n 'api\.kudosity\.com' -- . ':(exclude)docs/*' ':(exclude)HANDOFF.md' ':(exclude).ai-skills/*' \
  && echo "CORRUPTED HOSTNAME — STOP" || echo "negative check clean"
```

- [ ] **Step 5: Live-verify the receiver end to end.** The rig is the same one Phase 4 used: a local server plus `ngrok http <port>`, register a webhook at the tunnel URL through `kudosity:webhook:install`, send one tracked SMS, confirm the typed events fire, then tear down and confirm the account is back to zero webhooks. **This is the step that proves the phase**, because everything else is mocked.

Credentials are in the gitignored `.env`. **Skip `OPT_OUT`** — triggering it opts the test handset out of receiving messages.

- [ ] **Step 6: Commit.**

---

## Definition of done

- Four channels send through their V2 resources, and the SMS channel routes to V1 only for the six documented V1-only triggers — with `forceV2()` throwing rather than dropping one.
- `SentMessage` keeps the SMS channel's return type stable across the routing decision, and `status()` is null for V1 rather than an invented value.
- `POST {prefix}/events` handles all ten event types, dispatches four typed Laravel events, returns 200 for an unrecognised type and for a malformed body, and is authenticated by unguessable URL through the existing `CallbackUrlParser`.
- **Replaying a status fixture does not double-count, and a late `SENT` does not regress a recorded `DELIVERED`** — asserted against the captured fixtures, not hand-written payloads.
- The three V1 GET callback routes still work, with their tests unedited.
- `KudosityV2Connector` resolves from the container and honours `kudosity.timeout`; a stale flat `base_url` fails loudly.
- The three Artisan commands work, and `install` builds a signed receiver URL rather than a bare one.
- The removed-symbol audit ran, every entry was resolved with proof, and the config break is in both `rename-map.json` and `UPGRADING.md`.
- The receiver is live-verified end to end and the account is left with zero webhooks.
- Suite green and above the Step 1 baseline; PHPStan level 6 clean; Pint clean; three manifests valid; sweep and negative check clean.
- No Phase 6 work: no standalone PHPUnit suite, no release, no tag.
