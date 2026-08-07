---
handoff_version: "1"
source: xsys-handoff
mode: full
generated_at: "2026-08-06T22:25+10:00"
title: "Kudosity 2.0 — Phase 6 in flight: client test suite on the 8.2 floor"
status: in-progress
branch: "feat/kudosity-phase-6"
head_sha: "6d46a1a26307085895b66abea5dc7df40002e3f9"
dirty_files: 0
diff_digest: "clean"
ticket_key: "none"
repo: "transmitsms-php-sdk"
submodules: []
next_step: "Execute Task 5 of docs/superpowers/plans/2026-08-06-kudosity-phase-6-tests-ci-release.md — paginators and enum tolerance"
---

# Handoff: Kudosity 2.0 — Phase 6, tasks 1-4 of 9 done

## Goal

Ship this dual-API SDK as 2.0. **Phases 1–5 are merged on `main`.** Phase 6 is the last one:
give the client package real coverage of its own, running on the PHP 8.2 floor it declares,
then release.

| | Base URL | Auth | Covers |
|---|---|---|---|
| **V1** | `api.transmitsms.com` | HTTP Basic, key **and** secret | contact lists, bulk and scheduled sends, reporting, balance |
| **V2** | `api.transmitmessage.com` | `x-api-key`, key only | single-recipient SMS, MMS, WhatsApp, RCS, webhooks, senders |

Both hostnames are real; neither is a Kudosity domain. Spec:
`docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`, section 6.
**Plan being executed: `docs/superpowers/plans/2026-08-06-kudosity-phase-6-tests-ci-release.md`.**

## Completed

- [x] **Phases 1–5 merged to `main` at `21f1ef9`**, all four CI checks green including
      `Split Monorepo`, which publishes to both new package repos.
- [x] **Phase 5's live receiver verification passed** (2026-08-06). Seven real deliveries,
      seven typed events, account left at zero webhooks. It found and fixed a real bug —
      inbound MMS media arrives as inline base64, not URLs (`12f2689`).
- [x] **The 8.2 testing harness (`ffd2188`, on `main`).** PHPStan now analyses
      `phpVersion: {min: 80200, max: 80499}`; the client package has a PHPUnit 11 suite run
      standalone; CI has a `test-client` job on 8.2/8.3/8.4 that fails if a framework appears
      in the client's dependency tree. **All three jobs green, including P8.2.**
- [x] **Phase 6 Task 1 (`ef5da30`) — fixtures moved** into
      `packages/kudosity-client/tests/Fixtures/`, the package whose API produced them and the
      only one a consumer receives. Both suites read one copy. Both loaders now throw naming
      the missing fixture.
- [x] **Phase 6 Task 2 (`1c91fec`) — webhook payloads and guards.** All ten event types onto
      four payload shapes, the correlation key at its four paths, the inbound-MMS findings,
      `StatusPrecedence` and `SignedMessageRef`. Two completeness guards.
- [x] **Phase 6 Task 3 (`1be3716`) — all 22 V2 request classes**: endpoint, method, body
      shape and every local guard. Found and closed a real gap — see Key Decisions.
- [x] **Phase 6 Task 4 (`6d46a1a`) — DTOs** across both envelope shapes, the string→int
      casts, nine-fractional-digit timestamps, an empty-payload row per DTO.
- [x] **CI green on this branch at `6d46a1a`** — `run-tests` (including all three
      `Client standalone` jobs), PHPStan and Pint.

## Not Yet Done

- [ ] **Task 5 — paginators and enum tolerance.** All three paginators including termination
      and a short final page; every tolerant enum's `Unknown` fallback. Files:
      `packages/kudosity-client/tests/PaginatorTest.php`, `EnumToleranceTest.php`.
- [ ] **Task 6 — value objects and the callback URL contract.** WhatsApp content variants,
      `SmsFallback`, `PhoneNumber`, `CallbackUrlBuilder`/`CallbackUrlParser`.
- [ ] **Task 7 — de-duplicate the two suites**, then run the removed-symbol audit and the
      codemod self-check.
