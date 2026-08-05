---
handoff_version: "1"
source: xsys-handoff
mode: full
generated_at: "2026-08-05T21:42+10:00"
title: "Kudosity 2.0 — Phase 3 done and live-verified, Phase 4 next"
status: in-progress
branch: "feat/kudosity-v2-channels"
head_sha: "43ee40b0ef21abbbe35ec5b0137e09dc622c5fe9"
dirty_files: 0
diff_digest: "clean"
ticket_key: "none"
repo: "transmitsms-php-sdk"
submodules: []
next_step: "Write the Phase 4 plan (webhook CRUD + ten typed inbound payload DTOs + sender registrations), then execute it"
---

# Handoff: Kudosity 2.0, Phase 3 complete

## Goal

Upgrade this PHP SDK from the TransmitSMS V1 API to Kudosity's V2 API, shipping as a 2.0. Kudosity runs **two APIs under one account** and V2 cannot replace V1, so the SDK is dual-API: V2 is the default surface, V1 is retained for the capabilities that exist nowhere else.

| | Base URL | Auth | Covers |
|---|---|---|---|
| **V1** | `api.transmitsms.com` | HTTP Basic (`key:secret`) | contact lists, bulk and scheduled sends, reporting, balance |
| **V2** | `api.transmitmessage.com` | `x-api-key` header, API key only | single-recipient SMS, MMS, WhatsApp, RCS, webhooks, senders |

**V2 never uses the secret. V1 always needs both.** Both hostnames are real and neither is a Kudosity domain — see Failed Approaches.

Six phases, specced in `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`. Each phase gets its plan written just before it runs; writing them all upfront goes stale.

## Completed

- [x] **Phase 1 — rebrand** (merged to local `main`). Packages `expertsystemsau/kudosity-php-client` and `kudosity-laravel-client`, namespace `ExpertSystems\Kudosity\`, config `config/kudosity.php`, env prefix `KUDOSITY_`, hook `toKudosity()`, channel string `kudosity`. Ships `bin/kudosity-codemod` + `rename-map.json` and `UPGRADING.md`.
- [x] **Phase 2 — V2 core** (merged to local `main`). `KudosityV2Connector`, `KudosityV2Request` (no body) and `KudosityV2BodyRequest` (adds the JSON body), `Concerns\UnwrapsData`, `DecodesResponses`, `HasRetryPolicy`, `KudosityException::fromV2Response()` with `NotFoundException`/`ServerException`/`ProblemIssue`, `V2PagedPaginator` + `V2CursorPaginator` behind the `PaginatesV2Pages`/`PaginatesV2Cursor` markers. The V1 `SmsResource` was **deleted**, freeing `sms()` for Phase 3.
- [x] **Phase 3 — V2 channels.** On `feat/kudosity-v2-channels`, 13 commits, **506 tests / 984 assertions** (from 322/598), PHPStan level 6 clean, Pint clean, three composer manifests valid, `php bin/kudosity-codemod packages` → 0 changes. All six tasks individually reviewed, plus a final whole-branch review that returned **merge** after one fix wave.
  - Shared: `Resources\V2Resource`, `Enums\MessageStatus` (15 cases, the union of three vocabularies), `Data\V2\SmsFallback`.
  - SMS: `POST /v2/sms`, `GET /v2/sms/{id}`, `GET /v2/sms` (paged on `smses`).
  - MMS: `POST /v2/mms`, `GET /v2/mms/{id}`. No list endpoint — `GET /v2/mms` does not exist.
  - WhatsApp: send/get/list (cursor on `data.messages`), with `Contracts\WhatsAppContent` + `TextContent`/`TemplateContent`/`CustomContent`.
  - RCS: send/get/list/capabilities, `Enums\RcsCapabilityCode`.
  - Client: `$k->sms()`, `$k->mms()`, `$k->whatsapp()`, `$k->rcs()`, plus four facade `@method` lines.
  - **The removed-symbol audit came back empty** — the first phase where it did. Phases 1 and 2 both shipped a gap it caught.
- [x] **Phase 3 live-verified against the real API.** See Verification. SMS and MMS both sent and confirmed received on an AU handset.

## Not Yet Done

- [ ] **WhatsApp and RCS are not verified end to end.** The test account has neither provisioned. Both failed cleanly with correct error mapping, but no message has ever traversed either channel. Needs a WhatsApp Business sender and a registered RCS agent ID; both need Kudosity approval and are not same-day.
- [ ] **Phase 4 — webhooks and senders.** Webhook CRUD (`POST/GET /v2/webhook`, `GET/PUT/DELETE /v2/webhook/{id}`), the ten typed inbound payload DTOs, `POST/GET /v2/senders/registrations` and verifications, `SenderStatus` with `isReadyToUse()`. **This phase unblocks the WhatsApp/RCS verification above**, because sender readiness is read via `GET /v2/senders/registrations`.
- [ ] **Phase 5 — Laravel integration.** Four notification channels (`kudosity`, `kudosity-mms`, `kudosity-whatsapp`, `kudosity-rcs`), the V2 webhook receiver route, `kudosity:webhook:*` commands, and splitting config `base_url` into a `v1`/`v2` pair.
- [ ] **Phase 6 — tests, CI, docs, release.** A standalone PHPUnit 11 suite for the client package on PHP 8.2/8.3/8.4 (see Warnings), doc finalisation, release.
- [ ] **Nothing is pushed.** `main` is local-only. `split.yml` has never fired and no release exists.

## Failed Approaches (Don't Repeat These)

Carried forward from Phases 1-2 because the fixes are not obvious from the final code:

**A brand sweep using `\btransmitsms\b` silently corrupted the V1 hostname.** In `api.transmitsms.com` the dots are word boundaries, so `\b` matched *inside* the hostname and rewrote `KudosityV1Connector::BASE_URL` to a non-existent `https://api.kudosity.com`. The grep policing the sweep could not see it, because once corrupted the line no longer contains the searched string. **Any future sweep needs a negative check for the corrupted form, not only the original.** Exclude `docs/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod`, `tests/Unit/CodemodTest.php` — and now also **`HANDOFF.md` and `.ai-skills/handoffs/*`**, which legitimately contain the old brand and currently produce 6 non-defect hits.

