---
handoff_version: "1"
source: xsys-handoff
mode: full
generated_at: "2026-08-05T17:31+10:00"
title: "Kudosity 2.0 migration — Phase 3 of 6 ready to execute"
status: in-progress
branch: "main"
head_sha: "2aada949038b756d6a0c706260e09661831254b2"
dirty_files: 0
diff_digest: "clean"
ticket_key: "none"
repo: "transmitsms-php-sdk"
submodules: []
next_step: "Execute docs/superpowers/plans/2026-08-05-kudosity-phase-3-v2-channels.md via superpowers:subagent-driven-development, starting with Task 1"
---

# Handoff: Kudosity 2.0 migration, Phase 3 of 6

## Goal

Upgrade this PHP SDK from the TransmitSMS V1 API to Kudosity's V2 API, shipping as a 2.0. Kudosity runs **two APIs under one account** and V2 cannot replace V1, so the SDK is dual-API: V2 is the default surface, V1 is retained for the capabilities that exist nowhere else.

| | Base URL | Auth | Covers |
|---|---|---|---|
| **V1** | `api.transmitsms.com` | HTTP Basic (`key:secret`) | contact lists, bulk and scheduled sends, reporting, balance |
| **V2** | `api.transmitmessage.com` | header `x-api-key: {key}` | single-recipient SMS, MMS, WhatsApp, RCS, webhooks, senders |

**V2 never uses the secret. V1 always needs both.**

The migration is six phases, specced in `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`. Each phase gets its own plan document written just before it runs — writing them all upfront goes stale.

## Completed

- [x] **Phase 1 — rebrand** (merged to local `main`). Packages renamed to `expertsystemsau/kudosity-php-client` and `kudosity-laravel-client`, namespace `ExpertSystems\Kudosity\`, config `config/kudosity.php`, env prefix `KUDOSITY_`, hook `toKudosity()`, channel string `kudosity`. Shipped `bin/kudosity-codemod` + `rename-map.json` so consuming projects follow in one command, plus `UPGRADING.md`.
- [x] **Phase 2 — V2 core** (merged to local `main`). `KudosityV2Connector`, `KudosityV2Request` (no body) and `KudosityV2BodyRequest` (adds `HasBody`/`HasJsonBody`), `Concerns\UnwrapsData`, `Concerns\DecodesResponses`, `Concerns\HasRetryPolicy`, `KudosityException::fromV2Response()` with `NotFoundException`/`ServerException`/`ProblemIssue`, `V2PagedPaginator` + `V2CursorPaginator` behind the `PaginatesV2Pages`/`PaginatesV2Cursor` marker contracts. The V1 `SmsResource` was **deleted** — its members redistributed to `bulk()`, `reporting()` and `numbers()` — freeing `sms()` for Phase 3. `KudosityClient` now holds both connectors via `v1()`/`v2()`.
- [x] **Phase 3 plan written and committed** at `docs/superpowers/plans/2026-08-05-kudosity-phase-3-v2-channels.md` (commit `2aada94`).
- [x] Both phases passed a whole-branch review on Opus, with all findings fixed and re-reviewed.

## Not Yet Done

- [ ] **Phase 3 — V2 channels.** Six tasks: shared foundations (`V2Resource` base, `MessageStatus` enum, `SmsFallback`), then SMS, MMS, WhatsApp, RCS, then client wiring. Plan is committed and ready.
- [ ] **Phase 4 — webhooks and senders.** Webhook CRUD (`POST/GET /v2/webhook`, `GET/PUT/DELETE /v2/webhook/{id}`), the ten typed inbound payload DTOs, `POST/GET /v2/senders/registrations` and verifications, `SenderStatus` with `isReadyToUse()`.
- [ ] **Phase 5 — Laravel integration.** Four notification channels (`kudosity`, `kudosity-mms`, `kudosity-whatsapp`, `kudosity-rcs`), the V2 webhook receiver route, `kudosity:webhook:*` artisan commands, and splitting config `base_url` into a `v1`/`v2` pair.
- [ ] **Phase 6 — tests, CI, docs, release.** A real PHPUnit 11 suite for the client package run standalone on PHP 8.2/8.3/8.4 (see Warnings), README/UPGRADING/CHANGELOG finalisation, and the release.
- [ ] **Nothing is pushed.** `main` is local-only, 35 commits ahead of `origin/main`. `split.yml` has never fired and no release exists.

## Failed Approaches (Don't Repeat These)

Every one of these is a defect that shipped into a commit and had to be corrected. They are recorded because the fix is not obvious from the final code.

**A brand sweep using `\btransmitsms\b` silently corrupted the V1 hostname.** In `api.transmitsms.com` the dots are word boundaries, so `\b` matched *inside* the hostname and rewrote `KudosityV1Connector::BASE_URL` to a non-existent `https://api.kudosity.com`. The grep meant to police the sweep could not see it, because once corrupted the line no longer contains the string being searched for. Fixed in `6de26d5` with three regression tests asserting the literal. **Any future brand sweep needs a negative check for the corrupted form, not only the original.** Also exclude `docs/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod` and `tests/Unit/CodemodTest.php` — all legitimately contain old-brand strings.