- [ ] **Task 8 — docs**: `CHANGELOG.md` `Unreleased` → `2.0.0`, both READMEs, `CLAUDE.md`
      commands.
- [ ] **Task 9 — release. Needs the user.** Rename the monorepo to `kudosity-php-sdk`,
      register both packages on Packagist, mark the two `transmitsms-*` packages abandoned,
      then tag `v2.0.0`.
- [ ] **Rotate the API key and secret** — they were pasted into a chat transcript.
- [ ] **`.env`'s `KUDOSITY_FROM` is the retired virtual number.** Kudosity replaced it with
      `61491570023`; `POST /v2/sms` answers `Sender not found` for the old value.
- [ ] **`register()` and the sender SMS verification flow are still not live-verified.**
      Completing one registers a personal mobile and sends a real code to it.
- [ ] **WhatsApp and RCS remain unverified end to end.** Needs Kudosity to provision a
      WhatsApp Business sender and an RCS agent.
- [ ] **Open with Kudosity:** do they publish stable egress IP ranges for webhook deliveries?
      Referred to their product team, unanswered.

## Failed Approaches (Don't Repeat These)

**A `perl -0pi -e` mutation whose pattern contains a PHP `$variable` silently no-ops.**
`\Q…\E` stops perl treating it as a regex metacharacter but does **not** stop interpolation,
so `$this` expands to nothing and the pattern never matches. Three mutations reported clean
this way before the harness's own "did the file actually change?" check caught it. **Patch
with `php -r` and `str_replace`, and always assert the file changed.**

**The plan's Task 1 Step 6 constraint could not hold as written.** It required that no root
test file be edited, as proof the fixture move changed nothing. True for the *webhook*
fixtures, which a shared helper absorbed. False for the *sender* fixtures — that loader is
inlined in `tests/Unit/V2SendersResourceTest.php` rather than shared, so its path had to move
with the files. A path update is not a weakened assertion, but the constraint as phrased was
wrong and a future plan should say "no test's assertions may change".

**Pint's `fully_qualified_strict_types` fixer rewrites an inline `\Some\Class::class` into a
`use` import.** In `StandaloneInstallTest` that planted `use Illuminate\Support\Collection;`
into the one test asserting the package has no Laravel. It still passed — imports are lazy —
but it read as the opposite of its point. **Where a test deliberately names a class that must
not exist, write it as a string literal.**

**Two of my own expectations were wrong and corrected against the source, not the reverse.**
The link-hit fixture carries `linkhit-8842:cust-4471`, not the ref the other fixtures use;
and RCS capability codes are `ENABLED`/`UNREACHABLE`/…, not `RCS_ENABLED`. **The fixture and
the enum are the evidence.** A test written from memory and then "fixed" by changing the code
is how a suite starts asserting fiction.

**Wikimedia is not a usable MMS `content_url`.** It answers Kudosity's fetcher with
`text/plain`, and the API rejects the URL on Content-Type before it looks at the bytes. The
Phase 5 rig served the image off its own ngrok tunnel instead — which needs `return false`
in the `php -S` router script, or Laravel 404s the static file.

**`CallbackUrlParser` does not authenticate a request with no handler.** Its "events-only
mode" returns without verifying when neither `h` nor `c` is present. Correct for the V1 GET
routes; wrong for the V2 receiver, whose entire authenticity story is its unguessable URL.
The receiver requires both `s` and `h` before consulting the parser. **Do not loosen the
parser to match the controller** — Task 6 covers the parser's real contract, including that
this mode still works.

**A DTO field written from the outbound docs described nothing that arrives.**
`InboundEvent::$contentUrls` read `mo.content_urls` — the shape an outbound MMS *request*
takes. A real `MMS_INBOUND` carries the bytes inline under `mo.media[]`, so the picture
parsed cleanly, dispatched its typed event, and vanished. A silence, not an error, and
mocked tests could never have caught it because the mock inherited the code's assumption.

**A brand sweep using `\btransmitsms\b` silently corrupted the V1 hostname.** The dots in
`api.transmitsms.com` are word boundaries, so `\b` matched *inside* it. **Any sweep needs a
negative check for the corrupted `api.kudosity.com` form**, with the same exclusions as the
positive sweep.

