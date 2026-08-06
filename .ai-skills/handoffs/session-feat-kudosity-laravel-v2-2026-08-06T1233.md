---
handoff_version: "1"
source: xsys-handoff
mode: full
generated_at: "2026-08-06T12:33+10:00"
title: "Kudosity 2.0 — Phase 5 (Laravel) 5 of 6 tasks done; docs + live verification left"
status: in-progress
branch: "feat/kudosity-laravel-v2"
head_sha: "9a3e6c2f5bab3dffd7766f53e2e5651d2589260f"
dirty_files: 0
diff_digest: "clean"
ticket_key: "none"
repo: "transmitsms-php-sdk"
submodules: []
next_step: "Finish Phase 5 Task 6 — the three README/CLAUDE.md updates, then the live receiver verification with the ngrok rig, then merge to main"
---

# Handoff: Kudosity 2.0 — Phase 5 part-done on `feat/kudosity-laravel-v2`

## Goal

Upgrade this PHP SDK from the TransmitSMS V1 API to Kudosity's V2 API, shipping as a 2.0. Kudosity runs **two APIs under one account** and V2 cannot replace V1, so the SDK is dual-API: V2 is the default surface, V1 is retained for what exists nowhere else.

| | Base URL | Auth | Covers |
|---|---|---|---|
| **V1** | `api.transmitsms.com` | HTTP Basic, key **and** secret | contact lists, bulk and scheduled sends, reporting, balance |
| **V2** | `api.transmitmessage.com` | `x-api-key`, key only | single-recipient SMS, MMS, WhatsApp, RCS, webhooks, senders |

Both hostnames are real; neither is a Kudosity domain — see Failed Approaches. Six phases, specced in `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`.

**This branch is Phase 5 (Laravel integration), five of six tasks in.** Phases 1–4 are merged and pushed on `main` (`a512f02`).

## Completed

- [x] **Phases 1–4 merged to `main` and pushed.** Rebrand; V2 core (connector, RFC 9457 errors, both paginators); the four V2 channels (`sms()`, `mms()`, `whatsapp()`, `rcs()`); webhooks + senders.
- [x] **Phase 5 Task 1 — config and container.** `base_url` split into `v1`/`v2`; `KudosityV2Connector` registered as a singleton (**it cannot autowire** — `$apiKey` has no default); client built via `fromConnectors()` so `kudosity.timeout` reaches V2 and the client's V2 connector *is* the container's. A config still carrying the flat `base_url` string **throws on boot**, echoing the value and naming both replacement keys. New keys: `country_code`, `mms.sender`, `whatsapp.sender`, `rcs.agent_id`, `webhooks.events.{enabled,path}`.
- [x] **Phase 5 Task 2 — routing and one return type.** `Contracts\SentMessage` (`id(): string`, `recipientCount(): int`, `status(): ?MessageStatus`), implemented by V1 `SmsData` and V2 `SmsMessageData`. `KudosityMessage::apiVersion()` / `v1Reasons()` / `forceV1()` / `forceV2()`. **`forceV2()` throws** when the message uses a V1-only option rather than dropping it.
- [x] **Phase 5 Task 3 — four channels.** `kudosity` (routing), plus `kudosity-mms`, `kudosity-whatsapp`, `kudosity-rcs` with their message builders, registered on the notification manager, each with its own sender default.
- [x] **Phase 5 Task 4 — the V2 receiver.** `POST {prefix}/events` handles all ten event types and dispatches `KudosityStatusReceived` / `KudosityInboundReceived` / `KudosityLinkHitReceived` / `KudosityOptOutReceived`, carrying the Phase 4 event objects. The three V1 GET routes are untouched and still tested.
- [x] **Phase 5 Task 5 — the three Artisan commands.** `kudosity:webhook:list`, `:install`, `:delete`. `install` builds its receiver URL through `CallbackUrlBuilder` — **not a convenience**: the receiver 403s an unsigned request, so a hand-assembled URL registers a webhook that can never deliver, and fails silently because Kudosity cannot report that your endpoint is rejecting it. `CallbackType` gained an `EVENTS` case so the same signing machinery is reused.
- [x] **Phase 5 Task 6, partly — audit and consumer contract.** Removed-symbol audit run and resolved (see Failed Approaches); `rename-map.json` entries for the config and env break, ordering-pinned and idempotence-tested; `UPGRADING.md` and `CHANGELOG.md` written.
- [x] **CI green on this branch at `e11fb86`** — `run-tests`, PHPStan and Pint all pass. `Split Monorepo` does not fire on branches.

## Not Yet Done

