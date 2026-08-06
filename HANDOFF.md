---
handoff_version: "1"
source: xsys-handoff
mode: full
generated_at: "2026-08-06T15:55+10:00"
title: "Kudosity 2.0 — Phase 5 complete and live-verified; ready to merge"
status: in-progress
branch: "feat/kudosity-laravel-v2"
head_sha: "12f26892"
dirty_files: 0
diff_digest: "clean"
ticket_key: "none"
repo: "transmitsms-php-sdk"
submodules: []
next_step: "Merge feat/kudosity-laravel-v2 to main and push, then start Phase 6"
---

# Handoff: Kudosity 2.0 — Phase 5 part-done on `feat/kudosity-laravel-v2`

## Goal

Upgrade this PHP SDK from the TransmitSMS V1 API to Kudosity's V2 API, shipping as a 2.0. Kudosity runs **two APIs under one account** and V2 cannot replace V1, so the SDK is dual-API: V2 is the default surface, V1 is retained for what exists nowhere else.

| | Base URL | Auth | Covers |
|---|---|---|---|
| **V1** | `api.transmitsms.com` | HTTP Basic, key **and** secret | contact lists, bulk and scheduled sends, reporting, balance |
| **V2** | `api.transmitmessage.com` | `x-api-key`, key only | single-recipient SMS, MMS, WhatsApp, RCS, webhooks, senders |

Both hostnames are real; neither is a Kudosity domain — see Failed Approaches. Six phases, specced in `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`.

**Phase 5 is done, including the live receiver verification.** The branch is ready to merge. Phases 1–4 are merged and pushed on `main` (`059650b`).

## Completed

- [x] **Phases 1–4 merged to `main` and pushed.** Rebrand; V2 core (connector, RFC 9457 errors, both paginators); the four V2 channels (`sms()`, `mms()`, `whatsapp()`, `rcs()`); webhooks + senders.
- [x] **Phase 5 Task 1 — config and container.** `base_url` split into `v1`/`v2`; `KudosityV2Connector` registered as a singleton (**it cannot autowire** — `$apiKey` has no default); client built via `fromConnectors()` so `kudosity.timeout` reaches V2 and the client's V2 connector *is* the container's. A config still carrying the flat `base_url` string **throws on boot**, echoing the value and naming both replacement keys. New keys: `country_code`, `mms.sender`, `whatsapp.sender`, `rcs.agent_id`, `webhooks.events.{enabled,path}`.
- [x] **Phase 5 Task 2 — routing and one return type.** `Contracts\SentMessage` (`id(): string`, `recipientCount(): int`, `status(): ?MessageStatus`), implemented by V1 `SmsData` and V2 `SmsMessageData`. `KudosityMessage::apiVersion()` / `v1Reasons()` / `forceV1()` / `forceV2()`. **`forceV2()` throws** when the message uses a V1-only option rather than dropping it.
- [x] **Phase 5 Task 3 — four channels.** `kudosity` (routing), plus `kudosity-mms`, `kudosity-whatsapp`, `kudosity-rcs` with their message builders, registered on the notification manager, each with its own sender default.
- [x] **Phase 5 Task 4 — the V2 receiver.** `POST {prefix}/events` handles all ten event types and dispatches `KudosityStatusReceived` / `KudosityInboundReceived` / `KudosityLinkHitReceived` / `KudosityOptOutReceived`, carrying the Phase 4 event objects. The three V1 GET routes are untouched and still tested.
- [x] **Phase 5 Task 5 — the three Artisan commands.** `kudosity:webhook:list`, `:install`, `:delete`. `install` builds its receiver URL through `CallbackUrlBuilder` — **not a convenience**: the receiver 403s an unsigned request, so a hand-assembled URL registers a webhook that can never deliver, and fails silently because Kudosity cannot report that your endpoint is rejecting it. `CallbackType` gained an `EVENTS` case so the same signing machinery is reused.
- [x] **Phase 5 Task 6 — documentation.** `packages/kudosity-laravel/README.md` (four channels, the SMS routing table, the receiver, the commands), root `README.md`, and `CLAUDE.md`. `UPGRADING.md` and `CHANGELOG.md` were done earlier.
- [x] **Phase 5 Task 6, partly — audit and consumer contract.** Removed-symbol audit run and resolved (see Failed Approaches); `rename-map.json` entries for the config and env break, ordering-pinned and idempotence-tested; `UPGRADING.md` and `CHANGELOG.md` written.
- [x] **The two split-target repos now exist** — `expertsystemsau/kudosity-php-client` and `kudosity-laravel-client`, created public and empty on 2026-08-06. `Split Monorepo` should go green on the next push to `main`; **that has not been observed yet** — confirm it.
- [x] **The `http://` question is settled.** HTTPS for any real environment; plaintext permitted only via an explicit `allowInsecureUrl` opt-in, which `kudosity:webhook:install` sets only when `APP_ENV=local`.
- [x] **CI green on this branch at `e11fb86`** — `run-tests`, PHPStan and Pint all pass. `Split Monorepo` does not fire on branches.
- [x] **Phase 5 Task 6 — live receiver verification, done 2026-08-06.** Seven real deliveries, seven typed events, no drops and no double-dispatch. See Verification.
- [x] **Inbound MMS media fix (`12f2689`)**, which the live run found. `InboundEvent` read `mo.content_urls`; a real `MMS_INBOUND` puts the bytes inline under `mo.media[]`, so the picture was silently discarded. `Webhooks\InboundMedia` is the fix, with the real payload captured as `mms-inbound-with-media.json`.

