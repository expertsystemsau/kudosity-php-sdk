# Handover — Laravel 13 support

**Date:** 2026-08-10
**Status:** **COMPLETE 2026-08-10.** Shipped as `v2.1.0` — all four Definition of
done items closed, including a live send from a fresh Laravel 13 app against the
published package. Remaining 2.1.0 scope (DTO fields, the `get-contact-sms-stats`
paginator, the `SentMessage` design decision) is unrelated to Laravel 13 and still
open; see "Still open" below.
**Context:** 2.0.2 shipped without this, deliberately. See `CHANGELOG.md` "Known issue".

## Outcome (2026-08-10)

**Laravel 13 needed no source changes.** Every risk in the list below was checked
and none had moved.

| Check | Result |
|---|---|
| `saloonphp/laravel-plugin` Laravel 13 support | Already `^11.0 \|\| ^12.39.0 \|\| ^13.0` at v4.3.0 — never an upstream blocker |
| Root Pest suite, Laravel 13.24.0 / Testbench 11.1.0 / PHP 8.4 | **168 passed** (394 assertions) — identical to the Laravel 12 baseline |
| PHPStan level 6 | No errors |
| Deprecations / warnings / notices / risky | None |

Constraints widened: `illuminate/notifications` and `illuminate/support` to
`^11.0||^12.0||^13.0`, `orchestra/testbench` to `^9.0||^10.0||^11.0` (both the
package and the monorepo root). Laravel 13 / Testbench 11 added to
`.github/workflows/run-tests.yml` as a **first-class matrix entry, not an allowed
failure** — the gap the original plan wanted made visible turned out not to exist.

### The PHP floor stays at `^8.2` — decided, not deferred by accident

Laravel 13 requires PHP `^8.3`; Laravel 11 and 12 require `^8.2`. That is not a
conflict and needs no matrix exclusion, because Composer resolves per consumer.
Verified by resolving `packages/kudosity-laravel` standalone under each platform:

| Simulated consumer | Resolves to |
|---|---|
| PHP 8.2 | `laravel/framework v12.65.0` |
| PHP 8.3 | `laravel/framework v13.24.0` |

Raising the floor to `^8.3` is a **breaking change** and belongs in **3.0**, not in
an additive 2.1.0 — the same versioning logic that kept the constraint widening out
of a 2.0.2 patch. PHP 8.2 security support ends **2026-12-31**, so 3.0 is the
natural moment. What the bump would buy, for whoever writes 3.0:

- The root Pest suite could finally exercise the declared floor. It cannot today —
  **Pest 4 requires PHP `^8.3`**, which is the entire reason the standalone client
  PHPUnit suite exists. (Confirmed while testing: simulating a PHP 8.2 platform at
  the monorepo root fails on `pestphp/pest`, not on anything Laravel.)
- The client suite could move PHPUnit 11 → 12.

Neither justifies a BC break on its own. Note the client package is framework-free,
so a Laravel-motivated floor bump would penalise consumers who never touch Laravel.

## The problem, precisely

`packages/kudosity-laravel/composer.json` requires:

```json
"illuminate/notifications": "^11.0||^12.0",
"illuminate/support":       "^11.0||^12.0"
```

Laravel's current release is **13.x** (13.24.0 as of 2026-08-09). So:

```
composer create-project laravel/laravel my-app     # gets Laravel 13
composer require expertsystemsau/kudosity-laravel-client
# -> hard dependency resolution failure
```

**Every new adopter hits this on their first command.** The other seven defects
found during validation degrade behaviour; this one prevents installation. It is
the highest-impact open issue on the package, and it is invisible to anyone
already on Laravel 12 — which is why it went unnoticed until someone ran
`laravel new` from scratch during the 2026-08 consumer validation.

`orchestra/testbench` is capped the same way (`^9.0||^10.0`) and gates the dev
matrix. Testbench 11 is the Laravel 13 line.

## Why this was not fixed in 2.0.2

Widening a constraint is a one-line edit; *supporting* a major version is not.
Publishing `^13.0` without testing against 13 would move the failure from an
honest install error to a runtime surprise in someone's production app, which is
strictly worse. The validation exercise that found this had no Laravel 13
coverage and could not manufacture it responsibly in scope.

## What actually needs checking

The package's Laravel surface is small, which is the good news. In rough order of
risk:

1. **`KudosityServiceProvider`** — `packages/kudosity-laravel/src/KudosityServiceProvider.php`.
   Registers singletons for both connectors and the client, extends the
   notification `ChannelManager` with four channels, registers three Artisan
   commands, publishes config, and loads routes. Provider registration and
   `publishes()` are the APIs most likely to have moved.
2. **`Notification::resolved(fn (ChannelManager $service) => $service->extend(...))`** —
   the channel-registration hook. This is the least stable thing the package
   touches; verify `extend()` and the resolved-callback still behave the same.
3. **The four notification channels** — `KudosityChannel`, `KudosityMmsChannel`,
   `KudosityWhatsAppChannel`, `KudosityRcsChannel`. They implement Laravel's
   channel contract (`send($notifiable, Notification $notification)`); confirm
   the signature is unchanged.