**A Laravel `expectsOutputToContain()` assertion can pass on an incidental match.** Asserting
`all` against `kudosity:webhook:list` passed because `all` appeared elsewhere in the output —
Laravel truncates a table to terminal width, so the column under test was absent entirely.

**Symlinking `vendor/` into a git worktree does not work here.** Composer's
`autoload_psr4.php` resolves `$baseDir` back through the symlink, so `vendor/bin/pest` runs
the tracked tree's code and a deliberate regression appears to pass. **Work on a branch in
the primary checkout.**

**Do not invent a request body or DTO for an undocumented shape — ask the API instead.**
Posting `{}` returns RFC 9457 `issues[]` naming every required field.

**Do not predict exact test counts in a plan.** Phase 3's plan double-counted a dataset's own
`it()` block and every later prediction inherited the error.

## Key Decisions

| Decision | Rationale |
|---|---|
| **PHPUnit 11 for the client suite, never 12** | 12 requires PHP >= 8.3, which would silently delete 8.2 coverage while every job stayed green. |
| **The client suite runs against its own `vendor/`** | Only way to prove the package installs without Laravel; the root `composer.json` masks a stray framework dependency completely. |
| **PHPStan analyses the whole declared range** | Without `phpVersion`, it analyses against the executing PHP (8.4) and 8.3-only syntax passes review to fatal on a consumer. |
| **Fixtures live in the client package** | `split.yml` publishes only `packages/*`, so a consumer was getting a package whose fixtures sat in a repo they never see. |
| **Where a test lives is decided by which package owns the class under test** | Not by the symbols the test imports. 27 of 34 root test files use no Laravel symbol, but two test `KudosityMessage`, which is in the Laravel package — moving those on the symbol count turns the 8.2 job red with a class-not-found error that looks like a dependency problem. |
| **The RCS agent-ID guard now fires on capabilities too** | `SendRcsRequest` rejected a phone number in the agent slot; `CheckRcsCapabilitiesRequest` did not. The live API answers that mistake with "sender is not owned by this account" — true, and silent about the real error. A rule enforced on one path and not the other is worse than no rule. **The phase's only production change; additive, and the removed-symbol audit is empty.** |
| Every V2 enum is open, resolving unknowns to `Unknown` | A client reading its own history must not break because Kudosity added a value. |
| `UnknownEvent` is returned, never thrown | A receiver does not choose what it is sent; a 500 reads as a dead endpoint and earns a retry into the same 500. |
| `StatusPrecedence` is a rank, not a terminal check | `isTerminal()` is true for both `DELIVERED` and `READ`, and an RCS read receipt legitimately follows delivery. |
| `SignedMessageRef` protects correlation, not the payload | Parse from the **last** colon; real refs are composite. |
| PHP floor stays `^8.2` | User's call. This phase is what makes it true rather than aspirational. |

## Current State

**Working.** Branch `feat/kudosity-phase-6` at `6d46a1a`, pushed, clean tree, 4 commits ahead
of `main`. Tasks 1–4 of 9 complete. CI green on the branch tip, including all three
`Client standalone` jobs.

**Broken.** Nothing known.

**Uncommitted changes.** None.

## Verification

At `6d46a1a` on this branch:

| Command | Result |
|---------|--------|
| `vendor/bin/pest --compact` (root) | 844 passed (1660 assertions) — unchanged from `main` |
| `cd packages/kudosity-client && vendor/bin/phpunit` | **130 tests, 259 assertions** (was 22/43 at `ffd2188`) |
| the same on `php:8.2-cli` via Docker | 130 tests, 259 assertions |
| `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` at level 6, phpVersion 80200–80499 |
| `vendor/bin/pint --test` | passed |
| `composer validate --strict` (root, client, laravel) | all three valid |
| `php bin/kudosity-codemod packages` | 0 files would change |
| removed-symbol audit vs `main` | empty |
| CI at `6d46a1a` | `run-tests`, `PHPStan`, `Fix PHP code style issues` all SUCCESS |
| `composer test-coverage` | **not run this session** — Task 7 Step 4 needs it as the before/after measurement |