## Not Yet Done
- [ ] **Phase 6 — tests, CI, docs, release.** Standalone PHPUnit 11 suite for the client package on PHP 8.2/8.3/8.4, then release.
- [ ] **`register()` and the sender SMS verification flow are still not live-verified.** Deliberate: completing one registers a personal mobile as a sender and sends a real code to it. Wants a human present.
- [ ] **WhatsApp and RCS remain unverified end to end**, and Phase 4 established that the senders endpoint does **not** unblock this — it registers personal mobile numbers only. Needs Kudosity to provision a WhatsApp Business sender and an RCS agent.
- [ ] **Rotate the API key and secret** — they were pasted into a chat transcript.
- [ ] **`.env`'s `KUDOSITY_FROM` is the retired virtual number.** Kudosity replaced it (see Warnings), and `POST /v2/sms` answers `Sender not found` for the old value. The live run used the replacement explicitly. Update `.env` before the next live run, or every V2 send fails.
- [ ] **Open with Kudosity: do they publish stable egress IP ranges for webhook deliveries?** Referred to their product team, unanswered. Until then `35.197.178.201` is one observation, not an allowlist.

## Failed Approaches (Don't Repeat These)

**`CallbackUrlParser` does not authenticate a request with no handler.** It has an "events-only mode": with neither `h` nor `c` in the query it returns without verifying anything. Correct for the V1 GET routes; **wrong for the V2 receiver**, whose entire authenticity story is its unguessable URL — and whose default path (`webhooks/kudosity/events`) is documented in the README. Delegating left the route forgeable by anyone who read the docs. The receiver now requires both `s` and `h` before consulting the parser. Found because two tests written to expect 403 returned 200. Do not "simplify" it back, and do not loosen the V1 parser to match.

**A specific `rename-map.json` entry must be ordered before the generic prefix rule that would swallow it.** The `env` group carries `TRANSMITSMS_` → `KUDOSITY_`, which consumed `TRANSMITSMS_BASE_URL` before the specific `→ KUDOSITY_BASE_URL_V1` rule could see it, producing a key that no longer exists — a consumer's V1 base URL would have been silently ignored. `CodemodTest` now pins the ordering. **Verify every new rewrite is idempotent**: running the codemod twice must not yield `base_url.v1.v1`.

**A `rename-map.json` string entry can rewrite the SDK's own source.** A bare `'kudosity.base_url'` rewrite matched `KudosityServiceProvider`'s flat-form *detection* — the codemod would have disabled the very guard that warns consumers about the break. **`php bin/kudosity-codemod packages` reporting anything but 0 is the signal.** Narrow entries to an unambiguous form such as `config('kudosity.base_url')`.

**The callback handler form is as V1-only as the raw callback URL.** `onDlr()` / `onReply()` / `onLinkHit()` become per-send callbacks, so a message using them cannot go over V2. Routing that checked only `dlrCallback()` sent perfectly and never called the handler — a silence, not an error.

**A Laravel `expectsOutputToContain()` assertion can pass on an incidental match.** Asserting the string `all` against `kudosity:webhook:list` **passed** — not because the Events column rendered it, but because `all` appeared elsewhere in the output. Laravel truncates a table to the terminal width, so the column under test was absent entirely. A test that looks like coverage and is not. Assert on distinctive strings, and prefer covering computed values where they are computed.

