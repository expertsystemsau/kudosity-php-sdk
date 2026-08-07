# Live consumer validation of the Kudosity 2.0 SDKs

**Date:** 2026-08-07
**Status:** approved design, pending implementation plan

## Purpose

The 2.0 packages have 785 client tests and 168 Laravel tests, all green, and a
handful of ad-hoc live sends from Phase 3 and Phase 5. What has never happened is
someone installing the published packages into an application they did not write
and using them the way a customer would. This validates exactly that: two fresh
projects, neither aware of the monorepo, consuming the packages as Composer
dependencies and driving the full SMS and MMS lifecycle against the live API,
including callbacks.

Scope excludes WhatsApp and RCS by decision — the account has no WhatsApp sender
and no RCS agent, so those channels cannot be exercised live and their unit
coverage already stands. Everything else is in scope.

## Success criteria

The run succeeds when all of the following hold:

1. Both packages install from a Composer artifact built the way `splitsh`
   publishes them, with no path repositories and no reference to the monorepo.
2. A vanilla PHP application completes every V1 and V2 surface listed in
   "Coverage" below against the live API, and its offline surfaces run
   additionally on the declared `^8.2` floor. Live scenarios run once only, so
   the floor is exercised without doubling the spend.
3. A fresh Laravel 12 application sends via all in-scope notification channels,
   receives real webhook deliveries over a public tunnel, and persists every
   decoded event to a database table.
4. Every defect found is either fixed in the monorepo with a test that fails
   before the fix, or recorded in the report with the reason it was not fixed.
5. `VALIDATION-REPORT.md` records each check with its evidence, and separates
   SDK defects from upstream API behaviours the documentation gets wrong.

## Decisions taken

| Question | Decision | Reason |
|---|---|---|
| API realism | Live traffic, real credentials, real handset | The only way to validate a contract the unit suite mocks |
| Callbacks | ngrok tunnel, real deliveries, human replies from the handset | Registration→delivery is the loop that has never been tested from a consumer's position |
| Opt-out (`STOP`) | **Skipped live**; replay the captured `OPT_OUT` fixture | A live STOP genuinely opts the handset out of the account |
| Install method | `git archive` → zip → Composer artifact repository | Validates per-package `export-ignore`, LICENSE inclusion, and the `require` blocks nothing in the repo evaluates |
| Number leasing | Excluded from live | `numbers()->lease()` bills the account monthly; request shape validated against a stub |
| Defects found | Fixed in the monorepo as encountered; escalate when a fix needs a product decision | User instruction |

## Layout

```
/home/mitchell/projects/kudosity-sdk-validation/     # outside the monorepo git tree
├── artifacts/                                       # built zips + packages.json
│   ├── expertsystemsau-kudosity-php-client-2.0.1.zip
│   └── expertsystemsau-kudosity-laravel-client-2.0.1.zip
├── order-notifier/                                  # Project A — vanilla PHP
├── dispatch-desk/                                   # Project B — Laravel 12
├── captured/                                        # raw webhook payloads, JSONL
└── VALIDATION-REPORT.md
```

The directory sits outside the monorepo deliberately. A project inside it would
inherit the root `composer.json` PSR-4 map and the root `vendor/`, and could pass
while importing monorepo source rather than the installed package — the exact
failure this exercise exists to rule out.

## Package delivery

Each package is archived from `HEAD` exactly as the split produces it:

```
git archive --format=zip --prefix='' HEAD:packages/kudosity-client  > artifacts/…-2.0.1.zip
```

`export-ignore` rules apply during `git archive`, so the zip contains what a
consumer receives. Before packaging, each archived tree is checked for:

- `tests/` absent
- `LICENSE.md` present
- `composer validate --strict` clean

**One deliberate deviation.** Packagist derives a package version from its git
tag. No `v2.0.1` tag exists, so a `"version": "2.0.1"` field is injected into
each archived `composer.json` before zipping. That field is the only difference
between these artifacts and a real publish, and it is not present in the
repository.

The two projects each declare the artifact repository and `composer require` by
name. `kudosity-laravel-client`'s dependency on `kudosity-php-client: ^2.0`
therefore resolves for real — the constraint that, per the release notes, no
process in the repository has ever evaluated.