- [ ] **Phase 5 Task 6, remainder — three docs.** `packages/kudosity-laravel/README.md`, root `README.md` and `CLAUDE.md` still describe one channel and V1 callbacks only. `UPGRADING.md` and `CHANGELOG.md` are already done — do not redo them.
- [ ] **Phase 5 Task 6 — live receiver verification. This is the step that proves the phase**; everything else is mocked. Same ngrok rig as Phase 4: local server + `ngrok http <port>`, install a webhook at the tunnel URL, send one tracked SMS, confirm the typed events fire, tear down, confirm the account is back to zero webhooks. **Skip `OPT_OUT`** — it opts the test handset out of receiving messages.
- [ ] **Phase 6 — tests, CI, docs, release.** Standalone PHPUnit 11 suite for the client package on PHP 8.2/8.3/8.4, then release.
- [ ] **`register()` and the sender SMS verification flow are still not live-verified.** Deliberate: completing one registers a personal mobile as a sender and sends a real code to it. Wants a human present.
- [ ] **WhatsApp and RCS remain unverified end to end**, and Phase 4 established that the senders endpoint does **not** unblock this — it registers personal mobile numbers only. Needs Kudosity to provision a WhatsApp Business sender and an RCS agent.
- [ ] **Create the two split-target repos** — `expertsystemsau/kudosity-php-client` and `kudosity-laravel-client` do not exist, so `Split Monorepo` fails on every push to `main`. Dashboard work; it is the only red job anywhere.
- [ ] **Decide whether to keep rejecting `http://` webhook URLs.** The API accepts them (201); this SDK does not. Flagged for review.
- [ ] **Rotate the API key and secret** — they were pasted into a chat transcript.

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
| The SDK rejects an `http://` webhook URL although the API accepts one | Deliveries carry message text and are unsigned, so a plaintext endpoint is readable and forgeable. **Flagged for review.** |
| **Phase 5: `base_url` becoming an array fails loudly on a stale flat value** | A published config is not re-published on upgrade, and a stale value points at the V1 host — silently ignoring it would send every V2 request to the wrong API. |
| **Phase 5: `SentMessage::status()` is null for V1, never invented** | The V1 send response carries no status; a fabricated `Pending` is indistinguishable from a real one. V2's concrete class narrows the return type to non-nullable, which is legal by covariance. |
| **Phase 5: `recipientCount()` is 1 for V2, not `sms_count`** | Two *segments* to one person is one recipient; conflating them over-reports reach. |
| **Phase 5: the SMS channel routes V2 by default, V1 only for what V2 cannot express** | And `forceV2()` throws rather than dropping an option — silently ignoring a `sendAt()` turns a scheduled send into an immediate one, a wrong send rather than a failed one. |
| **Phase 5: WhatsApp omits its sender rather than defaulting to `kudosity.from`** | It needs a registered WhatsApp Business number; an SMS sender ID would be rejected. Null lets the account default apply, matching the API. |
| **Phase 5: the receiver returns 200 for unknown event types and unparseable bodies** | Neither is retryable, and a non-2xx earns a retry into the same failure. Only a bad signature gets 403. |
| PHP floor stays `^8.2` | User's call, despite the toolchain needing 8.3+. |

## Current State

**Working.** Branch `feat/kudosity-laravel-v2` at `e11fb86`, pushed, clean tree, 6 commits ahead of `main`. Everything above under Completed. CI green on the branch tip.

**Broken.** Nothing on this branch. On `main`, `Split Monorepo` fails because the two split-target repos do not exist — not code, and it does not run for branches.

**Uncommitted changes.** None.

## Verification

At `e11fb86` on this branch:

| Command | Result |
|---|---|
| `vendor/bin/pest --compact` | 822 passed (1619 assertions) |
| `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` (level 6) |
| `vendor/bin/pint --test` | passed |
| `composer validate --strict` (root, client, laravel) | all three valid |
| `php bin/kudosity-codemod packages` | 0 files would change |
| `php bin/kudosity-codemod tests` | 1 file — `tests/Unit/CodemodTest.php`, old-brand fixtures by design; pre-existing, `main` reports the same |
| branding sweep + hostname negative check | both clean; both real hostnames intact |
| CI at `e11fb86` | `run-tests`, `PHPStan`, `Fix PHP code style issues` all SUCCESS. **Not yet run for the two commits after it** — check before trusting. |
| Live receiver verification | **not run — this is the outstanding Task 6 step** |
| `composer test-coverage` | not run this session |

Mutation testing ran on Phase 5 tasks 1-4 — 4 on the provider, 7 on the routing/contract, 7 on the channels, 5 on the receiver. **Task 5 (the commands) was not mutation-tested** — worth doing. All caught except three that were informative rather than gaps (see Failed Approaches).

## Files to Know

| File | Why It Matters |
|---|---|
| `docs/superpowers/plans/2026-08-06-kudosity-phase-5-laravel.md` | **The plan being executed.** Task 5 and the rest of Task 6 are what remain. |
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
   - Expected: `822 passed (1619 assertions)` and `[OK] No errors`.
   - If different: `git log --oneline -8` — something moved since this handoff.
2. Do the rest of **Task 6**: `packages/kudosity-laravel/README.md`, root `README.md`, `CLAUDE.md`. `UPGRADING.md` and `CHANGELOG.md` are done.
3. Run the **live receiver verification** — the step that proves the phase. Rig recipe in the plan's Task 6 Step 5. Leave the account at zero webhooks.
4. Re-run the full verification block from the plan, then merge to `main` and push. CI on `main` will show `Split Monorepo` failing; that is expected.

## Setup Required

- PHP 8.3 or 8.4 for the dev toolchain (Pest 4 needs `^8.3.0`), though the packages declare `^8.2`.
- `composer install` at the repo root. No services or ports; unit tests use Saloon's `MockClient` and Orchestra Testbench.
- Live testing reads the gitignored `.env`. **No credential values are recorded here.** Note `parse_ini_file()` chokes on `#` comments containing parentheses, which is why that file's comments avoid them.
- For the receiver verification: `ngrok` (installed, authtoken configured) plus a local HTTP server. Webhook URLs must be HTTPS.

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
- **PHP 8.2 is declared but untested.** Phase 6 closes this.
- **`withRetry()` does not actually retry HTTP failures** on either connector. Docblocks were corrected; the mechanism predates 2.0 and is deliberately untouched.
- **Release checklist before tagging** (dashboard work, in `UPGRADING.md`): rename the GitHub monorepo to `kudosity-php-sdk`, create the two split-target repos, register both on Packagist, mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned pointing at the replacements, then tag.