**A `\->sms\(\)` sweep pattern cannot see a static facade call.** Three consecutive rounds reported "clean" while `README.md:193` still held `Kudosity::sms()->send(...)`, because the pattern only matches instance calls. Use `'\->sms\(\)|::sms\(\)|\bSmsResource\b'`, and never accept the word "clean" — ask for the literal command output.

**Removed public symbols went missing from `rename-map.json` in both phases, and the whole-branch review caught it both times.** Phase 1 omitted Task 2's removals (`BASE_URL_SMS`, `BASE_URL_MMS`, `useSmsUrl`, `useMmsUrl`), so the codemod emitted code that fataled on undefined constants and methods. Phase 2 omitted the *deleted class* `SmsResource`, so the map's namespace-prefix rewrite turned a consumer's `use ExpertSystems\TransmitSms\Resources\SmsResource;` into a non-existent `Kudosity\Resources\SmsResource` — silently, reporting success. The gap is structural: removals land in one task and the map in another, so per-task review cannot see it. **Phase 3's Task 6 has a mandatory audit step for exactly this.**

**`removed` map entries cannot flag a class.** The flagger appends `(` before matching, so a `removed` entry matches `new Foo(` but never a `use` statement, a `::class`, or a type hint. A removed **class** must go in `classes` with a real target; only methods belong in `removed`. Discovered while fixing the `SmsResource` gap above.

**The codemod rewrote its own map into no-op pairs.** `UPGRADING.md` told consumers to place `rename-map.json` in their project, so the file iterator picked it up; after `--write` the map read `"KudosityV1Connector": "KudosityV1Connector"` and every subsequent run silently did nothing. Fixed by skipping the resolved map path and `__FILE__` in the planning loop. The same failure had already been diagnosed for this repo's own docs in `2df8933` and simply was not carried across to the consumer copy.

**The codemod's preserve guard was not atomic.** On `--write` it wrote files as it walked, so a later file tripping the guard left earlier files already mutated while the process exited 1 as if it had refused. Restructured to two passes — plan and guard-check everything, then write only if all pass. The test that pins this asserts a *sibling* file is also unmodified; without that assertion it would pass against the old design.

**`json_encode` escapes forward slashes, so a composer.json rename was a silent no-op.** `expertsystemsau/kudosity-php-client` is stored as `expertsystemsau\/kudosity-php-client`, so the plain `str_replace` never matched. The codemod now also matches the escaped form.

**Two paginator bugs that single-page tests could not catch.** Saloon's `next()` increments `$currentPage` *before* `valid()` calls `isLastPage()`, so an extra `+1` in `isLastPage()` stopped pagination a page early on every multi-page walk. And a test-helper named `smsPage()` collided with an existing global of the same name but a different signature in `tests/Unit/PaginationTest.php` — invisible when running the file alone, a fatal on a full-suite run.