**A `\->sms\(\)` sweep cannot see a static facade call.** Three rounds reported "clean" while `README.md:193` held `Kudosity::sms()`. Use `'\->sms\(\)|::sms\(\)|\bSmsResource\b'`, and never accept the word "clean" — ask for the literal output.

**Removed public symbols went missing from `rename-map.json` in both Phases 1 and 2**, caught only at whole-branch review. The gap is structural: removals land in one task, the map in another. Phase 3's Task 6 audit is why this phase came back empty — keep it.

**`removed` map entries cannot flag a class.** The flagger appends `(` before matching, so a `removed` entry matches `new Foo(` but never a `use`, a `::class`, or a type hint. A removed **class** goes in `classes` with a real target; only methods belong in `removed`.

**Symlinking `vendor/` into a git worktree does not work here.** Composer's `autoload_psr4.php` resolves `$baseDir` back through the symlink, so `vendor/bin/pest` runs the tracked tree's code and a deliberate regression appears to pass. Phase 3 therefore worked on a branch in the primary checkout, not a worktree.

New from Phase 3:

**Regexes anchored `'/^…$/'` without the `D` modifier have a newline hole.** PCRE's `$` also matches immediately before a final newline, so `TemplateContent` shipped with `'/^[a-z0-9_]+$/'` accepting `"order_update\n"` — the newline went on the wire, past the one rule that class exists to enforce. Fixed with `/D` plus a trailing-newline test. Note the follow-up below is **one** real instance, not two.

**Three tests that could not fail shipped inside this phase and were caught in review.** One named "casts its string totals" never asserted the casts; one asserted only the exception type so deleting a dedicated guard let the regex path satisfy it; one deliberate `'' → null` normalisation had no assertion at all despite the fixture setting up the exact case. **The discipline that catches them:** revert the behaviour, watch the test fail, restore, and put that transcript in the report. Two corollaries — give each validation rule its own **single-violation** input, and assert on something **only** that rule produces (an `errorCode` plus a distinctive fragment), because a fragment shared with another error will not notice when the wrong error fires.

**The plan's predicted test counts were wrong from Task 1 onward.** Its "14 from the dataset plus 7" double-counted the dataset's own `it()` block, shifting every later prediction by one. Implementers must transcribe test content verbatim and never invent or drop a test to hit a predicted number.

## Key Decisions