**A brand sweep using `\btransmitsms\b` silently corrupted the V1 hostname.** In `api.transmitsms.com` the dots are word boundaries, so `\b` matched *inside* the hostname and rewrote `BASE_URL` to a nonexistent `api.kudosity.com`. The grep policing the sweep could not see it, because the corruption destroyed the string being searched for. **Any sweep needs a negative check for the corrupted form** — with the *same* exclusions as the positive sweep (`docs/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod`, `tests/Unit/CodemodTest.php`, `HANDOFF.md`, `.ai-skills/*`), or it fires on prose that narrates the bug.

**A `\->sms\(\)` sweep cannot see a static facade call.** Use `'\->sms\(\)|::sms\(\)|\bSmsResource\b'`, and never accept the word "clean" — ask for the literal output.

**Removed public symbols went missing from `rename-map.json` in Phases 1 and 2.** Keep the audit step in every phase plan. Phase 5's audit returned three lines and **all three were declaration changes, not removals** — two `implements SentMessage` additions and one return-type widening. Verify before acting: Phase 4's single hit (`MAX_MESSAGE_REF_LENGTH`) was a trait relocation still reachable on all four classes, proved with a runtime `defined()` check.

**Symlinking `vendor/` into a git worktree does not work here.** Composer's `autoload_psr4.php` resolves `$baseDir` back through the symlink, so `vendor/bin/pest` runs the tracked tree's code and a deliberate regression appears to pass. Work on a branch in the primary checkout.

**Three tests that could not fail shipped in Phase 3.** The discipline that catches them: revert the behaviour, watch the test fail, restore, put the transcript in the report. Every Phase 4 and 5 task was mutation-tested this way, and it found real gaps each time.

**Two mutation-testing failure modes that look exactly like a surviving mutation.** (1) A `perl -0pi -e` pattern that silently no-ops — **always verify the file actually changed**. (2) An "equivalent refactor": `array_filter()` without a callback still drops `[]` because an empty array is falsy, so behaviour was unchanged. A mutation that does not change behaviour proves nothing.

**Some invariants cannot be mutation-tested.** `hash_equals` → `===` in `SignedMessageRef` passes every test, because the difference is timing not result. Recorded as an invariant in that class's docblock instead. Do not add a test that pretends to cover it.

**A DTO field written from the outbound docs described nothing that arrives.** `InboundEvent::$contentUrls` read `mo.content_urls` — the shape an outbound MMS *request* takes. A real `MMS_INBOUND` carries the bytes inline under `mo.media[]`, so the picture parsed cleanly, dispatched its typed event, and vanished. **A silence, not an error**, and the same defect class as the V1-callbacks trap. Mocked tests could never have caught it, because the mock was built from the same wrong assumption as the code. Fixed in `12f2689`; the general lesson is that a field mirrored from the request side needs a captured response before it is believed.

**Do not invent a request body or DTO for an undocumented shape — ask the API instead.** Posting `{}` returns RFC 9457 `issues[]` naming every required field; posting an invalid enum returns the permitted set. That is how the whole senders schema was established without guessing a wire name.

**Do not predict exact test counts in a plan.** Phase 3's plan double-counted a dataset's own `it()` block and every later prediction inherited the error.

## Key Decisions

