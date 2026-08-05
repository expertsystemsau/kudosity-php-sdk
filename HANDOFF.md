---
handoff_version: "1"
source: xsys-handoff
mode: full
generated_at: "2026-08-05T22:28+10:00"
title: "Kudosity 2.0 — Phase 3 merged and live-verified, Phase 4 next"
status: resumed
resumed_at: "2026-08-06T00:43+10:00"
resumed_from_sha: "8ba965287204ef4bb5b3d7bb2d98f1ef5ab19b56"
branch: "main"
head_sha: "9f18b93390a83abc797b8b6faabdbecb4b79d9a8"
dirty_files: 0
diff_digest: "clean"
ticket_key: "none"
repo: "transmitsms-php-sdk"
submodules: []
next_step: "Cleanup pass (collapse the duplicated date_range block, fix PhoneNumber.php:234's missing /D), then write and execute the Phase 4 plan"
---

# Handoff: Kudosity 2.0, Phases 1-3 merged, Phase 4 next

## Goal

Upgrade this PHP SDK from the TransmitSMS V1 API to Kudosity's V2 API, shipping as a 2.0. Kudosity runs **two APIs under one account** and V2 cannot replace V1, so the SDK is dual-API: V2 is the default surface, V1 is retained for the capabilities that exist nowhere else.

| | Base URL | Auth | Covers |
|---|---|---|---|
| **V1** | `api.transmitsms.com` | HTTP Basic, key and secret | contact lists, bulk and scheduled sends, reporting, balance |
| **V2** | `api.transmitmessage.com` | `x-api-key` header, API key only | single-recipient SMS, MMS, WhatsApp, RCS, webhooks, senders |

**V2 never uses the secret. V1 always needs both.** Both hostnames are real and neither is a Kudosity domain — see Failed Approaches.

Six phases, specced in `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`. Each phase's plan is written just before it runs; writing them all upfront goes stale.

## Completed