| Decision | Rationale |
|---|---|
| Dual-API, V2 primary | V2 has no equivalent for contact lists, bulk/multi-recipient sends, scheduling, reporting or balance. |
| API hostnames keep their real values | Kudosity renamed neither `api.transmitsms.com` nor `api.transmitmessage.com`. Both must survive every sweep. |
| `sms()` is V2 single-recipient only | `POST /v2/sms` takes exactly one recipient and cannot schedule; multi-recipient/list/scheduled stay on `bulk()`. |
| GET readers extend `KudosityV2Request`, writers `KudosityV2BodyRequest` | A body on a GET — even `[]` — is stripped or rejected by some gateways. |
| Payload resolution lives on the request, not the DTO | `UnwrapsData` sits on the request base; `createDtoFromResponse()` calls `static::payload($response)`. |
| One `MessageStatus` enum as the union of three vocabularies | The lists disagree and will grow; everything resolves through `fromApi()` to `Unknown` rather than throwing. |
| **`ListSmsV2Request` guards the status filter to its documented 13** | The enum is a union, so `?MessageStatus` offered 15 where 2 are invalid for `GET /v2/sms`. An unsupported query param is silently ignored, so a caller would iterate the whole history believing it filtered. |
| **Undocumented SMS date filters removed** | The plan contradicted itself; neither the vendored skill nor the spec documents any date filter for `GET /v2/sms`. Inferred wire names + silent ignoring = results that look filtered and are not. |
| **WhatsApp/RCS recipients: normalise punctuation, never guess a country** | A country default would silently prepend `61` to a number typed for another country — a wrong send, not a failed one. A local-form number gets a loud 400 instead. |
| **Non-string WhatsApp template parameters rejected, not stringified** | Parameters are positional strings upstream; silently stringifying an object is the silent-wrong class this phase exists to prevent. `TemplateContent`'s `@param` stays `array<int, mixed>` deliberately, or the runtime `is_string()` guard becomes unreachable to PHPStan. |
| **`custom_date` pairing enforced in both directions** | Dates without `custom_date` would be silently ignored by the API. Same reasoning as the SMS date filters. |
| **`SmsFallback::fromResponse()` is the single shared read path** | The constructor rejects an empty message — right for a request object, wrong while reading a response. WhatsApp and RCS share one guard rather than writing it twice; the constructor invariant is untouched. |
| **`rename-map.json`'s `"sms": null` flag kept** | `sms()` returns with *different* semantics, so a 1.x `$client->sms()->send($msg, '614...,614...')` would silently send to a comma-joined string as one recipient instead of failing. A loud break turning silent is strictly worse. |
| PHP floor stays `^8.2` | User's call, despite the dev toolchain needing 8.3+. |

## Current State

**Working.** `feat/kudosity-v2-channels` at `43ee40b`, tree clean, 13 commits ahead of `main`. Final whole-branch review returned **merge**. SMS and MMS verified against the live API.

**Broken.** Nothing.

**Uncommitted changes.** None.

## Verification

All run this session at `43ee40b`:

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
| V1 auth — `$k->account()->getBalance()` | OK, 529.77 AUD (proves HTTP Basic) |
| V2 auth — `GET /v2/sms` | OK, read real history (proves `x-api-key`, paginator, envelope) |
| SMS send → handset | **delivered and confirmed received** |
| `GET /v2/sms/{id}` read-back | OK after a short delay — see Warnings |
| `GET /v2/sms` filtered by `message_ref` | OK |
| MMS send with attachment → handset | **delivered and confirmed received** |
| `ListSmsV2Request` status guard, live | rejected `MessageStatus::Queued` before any request |
| RCS agent-ID guard, live | rejected a phone-number-shaped sender locally |
| WhatsApp send | `ValidationException`, `getIssues()` → `[{"name":"account_id","message":"no WhatsApp sender found for this account"}]` — **not provisioned** |
| RCS capabilities | `AccessDeniedException: sender is not owned by this account` — **no agent provisioned** |

Casts confirmed live: `sms_count` arrives as the string `"1"` and casts to `int`; `routed_via` arrives as `""` and normalises to `null`; the nine-fractional-digit `created_at` parses; MMS returns a single `country` field, not SMS's pair.

## Files to Know

| File | Why It Matters |
|---|---|
| `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md` | The six-phase design. Sections 2-3 authoritative for the V2 surface. `/docs` is gitignored — force-added. |
| `docs/superpowers/plans/2026-08-05-kudosity-phase-3-v2-channels.md` | Phase 3's plan. Read its Global Constraints before writing Phase 4's. |
| `.agents/skills/kudosity-*/SKILL.md` | Vendored upstream API references — the authoritative per-endpoint contract. **Read the relevant one before writing any request class.** Never modify. `kudosity-webhooks` is the one Phase 4 needs. |
| `packages/kudosity-client/src/Resources/V2Resource.php` | Base for V2 resources: `sendAndDto()`, `paginate()`. Phase 4's webhook/sender resources extend it. |
| `packages/kudosity-client/src/Requests/V2/` | The twelve Phase 3 requests — the shape Phase 4 copies. |
| `packages/kudosity-client/src/Data/V2/SmsFallback.php` | Note the constructor-vs-`fromResponse()` split; the pattern to reuse for any request-shaped object read back from a response. |
| `rename-map.json` | The consumer migration contract. Phases 1 and 2 both shipped a gap here. |
| `.github/workflows/split.yml` | **Must keep `actions/checkout@v4`** — see Warnings. |