| Decision | Rationale |
|---|---|
| Dual-API, V2 primary | V2 has no equivalent for lists, bulk/multi-recipient sends, scheduling, reporting or balance. |
| API hostnames keep their real values | Kudosity renamed neither. Both must survive every sweep. |
| Every V2 enum is open, resolving unknowns to `Unknown` | A client reading its own history must not break because Kudosity added a value. |
| `UnknownEvent` is returned, never thrown | A receiver does not choose what it is sent; a 500 reads as a dead endpoint and earns a retry into the same 500. |
| `StatusPrecedence` is a rank, not a terminal check | `isTerminal()` is true for both `DELIVERED` and `READ`, and an RCS read receipt legitimately follows delivery. |
| `SignedMessageRef` protects correlation, not the payload | Stated in the docblock, or a reader assumes it authenticates the delivery. Parse from the **last** colon; real refs are composite. |
| The SDK rejects an `http://` webhook URL by default, though the API accepts one | Deliveries carry message text and are unsigned, so a plaintext endpoint is readable and forgeable. **Settled 2026-08-06:** permitted via an explicit `allowInsecureUrl` opt-in for local development; `install` sets it only when `APP_ENV=local`. An opt-in parameter rather than hostname sniffing, because the client package cannot tell a laptop from production. |
| **Phase 5: `base_url` becoming an array fails loudly on a stale flat value** | A published config is not re-published on upgrade, and a stale value points at the V1 host — silently ignoring it would send every V2 request to the wrong API. |
| **Phase 5: `SentMessage::status()` is null for V1, never invented** | The V1 send response carries no status; a fabricated `Pending` is indistinguishable from a real one. V2's concrete class narrows the return type to non-nullable, which is legal by covariance. |
| **Phase 5: `recipientCount()` is 1 for V2, not `sms_count`** | Two *segments* to one person is one recipient; conflating them over-reports reach. |
| **Phase 5: the SMS channel routes V2 by default, V1 only for what V2 cannot express** | And `forceV2()` throws rather than dropping an option — silently ignoring a `sendAt()` turns a scheduled send into an immediate one, a wrong send rather than a failed one. |
| **Phase 5: WhatsApp omits its sender rather than defaulting to `kudosity.from`** | It needs a registered WhatsApp Business number; an SMS sender ID would be rejected. Null lets the account default apply, matching the API. |
| **Phase 5: the receiver returns 200 for unknown event types and unparseable bodies** | Neither is retryable, and a non-2xx earns a retry into the same failure. Only a bad signature gets 403. |
| PHP floor stays `^8.2` | User's call, despite the toolchain needing 8.3+. |

## Current State

**Working.** Branch `feat/kudosity-laravel-v2` at `12f2689`, clean tree, 8 commits ahead of `main`. Everything above under Completed. **Not yet pushed at the time of writing** — push before merging.

**Broken.** Nothing known. `Split Monorepo` succeeded on `main` at `059650b` and both package repos are populated.

**Uncommitted changes.** None.

## Verification

At `12f2689` on this branch:

| Command | Result |
|---|---|
| `vendor/bin/pest --compact` | 844 passed (1660 assertions) |
| `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` (level 6) |
| `vendor/bin/pint --test` | passed |
| `composer validate --strict` (root, client, laravel) | all three valid |
| `php bin/kudosity-codemod packages` | 0 files would change |
| `php bin/kudosity-codemod tests` | 1 file — `tests/Unit/CodemodTest.php`, old-brand fixtures by design; pre-existing, `main` reports the same |
| branding sweep + hostname negative check | both clean; both real hostnames intact |
| CI at `e11fb86` | `run-tests`, `PHPStan`, `Fix PHP code style issues` all SUCCESS. **Not yet run for the commits after it** — check before trusting. |
| Live receiver verification | **passed, 2026-08-06** — see below |
| `composer test-coverage` | not run this session |

### The live receiver verification, 2026-08-06

Rig: a Testbench-booted Laravel app with **only** `KudosityServiceProvider` registered, served by `php -S` behind `ngrok http 8099`, so the route, the container bindings and the Artisan commands under test were the shipped ones. Webhook registered by `kudosity:webhook:install` (subscribed to all ten types) and every delivery arrived at the URL that command built.

| Delivery | Typed event | What it proved |
|---|---|---|
| `SMS_STATUS` SENT | `KudosityStatusReceived` | `message_ref` correlated |
| `SMS_STATUS` DELIVERED | `KudosityStatusReceived` | 2s later, upper-case as documented |
| `LINK_HIT` `hits:1` | `KudosityLinkHitReceived` | 2s after DELIVERED — the "not a human tap" warning holds |
| `LINK_HIT` `hits:2` | `KudosityLinkHitReceived` | `hits` cumulative, `isFirstHit()` false |
| `SMS_INBOUND` | `KudosityInboundReceived` | `messageRef()` resolved through `last_message` |
| `MMS_STATUS` DELIVERED | `KudosityStatusReceived` | carrier `description` present |
| `MMS_INBOUND` | `KudosityInboundReceived` | **first ever captured** — and it found the media bug |

Seven raw deliveries, seven typed events. An unsigned POST got 403 both locally and through the tunnel. `OPT_OUT` skipped by design. Torn down: webhook deleted, account confirmed back to zero through `WebhooksResource::all()` rather than the command that installed it, tunnel and server stopped.

Two things worth knowing before repeating this. `.env`'s `KUDOSITY_FROM` is the **retired** number and fails with `Sender not found` — pass the replacement explicitly. And an MMS `content_urls` entry must serve a real image content type: Wikimedia answers Kudosity's fetcher with `text/plain` and the API rejects the URL before looking at the bytes, so the rig served the image off its own tunnel.