- [x] **Phase 1 — rebrand**, merged. Packages `expertsystemsau/kudosity-php-client` and `kudosity-laravel-client`, namespace `ExpertSystems\Kudosity\`, config `config/kudosity.php`, env prefix `KUDOSITY_`, hook `toKudosity()`, channel `kudosity`. Ships `bin/kudosity-codemod` + `rename-map.json` + `UPGRADING.md`.
- [x] **Phase 2 — V2 core**, merged. `KudosityV2Connector`, `KudosityV2Request` (no body) / `KudosityV2BodyRequest` (JSON body), `Concerns\UnwrapsData`, `DecodesResponses`, `HasRetryPolicy`, `KudosityException::fromV2Response()`, `V2PagedPaginator` + `V2CursorPaginator` behind marker contracts. The V1 `SmsResource` was deleted, freeing `sms()`.
- [x] **Phase 3 — V2 channels, merged to `main` at `d852c78`.** 506 tests / 984 assertions (from 322/598), PHPStan level 6 clean, Pint clean, three manifests valid, codemod 0 changes on `packages`. Six tasks each individually reviewed, plus a final whole-branch review that returned **merge** after one fix wave. **The removed-symbol audit came back empty** — the first phase where it did; Phases 1 and 2 both shipped a gap it caught.
  - `$k->sms()`, `$k->mms()`, `$k->whatsapp()`, `$k->rcs()`, plus four facade `@method` lines.
  - SMS `POST/GET /v2/sms` + `GET /v2/sms/{id}` (paged on `smses`); MMS `POST/GET /v2/mms` (no list endpoint — `GET /v2/mms` does not exist); WhatsApp send/get/list (cursor on `data.messages`) with `Contracts\WhatsAppContent` + `TextContent`/`TemplateContent`/`CustomContent`; RCS send/get/list/capabilities with `Enums\RcsCapabilityCode`.
- [x] **SMS and MMS verified end to end against the live API**, both confirmed received on an AU handset. See Verification.
- [x] **V2 webhook reconnaissance done against the live API** — registered a real webhook against an ngrok tunnel, captured four real deliveries, and saved them as fixtures at `tests/Fixtures/V2Webhooks/` (`9f18b93`). Rig fully torn down; the account has zero webhooks. This answered questions the documentation does not — see Code Context.

## Not Yet Done

- [ ] **Cleanup pass, agreed as the immediate next step.** Two recorded follow-ups, both cheaper before Phase 4 builds on them:
  - Collapse the duplicated `date_range` allow-list + `custom_date` pairing block between `ListWhatsAppRequest` and `ListRcsRequest` (two verbatim copies of one coupling rule, 8 duplicated tests each). The final review asked for this **before a third cursor list exists**, and Phase 4's sender registrations may add one.
  - Fix `packages/kudosity-client/src/Support/PhoneNumber.php:234` — `'/^[a-zA-Z0-9]+$/'` lacks the `D` modifier, so a sender ID with a trailing newline passes validation. Phase 4 touches sender code.
- [ ] **Capture a `LINK_HIT` fixture** while the approach is fresh: send an SMS with `track_links` and a URL, tap it, capture the payload. No provisioning needed, and takes us to 5 of 10 event types with real fixtures. **Deliberately skipping `OPT_OUT`** — triggering it means replying STOP, which opts the test handset out of receiving messages.
- [ ] **Phase 4 — webhooks and senders.** Webhook CRUD, the ten typed inbound payload DTOs, `POST/GET /v2/senders/registrations` and verifications, `SenderStatus` with `isReadyToUse()`. **Three design decisions already settled** — see Key Decisions.
- [ ] **Phase 5 — Laravel integration.** Four notification channels, the V2 webhook receiver route, `kudosity:webhook:*` commands, config `base_url` split into `v1`/`v2`. **Blocked on nothing, but its receiver-auth design depends on the Kudosity answers below.**
- [ ] **Phase 6 — tests, CI, docs, release.** Standalone PHPUnit 11 suite for the client package on PHP 8.2/8.3/8.4, doc finalisation, release.
- [ ] **WhatsApp and RCS are still not verified end to end.** The account has neither provisioned. Both fail cleanly with correct error mapping, but no message has traversed either channel. **Phase 4 unblocks this**, because sender readiness is read via `GET /v2/senders/registrations`.
- [ ] **Two CI failures, both pre-existing Phase 1 code, both surfaced by the first-ever push (`edb8e8d`).** Neither is caused by Phase 3, and neither blocks Phase 4 — but CI is red until they are fixed.
  - **`run-tests` fails on all 4 Windows jobs; all 4 Ubuntu jobs pass** (PHP 8.3/8.4 × Laravel 11/12). Exactly two tests fail, both in `Tests\Unit\CodemodTest`: *"it renames BASE_URL_SMS and flags the removed connector members for manual review"* (`tests/Unit/CodemodTest.php:196`) and *"it flags KudosityClient::sms() call sites for manual review without false-positiving on emailSms()"* (`:285`). **Root cause is path separators, not behaviour** — the codemod reports `app\Notifications\Legacy.php` while the tests assert `app/Notifications/Legacy.php`. The functional assertions in the same test passed (`BASE_URL_SMS` was renamed, `BASE_URL_MMS` written), so the codemod works on Windows; only its printed paths differ. **Recommended fix: normalise the codemod's *displayed* paths to `/`** (keep native separators for filesystem operations). That fixes both tests and gives consistent report text cross-platform, which matters because those reports get pasted into issues and `UPGRADING.md`.
  - **`Split Monorepo` fails.** Its targets are `expertsystemsau/kudosity-php-client` and `expertsystemsau/kudosity-laravel-client`, **which do not exist yet** — creating them is already on the release checklist. Note the split runs on 2026-08-04 *succeeded*, because the targets were then the old `transmitsms-*` names: **Phase 1's rename broke this workflow and it could not be noticed while `main` was unpushed.** Also note `split.yml` fires on **every push to `main`**, not only on `v*` tags, so it will keep failing until those repos exist. No release risk: release creation is gated on `refs/tags/`, and `update-changelog.yml` only fires on `release: released`.
  - Unrelated noise: pushing also woke Dependabot, which opened several dependency-update runs; one failed. Not connected to this work.
- [x] **`main` is pushed** — `origin/main` is at `edb8e8d`, 0 commits ahead. Phases 1-3 are no longer only on one machine. No release exists and no tag has been cut.

### Awaiting answers from Kudosity (emails drafted, not blocking Phase 4)

1. **Are V2 webhook deliveries signed?** Confirmed empirically that they are not — see Code Context. Asked whether signing exists and we missed it, or is roadmapped.
2. **Are there stable egress IP ranges for webhook deliveries?** Observed `35.197.178.201` (Google Cloud). Asked whether ranges are published and stable.
3. **Does inbound MMS need separate provisioning on `61426309571`?** Outbound MMS works and inbound SMS works, but two picture replies produced no `MMS_INBOUND` at all with a verified-reachable endpoint.

Answers 1 and 2 shape **Phase 5's** receiver authentication, not Phase 4's code. Answer 3 is provisioning, not code.

## Failed Approaches (Don't Repeat These)

**A brand sweep using `\btransmitsms\b` silently corrupted the V1 hostname.** In `api.transmitsms.com` the dots are word boundaries, so `\b` matched *inside* the hostname and rewrote `KudosityV1Connector::BASE_URL` to a non-existent `https://api.kudosity.com`. The grep policing the sweep could not see it, because the corruption destroyed the string being searched for. **Any sweep needs a negative check for the corrupted form, not only the original.** Exclude `docs/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod`, `tests/Unit/CodemodTest.php`, **`HANDOFF.md`, and `.ai-skills/handoffs/*`** — the last two legitimately contain the old brand and produce 6 non-defect hits.

