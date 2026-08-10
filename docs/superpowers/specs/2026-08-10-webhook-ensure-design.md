# Idempotent V2 webhook registration

**Date:** 2026-08-10
**Status:** approved design, pending implementation plan

## Purpose

V2 has no per-send callback URL. Delivery receipts, inbound replies, link hits
and opt-outs arrive only if an account-level webhook registration points at your
receiver. Today the only way to create one is `kudosity:webhook:install`, which
is imperative, Laravel-only, and creates a second registration every time it
runs.

This adds `WebhooksResource::ensure()` — a declarative, idempotent reconcile
against the account — and makes every consumer a caller of it: a Laravel
command, a Symfony console command, or ten lines of raw PHP.

## The problem this actually solves

Not "I forgot to register". That failure is loud — nothing ever arrives, and
somebody notices within a day.

The failure worth designing for is **drift**. The receiver URL carries an HMAC
signature over the handler marker (`CallbackUrlBuilder::build()`), and
`WebhookController::events()` rejects any delivery whose signature does not
verify. So the registration keeps existing, Kudosity keeps delivering, and every
delivery 403s — silently, because Kudosity has no channel to tell you your
endpoint is refusing it.

Four ordinary changes cause it:

| Change | Effect on the registered URL |
|---|---|
| `KUDOSITY_SIGNING_KEY` or `APP_KEY` rotated | signature no longer verifies |
| `webhooks.prefix` changed | path no longer routes |
| `APP_URL` changed, or the app moved domain | host no longer resolves to you |
| the subscribed event list changed in code | events silently not delivered |

A "does a registration exist?" check passes in all four. A reconcile against
desired state catches all four. That is the whole argument for this design.

A fifth, separate hazard applies to non-Laravel consumers:
`CallbackUrlParser::parse()` skips signature verification entirely when neither
`h` nor `c` is present (`packages/kudosity-client/src/Callbacks/CallbackUrlParser.php:37-38`).
A raw-PHP receiver registered with a bare URL is therefore world-writable —
anyone who guesses the path can post fabricated delivery receipts. `ensure()`
must never make it easy to register a URL without the handler marker.

## Success criteria

1. Calling `ensure()` N times with the same arguments leaves exactly one
   registration and issues exactly one write (on the first call).
2. Each of the four drift triggers above is detected and repaired in place,
   preserving the registration id.
3. A registration belonging to a different host or a different path is never
   modified and never deleted.
4. A raw-PHP consumer registers in under ten lines, with no console framework,
   no config file, and no package binary.
5. `kudosity:webhook:install` and the new `kudosity:webhook:sync` build their URL
   through the same code path, so the receiver cannot reject what sync
   registered.
6. A test asserts the full round trip: the URL `sync` registers is accepted by
   `WebhookController::events()`.
7. No command in a non-allowlisted environment issues a single HTTP request to
   the webhook endpoints, and the refusal explains why.

## Decisions taken

| Question | Decision | Reason |
|---|---|---|
| How is "our" registration identified? | Normalised **scheme + host + path**; query string ignored, host lower-cased, trailing slash stripped | The signature lives in the query and is precisely the part that drifts, so it cannot be part of identity. `name` is user-editable in the dashboard. Matching by `id` would force the SDK to demand local persistence. |
| How is drift repaired? | `PUT` replace with the whole shape | The API has no `PATCH`; `update()` already replaces (`WebhooksResource:100`) |
| Delete-and-recreate instead? | No | Loses the id, and drops every delivery in the gap |
| Registrations we don't own | Never touched, not even reported as a problem | One account legitimately serves many apps |
| Two registrations matching our path | Repair the first, return the rest as `duplicates`, delete none | Deleting is unrecoverable and the SDK cannot know which is load-bearing |
| Environment policy (staging vs prod) | Caller's, not the SDK's. In Laravel it is a **fail-closed allowlist with no override flag** | Matches the existing `allowInsecureUrl` split — Laravel knows the environment, the client package does not (`WebhookInstallCommand:47-49`). Fails closed because one account backs every environment. |
| Scheduled reconcile | **Cut** | In a shared account an hourly self-heal re-creates a registration somebody deliberately deleted |
| Partitioning a shared account | **Not possible.** No `--sender` option, no filter config key | Every environment sends from the same sender, and the other four filter keys cannot express "environment" — see "Shared account" |
| Skipping the list request | Optional fingerprint store, off by default | One GET per deploy is not worth a mandatory dependency |
| Console binary in the client package | No | Would add `symfony/console` to a package with two runtime dependencies, plus a config-file convention it does not have |
| Lazy `ensure()` on the send path | **Out of scope**, see below | Puts a network round-trip and a new failure mode into message sending |