Mutation testing ran on Phase 5 tasks 1-4 — 4 on the provider, 7 on the routing/contract, 7 on the channels, 5 on the receiver — plus 6 on `InboundMedia`. **Task 5 (the commands) was still not mutation-tested** — worth doing. All caught except three that were informative rather than gaps (see Failed Approaches), and two on `InboundMedia` that survived and now have tests.

**A `perl -0pi -e` mutation whose pattern contains a PHP `$variable` silently no-ops.** `\Q…\E` stops perl treating it as a regex metacharacter but does *not* stop interpolation, so `$this` expands to nothing and the pattern never matches. Three mutations reported clean this way before the harness's own "did the file actually change?" check caught it. Patch with `php -r` and `str_replace` instead, and keep asserting the file changed.

## Files to Know

| File | Why It Matters |
|---|---|
| `docs/superpowers/plans/2026-08-06-kudosity-phase-5-laravel.md` | The plan, now fully executed. |
| `packages/kudosity-client/src/Webhooks/InboundMedia.php` | Where an inbound MMS attachment actually lives, and why the media type is sniffed rather than read. |
| `packages/kudosity-laravel/src/Http/Controllers/WebhookController.php` | `events()` is the V2 receiver, including the stricter-than-parser signature check. The three V1 GET handlers are below it, unchanged. |
| `packages/kudosity-laravel/src/Notifications/KudosityMessage.php` | `v1OnlyOptions()` is the routing table. Add to it if a new V1-only option appears. |
| `packages/kudosity-laravel/src/KudosityServiceProvider.php` | `baseUrlFor()` holds the flat-`base_url` guard — the thing a too-broad codemod rule would silently disable. |
| `packages/kudosity-client/src/Contracts/SentMessage.php` | Why the channel's return type is stable across the routing decision. |
| **`tests/Fixtures/V2Webhooks/README.md`** | **Read before touching anything that reads a webhook payload.** Real captured deliveries plus the arrival timeline; several behaviours the upstream docs contradict. |
| `tests/Fixtures/V2Senders/README.md` | What is and is not verified about the sender item shape, and the probe table that produced the request schemas. |
| `rename-map.json` | The consumer migration contract. Entry ordering matters — see Failed Approaches. |
| `.github/workflows/split.yml` | **Must keep `actions/checkout@v4`** — see Warnings. |
| `.env` | Gitignored, mode 600. `KUDOSITY_API_KEY`, `KUDOSITY_API_SECRET`, `KUDOSITY_FROM`, `KUDOSITY_TEST_RECIPIENT`. |

## Code Context

```php
// Phase 5's Laravel surface
$message = (new KudosityMessage('Hi'))->to('61400000000');
$message->apiVersion();     // ApiVersion::V2 by default
$message->v1Reasons();      // [] — or the options that forced V1
$message->forceV2();        // throws at apiVersion() if a V1-only option is set

// Notifications: toKudosity(), toKudosityMms(), toKudosityWhatsApp(), toKudosityRcs()
KudosityMmsMessage::create('Look')->media('https://e.com/a.jpg')->subject('Sale');
KudosityWhatsAppMessage::create()->template('order_update', ['ACME']);   // opens a conversation
KudosityRcsMessage::create('Shipped')->agentId('DemoSender');            // agent ID, not a number

// Receiving: POST {prefix}/events -> one of four typed events
public function handle(KudosityStatusReceived $e): void
{
    if (StatusPrecedence::supersedes($e->status->status, $this->recorded($e->status->id))) {
        // ... deliveries are at-least-once AND unordered
    }
}
```

**The V1-callbacks trap, which is the whole reason Phases 4 and 5 exist:** V1 attaches `dlr_callback` / `reply_callback` per send; **V2 has no per-send callback URL at all.** A call site moved from `bulk()` to `sms()` keeps sending and silently stops reporting unless an account-level webhook is registered. `UPGRADING.md` has the full entry.

## Resume Instructions

1. Confirm the baseline: `git checkout feat/kudosity-laravel-v2 && vendor/bin/pest --compact && vendor/bin/phpstan analyse --no-progress`
   - Expected: `844 passed (1660 assertions)` and `[OK] No errors`.
   - If different: `git log --oneline -8` — something moved since this handoff.
2. Push the branch and confirm CI is green at `12f2689`.
3. Merge to `main` and push. `Split Monorepo` should stay green — both target repos exist and were populated at `059650b`.
4. Start Phase 6: the standalone PHPUnit 11 suite for the client package on PHP 8.2/8.3/8.4, then the release checklist under Warnings.