**A `\->sms\(\)` sweep cannot see a static facade call.** Three rounds reported "clean" while `README.md:193` held `Kudosity::sms()`. Use `'\->sms\(\)|::sms\(\)|\bSmsResource\b'`, and never accept the word "clean" — ask for the literal output.

**Removed public symbols went missing from `rename-map.json` in Phases 1 and 2**, caught only at whole-branch review, because removals land in one task and the map in another. Phase 3's Task 6 audit step is why its audit came back empty. **Keep that step in every phase plan.**

**`removed` map entries cannot flag a class.** The flagger appends `(` before matching, so it never matches a `use`, a `::class`, or a type hint. A removed **class** goes in `classes` with a real target; only methods belong in `removed`.

**Symlinking `vendor/` into a git worktree does not work here.** Composer's `autoload_psr4.php` resolves `$baseDir` back through the symlink, so `vendor/bin/pest` runs the tracked tree's code and a deliberate regression appears to pass. Work on a branch in the primary checkout.

**Regexes anchored `'/^…$/'` without `/D` have a newline hole.** PCRE's `$` also matches before a final newline, so `TemplateContent` shipped accepting `"order_update\n"` — past the only rule that class enforces. Fixed with `/D` plus a trailing-newline test. **`PhoneNumber.php:234` still has this** (see Not Yet Done). Its sibling at `:251` does **not** — `\s` already absorbs the newline inside that character class, so this is one real fix, not two.

**Three tests that could not fail shipped inside Phase 3 and were caught in review.** One named "casts its string totals" never asserted the casts; one asserted only an exception type, so deleting the dedicated guard let the regex path satisfy it; one deliberate `'' → null` normalisation had no assertion despite the fixture setting up the exact case. **The discipline that catches them:** revert the behaviour, watch the test fail, restore, and put that transcript in the report. Corollaries — give each validation rule its own **single-violation** input, and assert on something **only** that rule produces (an `errorCode` plus a distinctive fragment).

**The Phase 3 plan's predicted test counts were wrong from Task 1 onward** — "14 from the dataset plus 7" double-counted the dataset's own `it()` block, shifting every later prediction. **Do not predict exact counts in Phase 4's plan**; say "at least N", and tell implementers to transcribe test content verbatim rather than inventing a test to hit a number.

**Do not invent a DTO for an undocumented response shape.** `ListWhatsAppRequest` deliberately has no `createDtoFromResponse()`: the upstream docs list the endpoint with no response body, and it is unreachable anyway because `V2CursorPaginator::getPageItems()` reads `data.messages` off the JSON and never calls `.dto()`. Same reasoning removed the speculative SMS date filters.

## Key Decisions