**Mutation testing: 17 mutations across Tasks 1–4, all caught.** Notable ones: `mo.media`
misread, the inbound message trimmed, `supersedes()` always true, a signed ref split on the
first colon, a dropped endpoint-table row, `rawurlencode` dropped from a path segment,
optional body keys sent as null, the `sms_count` cast dropped, `routed_via` left as the empty
string the API sends.

## Files to Know

| File | Why It Matters |
|------|----------------|
| `docs/superpowers/plans/2026-08-06-kudosity-phase-6-tests-ci-release.md` | **The plan being executed.** Tasks 5–9 remain. Its Reference section holds the ownership rule and the Docker recipe. |
| `packages/kudosity-client/phpunit.xml.dist` | The standalone suite's config. Strict flags on; `bootstrap="vendor/autoload.php"` is the package's own. |
| `packages/kudosity-client/tests/Fixtures/Fixtures.php` | The fixture loader both suites read through. |
| **`packages/kudosity-client/tests/Fixtures/V2Webhooks/README.md`** | **Read before touching anything that reads a webhook payload.** Real captured deliveries; several behaviours the upstream docs contradict. |
| `packages/kudosity-client/tests/Fixtures/V2Senders/README.md` | What is and is not verified about the sender item shape. |
| `.github/workflows/run-tests.yml` | `test-client` (8.2/8.3/8.4, standalone) and `test-laravel` (8.3/8.4 × L11/L12). |
| `phpstan.neon.dist` | The `phpVersion` range that enforces the floor. |
| `.github/workflows/split.yml` | **Must keep `actions/checkout@v4`** — see Warnings. |
| `.env` | Gitignored, mode 600. `KUDOSITY_API_KEY`, `KUDOSITY_API_SECRET`, `KUDOSITY_FROM`, `KUDOSITY_TEST_RECIPIENT`. **No values recorded anywhere in this document.** |

## Code Context

```bash
# Both suites, and the floor
vendor/bin/pest --compact                                    # root: Laravel + codemod
cd packages/kudosity-client && vendor/bin/phpunit            # client, standalone

# PHP 8.2, which no local toolchain provides
cd packages/kudosity-client
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit --no-coverage
```

```php
// The completeness-guard pattern used in Tasks 2-4. Without it, adding a class
// and forgetting its row leaves the suite green and the new surface untested.
public function test_every_v2_request_class_is_in_the_endpoint_table(): void
{
    $onDisk = array_map(
        static fn (string $f): string => basename($f, '.php'),
        (array) glob(__DIR__.'/../src/Requests/V2/*.php'),
    );

    $this->assertSame([], array_values(array_diff($onDisk, array_keys(self::endpoints()))));
}
```

```bash
# The mutation harness. php -r, NOT perl — see Failed Approaches.
run_mut () {
  local label="$1"; export MUT_FILE="$2" MUT_FROM="$3" MUT_TO="$4"
  cp "$MUT_FILE" /tmp/mut.bak
  php -r '$f=getenv("MUT_FILE");$s=file_get_contents($f);$n=0;
          $s=str_replace(getenv("MUT_FROM"),getenv("MUT_TO"),$s,$n);
          file_put_contents($f,$s);exit($n>0?0:1);' \
    || { echo "== $label: PATTERN DID NOT APPLY"; cp /tmp/mut.bak "$MUT_FILE"; return; }
  diff -q /tmp/mut.bak "$MUT_FILE" >/dev/null \
    && { echo "== $label: FILE UNCHANGED"; cp /tmp/mut.bak "$MUT_FILE"; return; }
  echo "== $label -> $( (cd packages/kudosity-client && vendor/bin/phpunit --no-coverage 2>&1 \
       | grep -E '^(OK|FAILURES|ERRORS)' | tr '\n' ' ') )"
  cp /tmp/mut.bak "$MUT_FILE"
}
```

## Resume Instructions