## Architecture

### 1. `WebhooksResource::ensure()` — client package

```php
public function ensure(
    string $name,
    string $url,
    array $eventTypes = [],
    ?WebhookFilter $filter = null,
    ?int $rateLimit = null,
    bool $allowInsecureUrl = false,
    ?WebhookFingerprintStore $store = null,
): EnsureResult
```

Algorithm:

1. If `$store` is supplied and holds a fingerprint equal to the desired
   fingerprint, return `Unchanged` with no HTTP traffic at all.
2. `all()` — one `GET /v2/webhook`. An account with no registrations answers
   `{}`, omitting the collection key entirely; `all()` already maps that to `[]`.
3. Select registrations whose normalised identity equals the desired URL's.
4. None → `create()`, action `Created`.
5. One or more → compare the first against desired state. Equal → `Unchanged`,
   no write. Different → `update()`, action `Updated`.
6. Write the fingerprint to `$store` if supplied.

`$allowInsecureUrl` is passed straight through to `create()`/`update()`, so the
HTTPS rule and its explicit override behave exactly as they do today.

**Comparison rules.** What counts as drift is where this gets subtle:

| Field | Rule |
|---|---|
| `url` | Exact string match, **query string included** — the signature is the point |
| `name` | Exact |
| `filter.eventType` | Compared as a **set**: normalised to backing string values, sorted, deduped. Reordering the array in code must not trigger a write. |
| other `filter` arrays | Same set comparison |
| `rateLimit` | Compared **only when the caller passed one**. The API echoes `0` for "system default" (`WebhookData::hasRateLimit()`), so a caller passing `null` against a stored `0` must read as unchanged. |

That last row is the trap: get it wrong and every deploy issues a `PUT` that
changes nothing, forever, and the "unchanged" path is never exercised in
production.

New types — `EnsureAction` in `ExpertSystems\Kudosity\Enums` alongside the other
enums, `EnsureResult` in `ExpertSystems\Kudosity\Data\V2` alongside `WebhookData`:

```php
enum EnsureAction: string {
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
}

final readonly class EnsureResult {
    public function __construct(
        public EnsureAction $action,
        public ?WebhookData $webhook = null,
        /** @var array<int, WebhookData> */
        public array $duplicates = [],
    ) {}
}
```

`Skipped` exists because the fingerprint store below short-circuits before the
list request, so there is no registration to return — it is the **only** action
carrying a null `$webhook`, and that invariant is documented on both types. A
caller that always needs the DTO passes no store. `Unchanged` and `Skipped` both
mean "nothing was written", and the difference is whether the account was read.

### 2. `WebhookFingerprintStore` — optional, for skipping the GET

```php
namespace ExpertSystems\Kudosity\Contracts;

interface WebhookFingerprintStore {
    public function get(string $key): ?string;
    public function put(string $key, string $fingerprint): void;
}
```

Two methods, no dependency. Wrapping a PSR-16 cache is six lines in the
consumer's own code, which is cheaper than requiring `psr/simple-cache` here.
The client package ships one implementation,
`ExpertSystems\Kudosity\Webhooks\FileFingerprintStore`, taking a file path —
because raw PHP is exactly the audience with no cache library.