| Decision | Rationale |
|---|---|
| Dual-API, V2 primary | V2 has no equivalent for contact lists, bulk/multi-recipient sends, scheduling, reporting or balance. |
| API hostnames keep their real values | Kudosity renamed neither. Both must survive every sweep. |
| `sms()` is V2 single-recipient only | `POST /v2/sms` takes one recipient and cannot schedule; multi-recipient/list/scheduled stay on `bulk()`. |
| GET readers extend `KudosityV2Request`, writers `KudosityV2BodyRequest` | A body on a GET — even `[]` — is stripped or rejected by some gateways. |
| Payload resolution lives on the request, not the DTO | `UnwrapsData` sits on the request base; `createDtoFromResponse()` calls `static::payload($response)`. |
| One `MessageStatus` enum as the union of three vocabularies | The lists disagree and will grow; all resolve via `fromApi()` to `Unknown` rather than throwing. |
| `ListSmsV2Request` guards the status filter to its documented 13 | The union offered 15 where 2 are invalid for `GET /v2/sms`, and an unsupported query param is silently ignored. |
| Undocumented SMS date filters removed | Inferred wire names plus silent ignoring means results that look filtered and are not. |
| WhatsApp/RCS recipients: normalise punctuation, never guess a country | A country default would silently prepend `61` to a number typed for another country — a wrong send, not a failed one. |
| Non-string WhatsApp template params rejected, not stringified | `TemplateContent`'s `@param` stays `array<int, mixed>` deliberately, or the runtime `is_string()` guard becomes unreachable to PHPStan. |
| `custom_date` pairing enforced in both directions | Dates without `custom_date` are silently ignored by the API. |
| `SmsFallback::fromResponse()` is the single shared read path | The constructor rejects an empty message — right for a request object, wrong while reading a response. Constructor invariant untouched. |
| `rename-map.json`'s `"sms": null` kept | `sms()` returns with different semantics, so a 1.x multi-recipient call would silently send to a comma-joined string instead of failing. |
| PHP floor stays `^8.2` | User's call, despite the toolchain needing 8.3+. |
| **Phase 4: one uniform `messageRef()` accessor across all ten event DTOs** | The correlation key sits at a different path per event type (`status.message_ref`, `mo.last_message.message_ref`, `link_hit.source_message.message_ref`, `opt_out.source_message.message_ref`). Hiding that is exactly the trap-as-type pattern this migration uses. |
| **Phase 4: the SDK ships the ordering/idempotency guard** | Multiple status events fire per message and are not order-guaranteed. Keyed on `status.id`, a non-terminal status must never overwrite a terminal one. Getting this wrong silently corrupts delivery reporting, so it does not belong in consumer code. |
| **Phase 4: the SDK ships a signed-`message_ref` helper** | Kudosity does not sign webhooks, so we sign our own correlation key: `"{entity}:{hmac(entity, secret)[:16]}"`. A forger without the secret cannot fabricate a payload that correlates to a real entity. User's explicit direction: the SDK should do as much of the work as possible. |
| **Phase 5: webhook receiver authenticates by unguessable URL** | There is no signature to verify — confirmed empirically. Registration is one-time, so a secret in the path is workable, and rotation is register-new / verify / delete-old with no downtime. |

## Current State

**Working.** `main` pushed and level with `origin/main` at `edb8e8d`. Phases 1-3 merged. SMS and MMS live-verified. Local suite green, PHPStan level 6 clean, Pint clean. CI green on Ubuntu (PHP 8.3/8.4 × Laravel 11/12) and PHPStan.

**Broken.** Two CI failures, both pre-existing Phase 1 code, detailed under Not Yet Done: `run-tests` on Windows only (2 `CodemodTest` tests, path-separator assertions at `tests/Unit/CodemodTest.php:196` and `:285`) and `Split Monorepo` (target repos not created yet). Nothing broken locally, and neither blocks Phase 4.

**Uncommitted changes.** None.

## Verification

At `9f18b93`:

| Command | Result |
|---|---|
| `vendor/bin/pest --compact` | 506 passed (984 assertions) |
| `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` (level 6) |
| `vendor/bin/pint --test` | passed |
| `composer validate --strict` (root + both packages) | all three valid |
| `php bin/kudosity-codemod packages` | 0 files would change |
| branding sweep | 6 hits, all in `HANDOFF.md` / `.ai-skills/handoffs/*` — narrative docs, not code. Negative check for the corrupted `api.kudosity.com` form: clean. |
| removed-symbol audit | empty |
| `composer test-coverage` | not run this session |

**Live API verification** (real account, real handset):