## Code Context

```php
$k = new KudosityClient(apiKey: '…', apiSecret: '…');  // secret optional — omit for V2-only

// V2 channels (Phase 3)
$k->sms()->send('Hi', to: '61478038915', from: '61481074185');   // exactly ONE recipient
$k->sms()->get($id);
$k->sms()->list(status: MessageStatus::Delivered);                // V2PagedPaginator
$k->mms()->send(to: '614…', from: '614…', contentUrls: ['https://…/i.png'], subject: 'Max 20 ASCII');
$k->whatsapp()->text('Hi', to: '614…');
$k->whatsapp()->template('order_update', ['ACME', '12345'], to: '614…');
$k->whatsapp()->custom($payload, to: '614…');
$k->rcs()->capabilities(['614…'], agentId: 'DemoSender');         // per agent, ≤100 numbers
$k->rcs()->send('Hi', to: '614…', agentId: 'DemoSender', fallback: new SmsFallback('SMS body'));

// V1, because V2 has no equivalent
$k->bulk()->send($msg, $upTo500CommaSeparated);
$k->bulk()->sendToList($msg, listId: 4213644);
$k->bulk()->schedule($msg, $to, at: $when);
$k->reporting(); $k->numbers(); $k->lists(); $k->keywords(); $k->account(); $k->emailSms();
```

**Envelope asymmetry, the most common way to misread this API:** SMS and MMS return their object **flat**. WhatsApp, RCS, RCS capabilities and sender registrations **wrap it** as `{"data": {...}}`. `static::payload($response)` resolves both. Both wrapped channels have a test planting a *decoy* top-level `id`, so a wrong read resolves the wrong message rather than null.

**String-typed numerics:** `sms_count`, `total_records`, `total_segments` arrive as strings. `"1" + 1` is `"11"`.

### What Phase 4 needs to know about V2 webhooks

Architecturally different from V1's per-send `onDlr`/`onReply`/`onLinkHit` callbacks. V2 webhooks are **account-level resources managed over the API**: `POST /v2/webhook`, `GET /v2/webhook`, `GET/PUT/DELETE /v2/webhook/{id}`, `x-api-key` auth. One webhook can serve every channel.

**Ten event types:** `SMS_STATUS`, `SMS_INBOUND`, `MMS_STATUS`, `MMS_INBOUND`, `WHATSAPP_STATUS`, `WHATSAPP_INBOUND`, `RCS_STATUS`, `RCS_INBOUND`, `LINK_HIT`, `OPT_OUT`. Optional `filter` (`event_type`, `sender`, `status`, `message_ref`, `campaign_id`) and `rate_limit` (max 10,000/s, 0 = default). Filter logic: OR within an array, AND between filters.

Four traps for the plan:
- **`SMS_STATUS` does not report WhatsApp or RCS** — those need their own event types.
- **For inbound events, `sender` is matched against the `recipient` field** — filtering inbound by `sender` filters by *your* number, not the customer's.
- **`ACCEPTED` is not `DELIVERED`** — the documented cause of over-reported success rates, and why `MessageStatus::isDelivered()` excludes it.
- **`READ` is RCS only.**
- Consumer consequence: per-send callback URLs do not carry over, so Phase 5's Laravel signed-URL receiver needs redesigning rather than porting.

## Resume Instructions

1. Confirm the baseline: `vendor/bin/pest --compact && vendor/bin/phpstan analyse --no-progress`
   - Expected: `506 passed (984 assertions)` and `[OK] No errors`.
   - If different: check `git log` — something moved since this handoff.
2. Write the Phase 4 plan at `docs/superpowers/plans/<date>-kudosity-phase-4-webhooks-senders.md`, modelled on Phase 3's. **Read `.agents/skills/kudosity-webhooks/SKILL.md` first**, and carry the four traps above into it.
   - Include a Task-N removed-symbol audit step. Phase 3's is why the audit came back empty.
   - Correct the plan's own arithmetic habit: do not predict test counts, or predict them as "at least N".