Fingerprint: `sha256` over `name | url | sorted event types | rateLimit`. The
key is the normalised identity, so one file can serve several registrations.

**The store decides only whether to skip the list request. It is never
authoritative** — a missing or stale file costs one GET, never a wrong outcome.

### 3. `kudosity:webhook:sync` — Laravel

Wraps `ensure()` and owns no reconcile logic of its own:

- Builds the URL through `CallbackUrlBuilder` with
  `WebhookInstallCommand::HANDLER_MARKER`, identical to `install`.
- Same HTTPS guard and same `APP_ENV=local` plaintext exception. The guard moves
  out of `WebhookInstallCommand` into a shared concern so the two commands
  cannot diverge — divergence here means sync registers a URL install's rules
  would have rejected.
- Options: `--name=`, `--event=*`, `--rate-limit=`, `--url=`, mirroring `install`.
- Output names the action: `Created webhook wh_…` / `Updated webhook wh_…` /
  `Webhook wh_… already correct`. Duplicates are listed with a note that nothing
  was deleted.
- `SUCCESS` on all three actions; `FAILURE` on guard rejection or
  `KudosityException`.

`kudosity:webhook:install` stays exactly as it is — the imperative one-shot,
still the right tool for registering a second, differently-filtered webhook.

**The environment gate fails closed.** One Kudosity account backs every
environment here (confirmed 2026-08-10), so this is the primary control, not a
convenience:

```php
'webhooks' => [
    'sync' => [
        // Environments permitted to write account-level webhook registrations.
        // Empty or unlisted => the command refuses. There is no override flag,
        // because registering outside production exposes production traffic to
        // that environment. See "Shared account".
        'environments' => ['production'],
    ],
],
```

`sync` refuses to run when `app()->environment()` is not in the list, and says
why — naming the account-sharing reason, because an operator who hits this in
staging needs to understand it is deliberate rather than a misconfiguration.
`install` and `delete` gain the same gate; they write to the same account.

No scheduled reconcile ships. An hourly self-heal in a shared account
re-creates a registration somebody deliberately deleted, which is the opposite
of self-healing.

### 4. Raw PHP

No new package surface; this is a documented recipe in the client README:

```php
<?php
require 'vendor/autoload.php';

use ExpertSystems\Kudosity\Callbacks\{CallbackType, CallbackUrlBuilder};
use ExpertSystems\Kudosity\KudosityClient;

$client = new KudosityClient(apiKey: getenv('KUDOSITY_API_KEY'));
$urls = new CallbackUrlBuilder('https://app.example.com/webhooks/kudosity', getenv('KUDOSITY_SIGNING_KEY'));

$result = $client->webhooks()->ensure(
    name: 'My app events',
    url: $urls->build(CallbackType::EVENTS, 'kudosity.v2.events'),
);

echo $result->action->value.' '.$result->webhook->id.PHP_EOL;
```

Run it after each deploy. Re-running is free.

For consumers with no CLI at all (shared hosting), the README documents the
bootstrap-from-the-receiver recipe: put the same call behind a signed query
param on the receiver endpoint they already deploy, and hit it once in a
browser. No code ships for this — it is application-level, and the signing
machinery it needs already exists.

## Security

- The handler marker is mandatory in every documented example. A bare URL
  produces an endpoint `CallbackUrlParser` will not verify.
- `ensure()` inherits the HTTPS rule from `create()`/`update()`; plaintext still
  requires the explicit `allowInsecureUrl` flag, and the environment judgement
  still lives with the caller.
- `ensure()` never deletes. Removal stays with `kudosity:webhook:delete`, where a
  human chose it.
- Deliveries remain unsigned by Kudosity (confirmed 2026-08-06). Nothing here
  changes that; the URL signature protects the endpoint, not the payload.