## Setup Required

- PHP 8.3 or 8.4 for the dev toolchain (Pest 4 needs `^8.3.0`), though the packages declare `^8.2`.
- `composer install` at the repo root. No services or ports; unit tests use Saloon's `MockClient` and Orchestra Testbench.
- Live testing reads the gitignored `.env`. **No credential values are recorded here.** Note `parse_ini_file()` chokes on `#` comments containing parentheses, which is why that file's comments avoid them.
- For the receiver verification: `ngrok` (installed, authtoken configured) plus a local HTTP server. Webhook URLs must be HTTPS. The 2026-08-06 rig booted Testbench directly — `Orchestra\Testbench\Foundation\Application::create(basePath: vendor/orchestra/testbench-core/laravel, options: ['extra' => ['providers' => [KudosityServiceProvider::class]]])`, config supplied through real env vars because the resolving callback runs *before* config is loaded, then `$app['events']->listen(...)` per typed event writing one JSON line per dispatch. `php -S` needs `return false` for existing static files or Laravel 404s them, which matters because an MMS `content_url` has to be served from somewhere.

## Edge Cases & Error Handling

- **Handled:** both V2 envelope shapes; RFC 9457 with per-field `issues[]`; the plain `{"error": "..."}` body the webhook endpoints use; a truncated or hostile webhook payload; an unrecognised event type; a missing `webhooks` key; a stale flat `base_url`; a notifiable with no route for a channel (returns null, not an error).
- **Deferred:** `paginationDirection()` is forwarded into the query unvalidated. Both V2 paginators' `getPageItems()` declare `array<int, mixed>` but return `mixed`.
- **Unverified by construction:** the sender registration *item* shape, and the success bodies of `register()` / the verification calls. All read defensively with `raw` retained.

## Warnings

- **`.github/workflows/split.yml` must keep `actions/checkout@v4`.** `claudiodekker/splitsh-action@v1.0.0` runs `git config --local --unset-all http.https://github.com/.extraheader` under `set -e`; checkout v5+ stores the token via an includeIf file, so the unset hits a missing key (exit 5) and aborts the split *before any push*. Do not "fix the drift".
- **Release tags must be `v`-prefixed** (`v2.0.0`). Tag `1.7.0` was cut without it and never released.
- **`GET /v2/sms/{id}` 404s for a few seconds after `POST /v2/sms`.** Read-after-write lag, not a broken reader.
- **Statuses arrive UPPERCASE from webhooks and lowercase from the send endpoints.** `MessageStatus::fromApi()`'s case-insensitivity is load-bearing.
- **Real inbound text is untidy** — a captured reply is `"YES "`. Nothing trims it, deliberately, with tests on both parse paths.
- **A `LINK_HIT` is not evidence a human clicked.** The first hit routinely lands in the same second as `DELIVERED` — a link preview. `hits` counts machine fetches.
- **`Saloon\PaginationPlugin\Paginator::items()` is annotated as yielding `Response|PromiseInterface`** when it yields rows. That is why `SendersResource::allRegistrations()` walks the paginator's *responses* — iterating `items()` cannot be type-checked at level 6.
- **`ngrok`'s `x-forwarded-*` headers are the tunnel's, not Kudosity's.** Anything reading them is reading the test rig.
- **Kudosity replaced the account's virtual number**, because the previous one could not receive MMS. Inbound MMS works on the replacement; a number that *sends* MMS does not necessarily *receive* it. `.env` still names the old one — see Not Yet Done.
- **V2 deliveries are unsigned, now confirmed in writing** (Kudosity, 2026-08-06). `x-transmitsms-signature` is V1-only; V2 signing is roadmap. Their recommended substitute is `message_ref`, which is what `SignedMessageRef` already signs — so nothing in the SDK changes, but the design note is no longer an inference.
- **An inbound MMS delivery can be hundreds of KB.** One photo made a 204KB POST body, essentially all of it one base64 field. A receiver that logs `$raw` on this event type will log all of it.
- **PHP 8.2 is declared but untested.** Phase 6 closes this.
- **`withRetry()` does not actually retry HTTP failures** on either connector. Docblocks were corrected; the mechanism predates 2.0 and is deliberately untouched.
- **Release checklist before tagging** (dashboard work, in `UPGRADING.md`): rename the GitHub monorepo to `kudosity-php-sdk`, create the two split-target repos, register both on Packagist, mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned pointing at the replacements, then tag.