4. **Route registration** — `registerWebhookRoutes()` uses `Route::prefix()->middleware()->group()`
   with `loadRoutesFrom`. Stable historically, but the `api` middleware group's
   composition has changed across majors.
5. **`saloonphp/laravel-plugin: ^4.0`** — a transitive constraint of its own.
   Check whether it supports Laravel 13 before assuming the blocker is only ours.
   **If it does not, that is the real blocker and it is upstream**, which changes
   the whole shape of this task.

## Recommended approach

**Do not start by editing the constraint.** Start by proving compatibility, then
widen to match the evidence.

1. **Check `saloonphp/laravel-plugin` first.** One command, and it can make the
   rest moot: `composer show saloonphp/laravel-plugin --all | grep -A2 requires`,
   or read its `composer.json` on Packagist. If it caps at Laravel 12, open or
   find an upstream issue before doing anything else.
2. **Add Laravel 13 to the CI matrix as an allowed failure**, so the gap is
   visible and measured rather than assumed. `.github/workflows/run-tests.yml`
   already has a matrix; Testbench 11 pairs with Laravel 13.
3. **Run the root suite against Testbench 11 locally.** The root suite is the
   Laravel integration suite — that is exactly the coverage this needs. Note it
   needs PHP 8.3+ (Pest 4), so the 8.2 floor is not exercisable here; that is
   pre-existing and documented in `CLAUDE.md`.
4. **Fix what breaks**, then widen `illuminate/*` to `^11.0||^12.0||^13.0` and
   `orchestra/testbench` to `^9.0||^10.0||^11.0`.
5. **Validate as a consumer, not just as a suite.** The whole lesson of the
   2026-08 exercise is that a green suite proves less than one install. Do a
   `laravel new` on 13, `composer require` the published package, and send one
   real message. That is a ten-minute check that would have caught this issue
   before it shipped.

## Version strategy

Widening a constraint to admit a new major is **additive** — no existing
consumer's resolution changes. Ship it as **2.1.0**, alongside the other 2.1.0
work already deferred (below). Do not ship it as a patch: consumers reasonably
read a patch as "nothing about my dependency graph changed".

## Already deferred to 2.1.0 — bundle these

From the same validation exercise, all deliberately not done in 2.0.2:

- **Expose the fields these DTOs currently drop.** The API returns them and the
  SDK discards them: `SmsStatsData` drops `hard_bounced`, `soft_bounced`,
  `link_hits`; `BulkProgressData` drops `imported`, `duplicates`, `skipped`,
  `optout`. Additive properties, safe in a minor.
- **A paginated reader for `get-contact-sms-stats`.** `getContactStats()`
  currently throws (2.0.2) because the endpoint returns
  `{page, total, records[]}` — a per-message record list — which
  `ContactSmsStatsData` cannot represent. Correct aggregation means paging
  through `records[]`, which is a behaviour change and so belongs here.
- **Design note, needs a decision rather than an implementation:** three of four
  V2 message DTOs (`MmsMessageData`, `WhatsAppMessageData`, `RcsMessageData`) do
  not implement `Contracts\SentMessage`; only `SmsMessageData` does. That is
  defensible today — only the SMS channel routes between V1 and V2, so only it
  needs a stable return type — but it means a consumer cannot write polymorphic
  code across channels. Decide whether that is intentional.
- **Two methods target endpoints the platform does not implement.** No SDK method
  calls them, so nothing is broken, but `/get-sms-report.json` and
  `/get-contact-stats.json` both answer `NOT_IMPLEMENTED` if anyone adds one.

## Traps that will cost you time if nobody warns you

These were all paid for during the 2026-08 validation. They are not Laravel 13
specific, but they will bite whoever picks this up.

- **`laravel/laravel` now resolves to 13 by default**, so scaffolding a "current"
  test app silently gives you 13. Pin explicitly when you want 12.
- **A fresh Laravel 12 skeleton ships plain PHPUnit, not Pest.** If you reuse the
  validation exercise's tests, install `pestphp/pest` and
  `pestphp/pest-plugin-laravel` and run `pest --init` first.
- **`->throws(Throwable::class)` does not work in Pest.** Pest resolves that
  argument with `class_exists()`, which is `false` for an interface, so it
  degrades to matching the exception *message* and fails confusingly. Name the
  concrete class.
- **Laravel 11/12 auto-discover class-based listeners.** Combined with explicit
  `Event::listen()`, every event dispatches twice. The package README now warns
  about this; if you write a listener class in a test app, either drop the
  explicit registration or set `->withEvents(discover: false)`.
- **`KudosityV2Connector` cannot autowire** — `$apiKey` has no default — so it
  depends on its explicit singleton in the provider. If provider registration
  changes in 13, this is the first thing that will break.

## Where the evidence lives

- `/home/mitchell/projects/kudosity-sdk-validation/VALIDATION-REPORT.md` — the
  full 142-check report from the exercise that found this.
- `.superpowers/sdd/2026-08-07-sdk-v2-live-validation/progress.md` — the ledger,
  including several places where an initial conclusion was wrong and got
  corrected. Read it before trusting any summary, including this one.