- Registering from a non-production environment on the shared account exposes
  production message bodies and phone numbers to that environment. The
  fail-closed gate is a security control, not ergonomics, and is the reason it
  has no override flag.

## Testing

**Client — `packages/kudosity-client/tests/V2WebhookEnsureTest.php`**, PHPUnit
with `MockClient` sequences:

- creates when the account has no registrations (`{}` body)
- unchanged, and issues no write, when the stored registration matches
- updates when the signature differs but the path matches
- unchanged when the event array is reordered
- updates when the event set genuinely differs
- **unchanged when the caller passes `rateLimit: null` and the API echoes `0`** —
  the infinite-`PUT` regression guard
- leaves a registration on a different host untouched
- leaves a registration on the same host, different path, untouched
- reports duplicates and deletes nothing
- rejects `http://` without `allowInsecureUrl`
- with a `FileFingerprintStore`: second call issues zero requests; a changed URL
  re-fires

**Laravel — `tests/Unit/WebhookSyncCommandTest.php`**, Pest:

- sync builds its URL through `CallbackUrlBuilder` and reports each of the three
  actions with the right exit code
- HTTPS guard rejects, and `APP_ENV=local` warns rather than rejecting
- **the environment gate refuses in `staging` and issues zero HTTP requests**,
  and its message names the shared-account reason
- the gate applies equally to `install` and `delete`
- an `Updated` result prints both the previous and the new URL
- **round trip: the URL `sync` registers passes `WebhookController::events()`
  verification.** This is the assertion the whole design exists for — if it ever
  fails, deliveries 403 in silence.

## Out of scope

- Lazy `ensure()` on the send path. The primitive supports it via
  `WebhookFingerprintStore`; the Laravel package will not wire a network call
  into message sending.
- Deleting stale or orphaned registrations.
- A scheduled reconcile. Cut, not deferred — see the decisions table.
- Multi-account reconciliation, and any attempt to make the SDK aware that one
  account serves several environments.
- V1 per-send callbacks, which are unaffected.

## Shared account

One Kudosity account backs every environment (confirmed 2026-08-10). Webhook
registrations are account-level, so if staging registers a webhook it receives
**the whole account's** events — every production delivery receipt, every
inbound reply, with message bodies and phone numbers. `ensure()` cannot detect
this, because both registrations are individually correct.

Of the five filter keys, only one can partition traffic by environment:

| Key | Verdict |
|---|---|
| `sender` | **The only viable partition.** Matches the sending number on status events, `mo.recipient` — your own number — on inbound, and `source_message` on link-hit and opt-out. One value covers all four event families. |
| `messageRef` | Client-supplied on all four V2 sends, but the filter is an exact-match list, not a prefix. Partitioning would mean enumerating every ref ever sent. |
| `campaignId` | No V2 send request sets `campaign_id`; it exists only as a list-query parameter. Nothing to match against. |
| `eventType`, `status` | Orthogonal to environment. |

So a partitioned setup requires **each environment to send from its own
sender**, which is a dashboard and commercial action, not an API one — a leased
virtual number is not a `SendersResource` registration.

Every environment sends from the same sender (confirmed 2026-08-10), so
`filter.sender` cannot partition anything either. **No filter-based isolation is
available.** One posture is therefore supported:

**Production registers. No other environment registers, ever.** Staging
exercises the receiver against the captured deliveries in
`packages/kudosity-client/tests/Fixtures/V2Webhooks/`, which are real payloads,
not hand-written ones.

Because the partition is unavailable rather than merely unused, `sync` gains no
`--sender` option and the config gains no filter key — an option that cannot
work is worse than its absence. `ensure()` still takes a `?WebhookFilter`, as
`create()` and `update()` already do, so a consumer who later leases a second
number can express the partition without a change here.

### What the gate does not cover

The environment gate is the only control, so its residual risks are the design's
residual risks, and both share one root cause — the environment lying about
itself:

- A staging box with `APP_ENV=production` in a copied `.env` passes the gate and
  registers.
- The clobber hazard below is the same fault with a worse outcome.

Neither is detectable from inside the SDK: an app asserting it is production,
with production's `APP_URL`, is indistinguishable from production. Accepted
rather than mitigated, and recorded here so it is a known limit rather than a
surprise.

### The inverse exposure, which no gate fixes

Staging shares the sender, so its sends are indistinguishable from production's
at the API level. **Production's webhook therefore receives delivery receipts and
inbound replies for messages staging sent.** Nothing about registration changes
this; it follows from one sender on one account.

The consequence is a requirement on consumers rather than on this SDK: a
receiver must tolerate a `messageRef` it has no record of, and must not treat an
unrecognised reference as an error worth alerting on. `WebhookController::events()`
already just dispatches, so no code change follows — but the client README, the
root README and `packages/kudosity-laravel/README.md` all need to say it,
because the natural implementation of a listener is to look the ref up and throw
when it is missing.

(An earlier revision of this spec claimed the Laravel package had no README of
its own. It does — 22KB, tracked, with its own Artisan-commands section. The
claim came from a check run in the wrong working directory and was wrong.)

### The clobber hazard

Newly plausible under a shared account: if staging boots with `APP_URL` pointing
at production's host — a copied `.env`, a wrong deploy variable — `sync` matches
production's registration by host and path and replaces its URL with one signed
by staging's key. Production callbacks then 403 in silence, which is the exact
failure this design exists to prevent, caused by the tool meant to prevent it.

The fail-closed environment gate is the control: staging cannot reach the write
at all. This is why the gate has no `--force` override, and why `sync` prints
the resolved URL and the previous URL on every `Updated` result.

## Files

| File | Change |
|---|---|
| `packages/kudosity-client/src/Resources/WebhooksResource.php` | add `ensure()`, drift comparison, fingerprint |
| `packages/kudosity-client/src/Webhooks/WebhookIdentity.php` | new; identity normalisation, its own class so it is directly testable |
| `packages/kudosity-client/src/Data/V2/EnsureResult.php` | new |
| `packages/kudosity-client/src/Enums/EnsureAction.php` | new |
| `packages/kudosity-client/src/Contracts/WebhookFingerprintStore.php` | new |
| `packages/kudosity-client/src/Webhooks/FileFingerprintStore.php` | new |
| `packages/kudosity-client/README.md` | raw-PHP recipes, no-CLI bootstrap, unknown-`messageRef` tolerance |
| `README.md` | `sync` usage, the environment gate and why it fails closed |
| `packages/kudosity-laravel/README.md` | **exists** — its Artisan-commands section documents `list`/`install`/`delete` and must gain `sync` plus the gate. The gate is a behaviour change to commands this file already documents, so omitting it leaves existing users with an unexplained refusal. |
| `packages/kudosity-laravel/src/Console/Commands/WebhookSyncCommand.php` | new |
| `packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsReceiverUrl.php` | new; URL guard extracted from `WebhookInstallCommand` |
| `packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsEnvironment.php` | new; the fail-closed allowlist |
| `packages/kudosity-laravel/src/Console/Commands/Concerns/ResolvesEventTypes.php` | new; extracted so `sync` does not duplicate `install`'s event validation |
| `packages/kudosity-laravel/src/Console/Commands/WebhookInstallCommand.php` | use both guards |
| `packages/kudosity-laravel/src/Console/Commands/WebhookDeleteCommand.php` | use the environment guard |
| `packages/kudosity-laravel/src/KudosityServiceProvider.php` | register the command |
| `packages/kudosity-laravel/config/kudosity.php` | `webhooks.sync` block |
| `packages/kudosity-client/tests/V2WebhookEnsureTest.php` | new |
| `tests/Unit/WebhookSyncCommandTest.php` | new |