**Tests that could not fail.** Three shipped and were caught in review: a configure-closure test asserting `validity(60)`, a field `applyDefaults()` never touches, so it passed regardless of the ordering it claimed to test; a reflection test denying two hardcoded names (`apiSecret`, `secret`) rather than asserting an allow-list, so `$apiToken` would sail past; and a derived-connector test asserting only the API key, so a future "fix" copying the V1 base URL into the derived V2 connector would pass. **The discipline that now catches these: make the regression, watch the test fail, restore, and put the transcript in the report.**

**Symlinking `vendor/` into a git worktree does not work for testing local source edits here.** Composer's `autoload_psr4.php` computes `$baseDir` such that it resolves back through the symlink to the original checkout, so `vendor/bin/pest` silently runs the tracked tree's code rather than the worktree's edit — a deliberate regression appears to pass. Workaround: `require_once` the source files directly before `vendor/autoload.php`.

## Key Decisions

| Decision | Rationale |
|---|---|
| Dual-API, V2 primary | V2 has no equivalent for contact lists, bulk/multi-recipient sends, scheduling, reporting or balance. Kudosity's own guidance is "start on V2 unless you need multi-recipient sends or custom link domains". |
| Full rebrand to Kudosity, no BC aliases | User's call. Only internal projects consume this, so a clean break plus a codemod beat carrying shims. |
| API hostnames keep their real values | Kudosity did **not** rename `api.transmitsms.com` or `api.transmitmessage.com`. Both must survive every sweep. |
| Resources named by capability, not version | `bulk()`, `lists()`, `reporting()` rather than `v1()->sms()`. Reads as what it does; V1 appears only where the capability exists nowhere else. |
| `sms()` reserved for V2 single-recipient | V2's `POST /v2/sms` takes exactly one recipient and cannot schedule, so multi-recipient/list/scheduled sends stay on `bulk()`. |
| Payload resolution lives on the request, not the DTO | `UnwrapsData` sits on `KudosityV2Request`, so `createDtoFromResponse()` calls `static::payload($response)`. The spec originally assumed DTO factories; this is the settled placement. |
| GET readers extend `KudosityV2Request`, writers extend `KudosityV2BodyRequest` | A body on a GET — even `[]` — is stripped or rejected by some proxies and gateways. All five V2 readers are GETs. |
| Both 400 and 422 map to `ValidationException` | Kudosity's error registry documents validation as 422; the RCS and WhatsApp endpoint pages show 400 for the same condition. The docs contradict each other, so both are handled. |
| One `MessageStatus` enum as the union of three vocabularies | The `GET /v2/sms` filter documents 13 values, webhook events 8, WhatsApp/RCS lists 5 including `QUEUED` which appears in neither. All resolve through `fromApi()` to `Unknown` rather than throwing, because the docs say the lists will grow. |
| PHP floor stays `^8.2` | User's call, despite the dev toolchain needing 8.3+. See Warnings. |
| Phase plans written one at a time | Writing all six upfront produces documents that are stale by execution time. |

## Current State

**Working.** `main` at `2aada94`, working tree clean, 35 commits ahead of `origin/main` (unpushed).

**Broken.** Nothing.

**Uncommitted changes.** None.

## Verification

All run this session, at `2aada94`:

| Command | Result |
|---|---|
| `vendor/bin/pest --compact` | 322 passed (598 assertions) |
| `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` (level 6) |
| `vendor/bin/pint --test` | passed |
| `composer validate --strict` (root + both packages) | all three valid |
| `php bin/kudosity-codemod packages` | 0 files would change |
| branding sweep (see Failed Approaches for the pattern) | clean |
| `composer test-coverage` | not run this session |

## Files to Know