1. Execute **Task 5** of `docs/superpowers/plans/2026-08-06-kudosity-phase-6-tests-ci-release.md` — paginators and enum tolerance.
   - Confirm the baseline first: `vendor/bin/pest --compact` → `844 passed`, and
     `cd packages/kudosity-client && vendor/bin/phpunit` → `130 tests, 259 assertions`.
   - If different: `git log --oneline -8`. Something moved since this handoff.
2. Then Tasks 6, 7, 8 in order. Task 7 needs `composer test-coverage` before and after its
   deletions — a coverage drop means a deletion was wrong.
3. Every task: mutation-test the new tests, run the client suite on 8.2 in Docker, and keep
   both suites green.
4. **Task 9 is the user's** — Packagist, the repo rename, and credential rotation.

## Setup Required

- PHP 8.3 or 8.4 for the root toolchain (Pest 4 needs `^8.3.0`).
- **Docker**, for the 8.2 runs. `php:8.2-cli` is already pulled.
- `composer install` at the repo root **and** in `packages/kudosity-client`. The latter is
  gitignored (`packages/*/vendor`) and is what the standalone suite runs against.
- Live testing reads the gitignored `.env`. **No credential values are recorded in this
  document.** `parse_ini_file()` chokes on `#` comments containing parentheses.
- For a receiver verification: `ngrok` plus a local HTTP server. Webhook URLs must be HTTPS.

## Edge Cases & Error Handling

- **Handled:** both V2 envelope shapes; RFC 9457 with per-field `issues[]`; the plain
  `{"error": "..."}` body the webhook endpoints use; non-JSON error bodies; a truncated or
  hostile webhook payload; an unrecognised event type; inline base64 inbound media that will
  not decode; a stale flat `base_url`.
- **Deferred:** `paginationDirection()` is forwarded into the query unvalidated. Both V2
  paginators' `getPageItems()` declare `array<int, mixed>` but return `mixed`.
- **Unverified by construction:** the sender registration *item* shape, and the success
  bodies of `register()` / the verification calls. All read defensively with `raw` retained.

## Warnings

- **`.github/workflows/split.yml` must keep `actions/checkout@v4`.**
  `claudiodekker/splitsh-action@v1.0.0` runs `git config --local --unset-all
  http.https://github.com/.extraheader` under `set -e`; checkout v5+ stores the token via an
  includeIf file, so the unset hits a missing key (exit 5) and aborts the split *before any
  push*. Do not "fix the drift".
- **Release tags must be `v`-prefixed** (`v2.0.0`). Tag `1.7.0` was cut without it and never
  released.
- **Kudosity replaced the account's virtual number** with `61491570023`, because the old one
  could not receive MMS. A number that *sends* MMS does not necessarily *receive* it.
- **V2 deliveries are unsigned — confirmed in writing by Kudosity, 2026-08-06.**
  `x-transmitsms-signature` is V1-only; V2 signing is roadmap. Their recommended substitute
  is `message_ref`, which is what `SignedMessageRef` already signs.
- **An inbound MMS delivery can be hundreds of KB.** One photo made a 204KB POST body,
  essentially all of it one base64 field. A receiver that logs `$raw` will log all of it.
- **`GET /v2/sms/{id}` 404s for a few seconds after `POST /v2/sms`.** Read-after-write lag.
- **Statuses arrive UPPERCASE from webhooks and lowercase from the send endpoints.**
  `MessageStatus::fromApi()`'s case-insensitivity is load-bearing.
- **A `LINK_HIT` is not evidence a human clicked.** The first hit routinely lands in the same
  second as `DELIVERED` — a link preview.
- **`ngrok`'s `x-forwarded-*` headers are the tunnel's, not Kudosity's.**
- **`withRetry()` does not actually retry HTTP failures** on either connector. The docblocks
  were corrected; the mechanism predates 2.0 and is deliberately untouched.
- **Release checklist before tagging**, in this order: rename the GitHub monorepo to
  `kudosity-php-sdk`; confirm both split repos are populated; register both on Packagist;
  mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned pointing at the
  replacements; then tag. Registering on Packagist before the split repos have content
  publishes an empty package.