## Project A — `order-notifier` (vanilla PHP, client package only)

A CLI application modelling an order-notification service. No framework, no
Laravel, no Testbench. The live scenarios run once, on local PHP 8.4. The offline
scenarios run again inside `php:8.2-cli`, proving the declared floor holds for a
real consumer and not only for the package's own suite — the root Pest suite can
never show this, because Pest 4 requires 8.3.

Structure: a thin `bin/notify` dispatcher over one class per scenario, each
returning a structured result the report generator consumes. Each scenario knows
one surface, states what it asserts, and can run alone.

### Coverage

**V2 messaging**
- `sms()->send()` to the test handset; assert the flat envelope, the returned
  message id, and `MessageStatus` decoding
- `sms()->get($id)` for that message
- `sms()->list()` paginated across a page boundary, confirming `V2PagedPaginator`
  reads `limit` from the response rather than assuming it
- `mms()->send()` with a real media URL; `mms()->get($id)`; confirm there is no
  list endpoint and the SDK does not pretend otherwise

**V1 lists and contacts**
- create a throwaway list → add the test handset → read members → bulk CSV add →
  remove a member → delete the list
- API opt-out exercised on a *second* contact created with a reserved fictitious
  number (`61491570006`), never on the test handset — that number cannot receive
  a message, so opting it out has no real-world effect

**V1 bulk send**
- one send to the throwaway list carrying `send_at`, validity, link tracking and
  per-send DLR / reply / link-hit callback URLs
- bulk progress read back

**V1 reporting and account**
- delivery stats, message report, sent counts, replies, inbound
- `account()->getBalance()`

**Senders**
- list registrations, paging on `meta.pagination.total_count`; `allRegistrations()`
  collecting every page; `readyToUse()` filtering to the usable ones
- the item shape decoded field by field, since only the read path is verifiable:
  `register()` needs a number the account does not already own, and
  `confirmVerification()` needs a code read off that handset, so the write path is
  recorded as not covered rather than faked

**Webhooks CRUD**
- `create` → `get` → `update` (a full-shape replace, since `PUT` is not a patch)
  → `delete`
- an `http://` URL is rejected client-side even though the API accepts it

**Keywords, numbers, email-to-SMS**
- keyword reads; offline `formatNumber()` and E.164 normalisation, including the
  refusal to guess a country
- email-to-SMS configuration and allowed-sender reads, read-only

**Webhook payload handling**, driven from payloads captured live during this run:
- `WebhookEvent::fromArray()` across every event type observed
- `StatusPrecedence` against real duplicate and out-of-order status deliveries
- `SignedMessageRef` round trip — sign a ref on an outbound send, recover it from
  the real inbound reply, and confirm a forged ref is rejected

**Excluded:** `numbers()->lease()`, exercised against a stub only.

## Project B — `dispatch-desk` (fresh Laravel 12 application)

Created with `laravel new`, then `composer require` the Laravel package from the
artifact repository. Package auto-discovery is left to do its job; if discovery
fails, that is a finding.

The application is a small dispatch desk: a `Customer` model that is a real
notifiable, a `message_events` table, and event listeners that persist every
decoded webhook event. Verification queries the database — "the callback
arrived and was understood" is answered by a row, not by a log line.

### Coverage

**Notification channels**
- `toKudosity()` on the V2 path — the default
- the same message forced onto V1 by `sendAt()`, then by `toList()`, then by
  `onDlr()`, asserting `apiVersion()` and `v1Reasons()` each time
- `forceV2()` **throws** when the message carries a V1-only option, rather than
  silently dropping it
- the channel returns `Contracts\SentMessage`, not a concrete DTO
- `toKudosityMms()` end to end

**Webhook receiver**
- the V2 `POST {prefix}/events` route dispatching
  `KudosityStatusReceived` / `KudosityInboundReceived` /
  `KudosityLinkHitReceived` / `KudosityOptOutReceived`, each landing in
  `message_events`
- an unrecognised event type is logged and accepted with a 200, not a 500 — it
  resolves to `UnknownEvent`, which the controller does not dispatch to a typed
  listener, so no row is written
- the three V1 GET routes with genuinely signed URLs, plus a tampered signature
  rejected — the receiver is stricter than `CallbackUrlParser` by design