| File | Why It Matters |
|---|---|
| `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md` | The six-phase design. Sections 2 and 3 are authoritative for the V2 surface. Note `/docs` is gitignored — these are force-added. |
| `docs/superpowers/plans/2026-08-05-kudosity-phase-3-v2-channels.md` | **The next thing to execute.** Six tasks, ready. |
| `.agents/skills/kudosity-*/SKILL.md` | Vendored upstream API references — the authoritative per-endpoint contract. **Read the relevant one before writing any request class.** Never modify them. |
| `rename-map.json` | The consumer migration contract. Both phases' final reviews caught a gap here; see Failed Approaches. |
| `bin/kudosity-codemod` | Ships to consumers to replay the rename. Two-pass with an atomic preserve guard. |
| `UPGRADING.md` | The 2.0 upgrade guide, including the resource-surface table. |
| `packages/kudosity-client/src/Requests/KudosityV2Request.php` | The no-body base for GET readers; carries `UnwrapsData`. |
| `packages/kudosity-client/src/Requests/KudosityV2BodyRequest.php` | The body-carrying base for writers. |
| `packages/kudosity-client/src/Exceptions/KudosityException.php` | Both error factories, `fromV1Response()` and `fromV2Response()`. |
| `.github/workflows/split.yml` | **Must keep `actions/checkout@v4`** — see Warnings. |

## Code Context

```php
// The client surface as of Phase 2. Phase 3 adds sms(), mms(), whatsapp(), rcs().
$k = new KudosityClient(apiKey: '…', apiSecret: '…');   // secret optional — omit for V2-only

$k->v1();  // KudosityV1Connector  — api.transmitsms.com, Basic auth
$k->v2();  // KudosityV2Connector  — api.transmitmessage.com, x-api-key

// V1, because V2 has no equivalent
$k->bulk()->send($msg, $upTo500CommaSeparated);
$k->bulk()->sendToList($msg, listId: 4213644);
$k->bulk()->schedule($msg, $to, at: $when);
$k->reporting()->getResponses($id);
$k->numbers()->formatNumber($n);
$k->lists(); $k->keywords(); $k->account(); $k->emailSms();
```

```php
// What a Phase 3 request looks like. Writers extend the body base:
class SendSmsV2Request extends KudosityV2BodyRequest
{
    public function resolveEndpoint(): string { return '/v2/sms'; }
    protected function defaultBody(): array { /* message, sender, recipient */ }
    public function createDtoFromResponse(Response $r): SmsMessageData
    {
        // payload() from UnwrapsData resolves both envelope shapes
        return SmsMessageData::fromArray(static::payload($r));
    }
}
```

**The envelope asymmetry, which is the most common way to misread this API:** SMS and MMS return their object **flat**. WhatsApp, RCS, RCS capabilities and sender registrations **wrap it** as `{"data": {...}, "request": {}, "meta": {}}`. `static::payload($response)` resolves both.

**String-typed numerics:** `sms_count`, `total_records` and `total_segments` arrive as strings (`"1"`, not `1`). Cast before arithmetic — `"1" + 1` gives `"11"`.

## Resume Instructions

1. Read `docs/superpowers/plans/2026-08-05-kudosity-phase-3-v2-channels.md` once, noting its Global Constraints.
2. Cut the branch: `git checkout -b feat/kudosity-v2-channels`
   - Expected: branched from `main` at `2aada94`.
3. Confirm the baseline before changing anything: `vendor/bin/pest --compact && vendor/bin/phpstan analyse --no-progress`
   - Expected: `322 passed (598 assertions)` and `[OK] No errors`.
   - If different: something has moved since this handoff — check `git log` before proceeding.
4. Execute the plan task-by-task using `superpowers:subagent-driven-development`. It creates a ledger at `.superpowers/sdd/2026-08-05-kudosity-phase-3-v2-channels/progress.md`; that ledger is the recovery map if context runs out again.
   - Expected per task: green suite, PHPStan clean, Pint clean, then a task review before moving on.
5. **Do not skip Task 6's removed-symbol audit.** It exists because the same defect shipped in both prior phases; see Failed Approaches.
6. Task 6 also carries a judgement call left open on purpose: whether to keep `rename-map.json`'s `"sms": null` flag now that `sms()` returns with different semantics. The plan argues for keeping it — see Warnings. Argue rather than silently reverse.