| Check | Result |
|---|---|
| V1 auth — `$k->account()->getBalance()` | OK, 529.77 AUD (HTTP Basic works) |
| V2 auth — `GET /v2/sms` | OK, read real history (`x-api-key`, paginator, envelope all work) |
| SMS send → handset | **delivered, confirmed received** |
| `GET /v2/sms/{id}` read-back | OK after a short delay — see Warnings |
| `GET /v2/sms` filtered by `message_ref` | OK |
| MMS send with attachment → handset | **delivered, confirmed received** |
| SMS status filter guard, live | rejected `MessageStatus::Queued` before any request |
| RCS agent-ID guard, live | rejected a phone-number-shaped sender locally |
| Webhook create / update / list / delete | 201 / 200 / 200 / 200 — all worked via curl |
| `SMS_STATUS` ×2, `SMS_INBOUND`, `MMS_STATUS` | captured live, saved as fixtures |
| `MMS_INBOUND` | **never arrived** — two picture replies, endpoint verified reachable throughout |
| WhatsApp send | `ValidationException`, `getIssues()` → `[{"name":"account_id","message":"no WhatsApp sender found for this account"}]` |
| RCS capabilities | `AccessDeniedException: sender is not owned by this account` |

Casts confirmed live: `sms_count` arrives as `"1"` and casts to `int`; `routed_via` arrives as `""` and normalises to `null`; the nine-fractional-digit `created_at` parses; MMS returns a single `country` field, not SMS's pair.

## Files to Know

| File | Why It Matters |
|---|---|
| `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md` | The six-phase design. Sections 2-3 authoritative for the V2 surface. `/docs` is gitignored — force-added. |
| `docs/superpowers/plans/2026-08-05-kudosity-phase-3-v2-channels.md` | Phase 3's plan — the template for Phase 4's, including its Global Constraints. |
| **`tests/Fixtures/V2Webhooks/`** | **Real captured webhook deliveries plus a README explaining what they pin and which fields the docs omit. Phase 4 should build its DTOs against these, not the doc examples.** |
| `.agents/skills/kudosity-webhooks/SKILL.md` | The authoritative webhook contract. **Read before writing any Phase 4 request class.** Never modify anything under `.agents/skills/`. |
| `packages/kudosity-client/src/Resources/V2Resource.php` | Base for V2 resources: `sendAndDto()`, `paginate()`. Phase 4's resources extend it. |
| `packages/kudosity-client/src/Requests/V2/` | The twelve Phase 3 requests — the shape Phase 4 copies. |
| `packages/kudosity-client/src/Data/V2/SmsFallback.php` | The constructor-vs-`fromResponse()` split; reuse for any request-shaped object read back from a response. |
| `rename-map.json` | The consumer migration contract. Phases 1 and 2 both shipped a gap here. |
| `.github/workflows/split.yml` | **Must keep `actions/checkout@v4`** — see Warnings. |

## Code Context

```php
$k = new KudosityClient(apiKey: '…', apiSecret: '…');  // secret optional — omit for V2-only

$k->sms()->send('Hi', to: '61478038915', from: '61481074185');   // exactly ONE recipient
$k->sms()->get($id);
$k->sms()->list(status: MessageStatus::Delivered);                // V2PagedPaginator
$k->mms()->send(to: '614…', from: '614…', contentUrls: ['https://…/i.png'], subject: 'Max 20 ASCII');
$k->whatsapp()->text('Hi', to: '614…');
$k->whatsapp()->template('order_update', ['ACME', '12345'], to: '614…');
$k->rcs()->capabilities(['614…'], agentId: 'DemoSender');
$k->rcs()->send('Hi', to: '614…', agentId: 'DemoSender', fallback: new SmsFallback('SMS body'));

// V1, because V2 has no equivalent
$k->bulk()->send($msg, $upTo500CommaSeparated);
$k->bulk()->sendToList($msg, listId: 4213644);
$k->reporting(); $k->numbers(); $k->lists(); $k->keywords(); $k->account(); $k->emailSms();
```

**Envelope asymmetry, the most common way to misread this API:** SMS and MMS return their object **flat**. WhatsApp, RCS, RCS capabilities and sender registrations **wrap it** as `{"data": {...}}`. `static::payload($response)` resolves both. Both wrapped channels have a test planting a *decoy* top-level `id`, so a wrong read resolves the wrong message rather than null.

**String-typed numerics:** `sms_count`, `total_records`, `total_segments` arrive as strings. `"1" + 1` is `"11"`.