- `docs/superpowers/plans/2026-08-07-sdk-v2-live-validation.md` — the plan, with
  sixteen defects of its own corrected in place. Task 11 is the Laravel scaffold
  and is the closest existing template for a Laravel 13 consumer check.

## Definition of done

1. [x] `saloonphp/laravel-plugin`'s own Laravel 13 support is confirmed, or the
   blocker is documented as upstream. — **Confirmed supported since v4.3.0.**
2. [x] The root suite passes on Testbench 11 / Laravel 13, in CI, not just locally.
   — **All 15 jobs green** on run `31352444072` (`e5beb7d`): 12 Laravel + 3 client
   standalone, including all four Laravel 13 jobs (PHP 8.3 and 8.4 × Ubuntu and
   Windows). PHP 8.3 had no local toolchain and was unverified anywhere until this
   run, which mattered because `^8.3` is Laravel 13's own floor.
3. [x] A `laravel new` on 13 installs the published package and sends one real
   message. — **Done 2026-08-10.** Fresh `composer create-project laravel/laravel`
   gave Laravel 13.24.0 on PHP 8.4.5; `composer require
   expertsystemsau/kudosity-laravel-client` resolved `^2.1` from real Packagist
   and auto-discovered the provider. Verified in that app: config publishes, the
   client and both connectors resolve (including `KudosityV2Connector`, the one
   flagged as most likely to break), all four channels register on
   `ChannelManager`, all six V2 resources resolve, three Artisan commands and four
   webhook routes register.

   **Live send: `id=71b46873-67a1-41cb-82a7-8ce9c3ebc1d3`, read back as
   `DELIVERED`** to `61447514584` from `61437130145`, `sms_count=1`.

   Two things this check caught that CI structurally could not: the repo `.env`
   still carried the retired sender `61426309571` and every V2 send failed
   `Sender not found` (now fixed in `.env`); and the account holds exactly one
   active number, `61437130145`, confirmed via V1 `numbers()`. Neither is a
   Laravel 13 issue — but the first would have made any live send fail and be
   misread as one.

   Non-defect worth recording so nobody re-reports it: `numbers()->all()` returns
   a `V1PagedPaginator`, not an array. A probe that does
   `is_array($r) ? $r : $r->toArray()` silently yields `[]` and looks like an
   empty account.
4. [x] Constraints widened, 2.1.0 tagged and published, `CHANGELOG.md`'s "Known
   issue" entry updated. — **Constraints widened, changelog cut to `2.1.0`, tagged
   `v2.1.0` and pushed.** Packagist picks it up from the split repos via webhook;
   confirm both packages list 2.1.0 before relying on item 3.

   Deviation worth knowing: the 2.0.2 "Known issue" entry was **marked resolved
   rather than deleted**. It is an accurate record of what 2.0.2 shipped, and
   changelog history should not be rewritten to hide a fixed defect.

## The other deferred items — all done 2026-08-10, targeting 2.2.0

Laravel 13 shipped alone as 2.1.0, so the rest lands in **2.2.0**. All four are
complete and on `main`; nothing from the 2026-08 validation backlog is left.

| Item | Outcome |
|---|---|
| Dropped `SmsStatsData` fields | Added `hardBounced`, `softBounced`, `linkHits` — **and `recipientCount`, a fourth the original note missed** |
| Dropped `BulkProgressData` fields | Added `imported`, `duplicates`, `skipped`, `optout`; `errors` deprecated (always 0) |
| `get-contact-sms-stats` paginated reader | `getContactRecords()` + `getContactStats()` now aggregates instead of throwing |
| `SentMessage` on the other three V2 DTOs | Implemented on all four — decided with the user, not left as a design note |
| The two `NOT_IMPLEMENTED` endpoints | Confirmed live; no SDK method reaches them, so nothing to fix |

**Every field name was verified against the live API**, including a deliberately
mixed 2-row bulk import (one valid number, one invalid) so `importlength`,
`imported` and `skipped` took distinct values and could not be confused by all
reading zero. The probe list and its gist were deleted afterwards.

Three things worth carrying forward:

- **`get-sms-stats` returns `recipientCount` in camelCase** while every sibling
  key is snake_case. A reasonable "fix" to `recipient_count` silently zeroes it.
  There is a mutation-tested assertion pinning this.
- **Saloon's `Paginator::items()` is annotated `iterable<mixed,
  Response|PromiseInterface>` upstream, which is wrong** — it yields the rows.
  PHPStan believes the annotation, so `ReportingResource` reads pages directly
  rather than suppressing the error.
- **`MessageStatus::fromApi()` returns `Unknown` rather than throwing** on an
  unmodelled value. `ContactSmsRecordData` keeps the raw string beside the enum,
  because this endpoint's status vocabulary is undocumented upstream.

Verified: client suite **820 tests** (was 802), root suite 168, PHPStan level 6
clean, Pint clean. Both new behaviours were mutation-tested — breaking the
multi-page tally and snake_casing `recipientCount` each fail a test.