## Setup Required

- PHP 8.3 or 8.4 to run the dev toolchain (Pest 4 requires `^8.3.0`), even though the packages declare `^8.2`.
- `composer install` at the repo root. No services, ports, or external credentials are needed — every test uses Saloon's `MockClient`.
- A `.env` exists at the repo root but no test reads it. Real Kudosity credentials would be `KUDOSITY_API_KEY` and `KUDOSITY_API_SECRET`; see `packages/kudosity-laravel/config/kudosity.php` for the full key list. No values are recorded here.

## Edge Cases & Error Handling

- **Handled:** both V2 envelope shapes; RFC 9457 errors with per-field `issues[]`; a plain-string `{"error": "..."}` body; a non-JSON error body (guarded by `Concerns\DecodesResponses`, so a proxy's HTML 502 yields `ServerException` rather than `JsonException`); the missing-secret case (`KudosityV1Connector::defaultAuth()` throws with an explanation rather than letting a 401 surface); all documented paginator termination signals.
- **Deferred:** `paginationDirection()` is forwarded into the query unvalidated, and `PaginatesV2Cursor` advertises a `prev` direction that `V2CursorPaginator` never implements — it only ever reads `has_next`/`next_cursor`. Phase 3 should either restrict the docblock or implement `has_prev`/`prev_cursor` when a real endpoint pins the semantics.
- **Deferred:** both V2 paginators' `getPageItems()` declare `array<int, mixed>` but return `mixed`, so a malformed page whose items key holds a scalar throws `TypeError` inside iteration. Matches the existing V1 paginator.
- The full deferred list lives in the memory file at `~/.claude/projects/-home-mitchell-projects-transmitsms-php-sdk/memory/transmitsms-sdk-followups.md`.

## Warnings

- **`.github/workflows/split.yml` must keep `actions/checkout@v4`.** `claudiodekker/splitsh-action@v1.0.0` runs `git config --local --unset-all http.https://github.com/.extraheader` under `set -e`; checkout v5+ stores the token via an includeIf credentials file, so the unset hits a missing key (exit 5) and aborts the split *before any push* — the release silently fails at the Split step. A comment in the file explains this. Do not "fix the drift".
- **Release tags must be `v`-prefixed** (`v2.0.0`). Tag `1.7.0` was cut without it and never released.
- **`bin/kudosity-codemod` never reaches consumers via composer** — `split.yml` only splits `packages/*`, so root-level `bin/` is unpublished. `UPGRADING.md` documents a direct fetch instead.
- **PHP 8.2 is declared but untested.** Pest 4 needs `^8.3.0` and PHPUnit 12 needs `>=8.3`, so nothing currently proves 8.2 works. Phase 6 closes this by giving the client package its own PHPUnit 11 suite run standalone on 8.2/8.3/8.4.
- **`withRetry()` does not actually retry HTTP failures** on either connector — a 502 under `withRetry(tries: 3)` makes one attempt. The docblocks were corrected to stop claiming otherwise. The mechanism predates 2.0 and fixing it has its own blast radius; it is deliberately untouched.
- **The `"sms": null` rename-map flag looks redundant after Phase 3 and is not.** Phase 2 added it because `sms()` was deleted. Phase 3 re-adds `sms()` with *different semantics* — V1's took up to 500 comma-separated recipients and could schedule; V2's takes exactly one and cannot. So a 1.x consumer calling `$client->sms()->send($msg, '614...,614...')` would silently send to a comma-joined string as a single recipient instead of failing. That is a loud break turning silent, which is strictly worse. Keep the flag.
- **PHPStan hard-errors on a trait with zero consumers** (`trait.unused`). That is why `KudosityV2Request` uses `UnwrapsData` — it is a real constraint, not a style choice.
- **Release checklist before tagging** (dashboard work, in `UPGRADING.md`): rename the GitHub monorepo to `kudosity-php-sdk`, create the two new split-target repos, register both on Packagist, mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned pointing at the replacements, then tag.