3. Execute it with `superpowers:subagent-driven-development`, on a branch in the primary checkout — **not** a git worktree (see Failed Approaches).
4. Once sender registrations exist, **finish the WhatsApp and RCS live verification** that Phase 3 could not: provision a WhatsApp Business sender and an RCS agent ID, then send one message on each.
   - Expected: a real send returning a wrapped `data` envelope; `RcsCapabilityCode::isReachable()` true for `ENABLED`.

## Setup Required

- PHP 8.3 or 8.4 to run the dev toolchain (Pest 4 requires `^8.3.0`), even though the packages declare `^8.2`.
- `composer install` at the repo root. No services or ports; every test uses Saloon's `MockClient`.
- For live testing: `KUDOSITY_API_KEY` (all four V2 channels) and `KUDOSITY_API_SECRET` (V1 only). See `packages/kudosity-laravel/config/kudosity.php` for the full key list. **No credential values are recorded here or anywhere in the repo.**
- A sender per channel: a number or approved alphanumeric ID (SMS/MMS), a WhatsApp Business number (or omit for the account default), a registered RCS **agent ID**. Alphanumeric IDs and RCS agents need Kudosity approval and are not instant.

## Edge Cases & Error Handling

- **Handled:** both V2 envelope shapes; RFC 9457 errors with per-field `issues[]` (verified live); a plain-string `{"error": "..."}` body; a non-JSON error body (so a proxy's HTML 502 yields `ServerException`, not `JsonException`); the missing-secret case; all documented paginator termination signals.
- **Deferred:** `paginationDirection()` is forwarded into the query unvalidated. Both V2 paginators' `getPageItems()` declare `array<int, mixed>` but return `mixed`, so a malformed page throws `TypeError` inside iteration — matches the V1 paginator.
- The full deferred list, plus the Phase 3 follow-ups, lives in `~/.claude/projects/-home-mitchell-projects-transmitsms-php-sdk/memory/transmitsms-sdk-followups.md`.

## Warnings

- **`GET /v2/sms/{id}` 404s for a few seconds after `POST /v2/sms`.** Read-after-write lag, **not** a broken reader — proven by reading an older id successfully in the same run and the new id fine moments later. Do not "fix" the reader; retry or poll instead.
- **The same message's status comes back `DELIVERED` from `GET /v2/sms/{id}` but `delivered` from `GET /v2/sms`.** `MessageStatus::fromApi()`'s case-insensitivity is load-bearing.
- **`.github/workflows/split.yml` must keep `actions/checkout@v4`.** `claudiodekker/splitsh-action@v1.0.0` runs `git config --local --unset-all http.https://github.com/.extraheader` under `set -e`; checkout v5+ stores the token via an includeIf file, so the unset hits a missing key (exit 5) and aborts the split *before any push*. Do not "fix the drift".
- **Release tags must be `v`-prefixed** (`v2.0.0`). Tag `1.7.0` was cut without it and never released.
- **`bin/kudosity-codemod` never reaches consumers via composer** — `split.yml` only splits `packages/*`. `UPGRADING.md` documents a direct fetch.
- **PHP 8.2 is declared but untested.** Pest 4 needs `^8.3.0`. Phase 6 closes this with a standalone PHPUnit 11 suite on 8.2/8.3/8.4.
- **`withRetry()` does not actually retry HTTP failures** on either connector — a 502 under `withRetry(tries: 3)` makes one attempt. Docblocks were corrected; the mechanism predates 2.0 and is deliberately untouched.
- **The `"sms": null` rename-map flag looks redundant after Phase 3 and is not.** See Key Decisions.
- **PHPStan hard-errors on a trait with zero consumers** (`trait.unused`). That is why `KudosityV2Request` uses `UnwrapsData` — a real constraint, not style.
- **`Support/PhoneNumber.php:234`** — `'/^[a-zA-Z0-9]+$/'` lacks `/D`, so a sender ID with a trailing newline passes validation. Real latent defect, pre-existing V1 code, deliberately out of Phase 3's scope. Its sibling at `:251` is **not** affected — `\s` already absorbs the newline inside that character class. One real fix, not two.
- **Collapse the duplicated `date_range` block between `ListWhatsAppRequest` and `ListRcsRequest` before Phase 4 adds a third cursor list.** Two verbatim copies of one documented coupling rule, with 8 duplicated tests each.
- **Release checklist before tagging** (dashboard work, in `UPGRADING.md`): rename the GitHub monorepo to `kudosity-php-sdk`, create the two split-target repos, register both on Packagist, mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned pointing at the replacements, then tag.