### V2 webhooks — what the live reconnaissance established

Architecturally different from V1's per-send `dlr_callback`/`reply_callback` (those are **V1-only**; V2 has no per-send callback URL at all). V2 webhooks are **account-level resources managed over the API**: `POST /v2/webhook` (201), `GET /v2/webhook`, `PUT /v2/webhook/{id}`, `DELETE /v2/webhook/{id}`. One webhook can serve every channel.

Verified against the live API, and **not all of it is documented**:

- **Deliveries are NOT signed.** The complete observed header set is `accept-encoding`, `content-length`, `content-type`, `host`, `sentry-trace`, `traceparent`, `user-agent: Go-http-client/2.0`. No HMAC, signature, or auth header of any kind. A receiver cannot verify a delivery came from Kudosity. Source IP `35.197.178.201` (Google Cloud).
- **Three undocumented payload fields:** `webhook_id` and `webhook_name` at top level, and `status.description` on `MMS_STATUS` carrying carrier detail (e.g. `"Sent to Vodafone (response status details: Success)"`). Phase 4's DTOs should carry all three.
- **`GET /v2/webhook` returns `{"webhooks": [...]}`** — a named collection key like SMS's `smses`. **When there are none it returns `{}`, omitting the key entirely**, so the list DTO must tolerate a missing key.
- **`PUT` is a replace, not a patch** — it requires the full `name` + `url` + `filter`.
- **Webhook create/list responses are flat**, not `data`-wrapped.
- **`MMS_STATUS` reached `DELIVERED`**, contradicting the doc's claim that it carries "internal statuses only — SENT, FAILED".
- **Correlation is `message_ref`**, and it arrives at a different path per event type: `status.message_ref` (status events), `mo.last_message.message_ref` (inbound), `link_hit.source_message.message_ref`, `opt_out.source_message.message_ref`. A composite key round-tripped intact (`order-9931:cust-4471`), including through a customer reply.
- **`last_message` is best-effort** — absent when no recent outbound matches, so unsolicited inbound cannot be correlated *or authenticated*. Treat those as low-trust.
- **Route replies on `message_ref`, never the phone number.** Number matching breaks when one contact is in two flows at once, and again when `routed_via` shows a shared number delivered the message.
- **In an inbound payload `mo.sender` is the customer and `mo.recipient` is your number.** The webhook `filter`'s `sender` key matches against `mo.recipient` for inbound events — i.e. it filters by *your* number.
- **Ten event types:** `SMS_STATUS`, `SMS_INBOUND`, `MMS_STATUS`, `MMS_INBOUND`, `WHATSAPP_STATUS`, `WHATSAPP_INBOUND`, `RCS_STATUS`, `RCS_INBOUND`, `LINK_HIT`, `OPT_OUT`. `SMS_STATUS` does **not** report WhatsApp or RCS. `READ` is RCS-only. `ACCEPTED` is not `DELIVERED`. Top-level `event_type` in the *request* body is deprecated — use `filter.event_type`.
- Webhook validation errors return a plain `{"error": "..."}` string, not RFC 9457 — `fromV2Response()` already has that branch.

## Resume Instructions

1. Confirm the baseline: `vendor/bin/pest --compact && vendor/bin/phpstan analyse --no-progress`
   - Expected: `506 passed (984 assertions)` and `[OK] No errors`.
   - If different: check `git log` — something moved since this handoff.
2. Do the cleanup pass (see Not Yet Done): collapse the duplicated `date_range` block across `ListWhatsAppRequest`/`ListRcsRequest`, and add `/D` to `PhoneNumber.php:234` with a trailing-newline test.
   - Expected: suite still green and above 506; the new `/D` test fails before the fix and passes after.
3. Optionally capture the `LINK_HIT` fixture: send an SMS with `track_links: true` and a URL, tap the link, capture the delivery. Rig recipe is in this handoff's history — local PHP server plus `ngrok http <port>`, register a webhook at the tunnel URL, tear down afterwards. **Skip `OPT_OUT`** (it opts the test handset out).
4. Write the Phase 4 plan at `docs/superpowers/plans/<date>-kudosity-phase-4-webhooks-senders.md`, modelled on Phase 3's. **Read `.agents/skills/kudosity-webhooks/SKILL.md` and `tests/Fixtures/V2Webhooks/README.md` first.** Carry in the three settled design decisions from Key Decisions, the undocumented fields, and a removed-symbol audit step. Do not predict exact test counts.
5. Execute it with `superpowers:subagent-driven-development`, on a branch in the primary checkout — **not** a git worktree.
6. Once sender registrations exist, finish the WhatsApp and RCS live verification: provision a WhatsApp Business sender and an RCS agent ID, then send one message on each.
   - Expected: a real send returning a wrapped `data` envelope; `RcsCapabilityCode::isReachable()` true for `ENABLED`.