**Artisan commands**
- `kudosity:webhook:list`, `:install`, `:delete`
- `:install` builds its URL through `CallbackUrlBuilder`; a URL built any other
  way is refused by the receiver it just registered

**Configuration**
- `config/kudosity.php` publishes
- a config still carrying a flat `base_url` string throws at boot rather than
  sending V2 traffic to the V1 host
- `KudosityV2Connector` resolves from the container despite `$apiKey` having no
  default

**Not exercised:** the WhatsApp and RCS channels.

## The live callback loop

One ngrok tunnel, re-pointed once mid-run, which makes `webhooks()->update()`
part of the test rather than a chore.

**Phase 1 — tunnel to Project A.** A bare `php -S` receiver writes every raw
delivery to `captured/*.jsonl`. Register a webhook through `webhooks()->create()`.
Send a link-tracked V2 SMS and an MMS. Real `SMS_STATUS`, `MMS_STATUS` and
`LINK_HIT` deliveries arrive. The user replies from the handset, producing a real
`SMS_INBOUND` carrying the outbound `message_ref`; then sends an MMS reply,
producing a real `MMS_INBOUND` with an inline base64 attachment.

**Phase 2 — `update()` the same registration to point at the Laravel app.**
Repeat the send set. Events now travel through the real controller and listeners
into `message_events`.

**Phase 3 — opt-out.** Replayed from the captured fixture, not sent live.

**Duplicate and out-of-order deliveries are expected, not incidental.** The
repository's own fixtures record a `SENT` arriving 57 seconds after `DELIVERED`,
byte-identical to its original. If the behaviour recurs during this run, it is
asserted against `StatusPrecedence` live. If it does not recur inside the
observation window, the captured duplicate is replayed instead and the report
says so — an unobserved behaviour is not reported as a live pass.

## Failure handling

A failing check does not stop the run. Each is triaged into one of three
buckets, the run continues, and the bucket determines what happens next:

1. **SDK defect** — fixed in the monorepo on a branch, with a test that fails
   before the fix and passes after. The scratch project is re-run against a
   rebuilt artifact to confirm the fix from the consumer's side.
2. **Upstream API behaviour** — recorded, with the payload, in the report's
   findings section. Where it contradicts a vendored skill or a fixture README,
   the contradiction is called out for a decision rather than silently
   reconciled.
3. **Environment or account** — missing sender, unprovisioned capability,
   insufficient balance. Recorded, and the dependent checks marked blocked
   rather than failed.

Anything needing a product decision — a behaviour change with a compatibility
cost, an ambiguous API contract — is raised rather than decided.

Fixes land on a branch off `main`, one atomic commit per defect, each commit
message carrying the failing evidence. Nothing is committed to `main` directly.

## Pre-flight

Before a single message is sent:

- **Verify the sender.** The notes record `KUDOSITY_FROM` as stale — Kudosity
  retired that virtual number and replaced it, and every V2 send answers
  `Sender not found` until it is corrected. `senders()` is listed live and a
  usable sender confirmed first.
- Confirm account balance covers the run.
- Confirm the handset is reachable and the user is available for the two reply
  prompts.

Credentials are read from the monorepo `.env` and copied into each project's own
`.env`. No credential value is written to the report, to a commit, or to any
file inside the monorepo.

## Cost and side effects

Roughly twelve billable messages: several SMS, two MMS, one bulk send of one
recipient, one sender verification. Created and then removed: one contact list,
several webhook registrations, one ngrok tunnel. Nothing persists on the account
after cleanup, and the handset is not opted out.

## Output

`VALIDATION-REPORT.md`, containing:

- a check-by-check table: surface, expectation, result, evidence
- captured request and response bodies, with credentials and the handset number
  redacted the way the existing fixtures redact them
- SDK defects found, each with its fix commit or the reason it has none
- upstream API findings, separated from SDK defects
- any newly-captured payload that contradicts an existing fixture or a vendored
  skill, flagged for the user to decide whether it belongs in the repository
- what was not covered, and why

## Out of scope

- WhatsApp and RCS channels
- `numbers()->lease()` against the live API
- A live opt-out
- Publishing to Packagist, tagging `v2.0.0`, or any other release-checklist work
- Credential rotation