## Setup Required

- PHP 8.3 or 8.4 for the dev toolchain (Pest 4 requires `^8.3.0`), even though the packages declare `^8.2`.
- `composer install` at the repo root. No services or ports; every unit test uses Saloon's `MockClient`.
- For live testing: `KUDOSITY_API_KEY` (all four V2 channels) and `KUDOSITY_API_SECRET` (V1 only). See `packages/kudosity-laravel/config/kudosity.php` for the full key list. **No credential values are recorded here or anywhere in the repo.** The account has a dedicated AU number available as an SMS/MMS sender.
- For webhook testing: `ngrok` (installed, authtoken configured) plus a local HTTP server. Webhook URLs must be HTTPS.
- WhatsApp and RCS need provisioning that the account lacks: a WhatsApp Business sender and a registered RCS **agent ID**. Alphanumeric sender IDs and RCS agents need Kudosity approval and are not instant.

## Edge Cases & Error Handling

- **Handled:** both V2 envelope shapes; RFC 9457 errors with per-field `issues[]` (verified live); a plain-string `{"error": "..."}` body; a non-JSON error body (a proxy's HTML 502 yields `ServerException`, not `JsonException`); the missing-secret case; all documented paginator termination signals.
- **Deferred:** `paginationDirection()` is forwarded into the query unvalidated. Both V2 paginators' `getPageItems()` declare `array<int, mixed>` but return `mixed`, so a malformed page throws `TypeError` inside iteration — matches the V1 paginator.
- The full deferred list lives in `~/.claude/projects/-home-mitchell-projects-transmitsms-php-sdk/memory/transmitsms-sdk-followups.md`.

## Warnings

- **`GET /v2/sms/{id}` 404s for a few seconds after `POST /v2/sms`.** Read-after-write lag, **not** a broken reader — proven by reading an older id successfully in the same run and the new id fine moments later. Do not "fix" the reader; retry or poll.
- **The same message's status is `DELIVERED` from `GET /v2/sms/{id}` but `delivered` from `GET /v2/sms`.** `MessageStatus::fromApi()`'s case-insensitivity is load-bearing.
- **Real inbound message text is untidy** — the captured reply was `"YES "`, with a trailing space. Do not assume trimmed input.
- **`.github/workflows/split.yml` must keep `actions/checkout@v4`.** `claudiodekker/splitsh-action@v1.0.0` runs `git config --local --unset-all http.https://github.com/.extraheader` under `set -e`; checkout v5+ stores the token via an includeIf file, so the unset hits a missing key (exit 5) and aborts the split *before any push*. Do not "fix the drift".
- **Release tags must be `v`-prefixed** (`v2.0.0`). Tag `1.7.0` was cut without it and never released.
- **`bin/kudosity-codemod` never reaches consumers via composer** — `split.yml` only splits `packages/*`. `UPGRADING.md` documents a direct fetch.
- **PHP 8.2 is declared but untested.** Pest 4 needs `^8.3.0`. Phase 6 closes this.
- **`withRetry()` does not actually retry HTTP failures** on either connector — a 502 under `withRetry(tries: 3)` makes one attempt. Docblocks were corrected; the mechanism predates 2.0 and is deliberately untouched.
- **PHPStan hard-errors on a trait with zero consumers** (`trait.unused`). That is why `KudosityV2Request` uses `UnwrapsData` — a real constraint, not style.
- **`Support/PhoneNumber.php:234` has a real `/D` defect** (see Not Yet Done). `:251` does not — one fix, not two.
- **Release checklist before tagging** (dashboard work, in `UPGRADING.md`): rename the GitHub monorepo to `kudosity-php-sdk`, create the two split-target repos, register both on Packagist, mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned pointing at the replacements, then tag.
