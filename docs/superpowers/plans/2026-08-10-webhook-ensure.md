# Idempotent V2 Webhook Registration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `WebhooksResource::ensure()` — a declarative, idempotent reconcile of one V2 webhook registration — plus a `kudosity:webhook:sync` command and a fail-closed environment gate on every command that writes to the shared account.

**Architecture:** `ensure()` lists the account's registrations, matches ours by normalised scheme+host+path (the signature lives in the query string and is exactly what drifts), then creates, `PUT`-replaces, or does nothing. Identity normalisation is its own class so it can be tested directly; drift comparison is private to the resource and tested through `ensure()`. Laravel's `sync` command wraps the primitive and adds only the two guards the client package cannot make: HTTPS-vs-plaintext and which environments may write.

**Tech Stack:** PHP 8.2+, Saloon v4, PHPUnit 11 (client package, standalone), Pest v4 + Orchestra Testbench (root suite), PHPStan level 6, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-08-10-webhook-ensure-design.md`

## Global Constraints

- PHP floor is `^8.2`. No syntax or stdlib newer than 8.2. The client suite is PHPUnit 11, not 12, for this reason.
- The client package's runtime dependencies stay at exactly two: `saloonphp/saloon` and `saloonphp/pagination-plugin`. No new `require` entries, and no `psr/simple-cache` even as a `suggest`.
- Every new file starts `<?php`, then a blank line, then `declare(strict_types=1);`, then a blank line, then the namespace.
- Client package namespace `ExpertSystems\Kudosity\`; Laravel package `ExpertSystems\Kudosity\Laravel\`.
- Client tests live flat in `packages/kudosity-client/tests/` (no `Unit/` subdirectory), extend `PHPUnit\Framework\TestCase`, are `final class`, use `#[CoversClass]` attributes, and name methods `test_lowercase_with_underscores`.
- Root suite tests live in `tests/Unit/` and use Pest `it('...', function () {})`.
- `ensure()` never deletes a registration, and never modifies one whose normalised identity differs from the desired URL's.
- The environment gate has no override flag. `WebhookDeleteCommand`'s existing `--force` skips the *confirmation prompt* only and must not bypass the gate.
- Run `composer format` and `composer analyse` before the final commit of each task that touches PHP.

---

### Task 1: Receiver URL identity

The comparison key for "is this registration ours". The query string is excluded because it holds the HMAC signature, which is the thing most likely to have drifted — including it would make every rotation look like a foreign registration and create a duplicate instead of repairing.

**Files:**
- Create: `packages/kudosity-client/src/Webhooks/WebhookIdentity.php`
- Test: `packages/kudosity-client/tests/V2WebhookIdentityTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `WebhookIdentity::of(string $url): string` — a normalised identity string. Equal return values mean "the same receiver endpoint".

- [ ] **Step 1: Write the failing test**

Create `packages/kudosity-client/tests/V2WebhookIdentityTest.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Webhooks\WebhookIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookIdentity::class)]
final class V2WebhookIdentityTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function equivalentUrls(): array
    {
        return [
            'the signature is ignored' => [
                'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=abc123',
                'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=DIFFERENT',
            ],
            'the whole query is ignored' => [
                'https://app.example.com/webhooks/kudosity/events?h=a&c=b&s=c',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'the host is case-insensitive' => [
                'https://APP.Example.COM/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'the scheme is case-insensitive' => [
                'HTTPS://app.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'a trailing slash is not a different endpoint' => [
                'https://app.example.com/webhooks/kudosity/events/',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'an explicit default port is not a different endpoint' => [
                'https://app.example.com:443/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
        ];
    }

    #[DataProvider('equivalentUrls')]
    public function test_treats_urls_differing_only_outside_the_endpoint_as_the_same(string $a, string $b): void
    {
        $this->assertSame(WebhookIdentity::of($a), WebhookIdentity::of($b));
    }

    /** @return array<string, array{string, string}> */
    public static function distinctUrls(): array
    {
        return [
            'a different host is a different app' => [
                'https://staging.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'a different path is a different receiver' => [
                'https://app.example.com/webhooks/kudosity/events',
                'https://app.example.com/hooks/kudosity/events',
            ],
            'a non-default port is part of the endpoint' => [
                'https://app.example.com:8443/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'the path is case-sensitive, unlike the host' => [
                'https://app.example.com/Webhooks/Kudosity/Events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'plaintext is not the same endpoint as TLS' => [
                'http://app.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
        ];
    }

    #[DataProvider('distinctUrls')]
    public function test_keeps_genuinely_different_endpoints_apart(string $a, string $b): void
    {
        $this->assertNotSame(WebhookIdentity::of($a), WebhookIdentity::of($b));
    }

    public function test_an_unparseable_url_falls_back_to_itself_and_matches_no_real_endpoint(): void
    {
        // A registration made by another tool can hold anything at all. Falling back
        // to the raw string means such a row simply never matches ours, which is the
        // safe outcome: we leave it alone rather than PUTting over it.
        $this->assertSame('http:///nonsense', WebhookIdentity::of('http:///nonsense'));
        $this->assertNotSame(
            WebhookIdentity::of('http:///nonsense'),
            WebhookIdentity::of('https://app.example.com/webhooks/kudosity/events'),
        );
    }

    public function test_a_url_with_no_path_normalises_to_a_single_slash_rather_than_an_empty_string(): void
    {
        // Otherwise "https://a.com" and "https://a.com/" are different identities and
        // one deploy creates a duplicate of the other.
        $this->assertSame(
            WebhookIdentity::of('https://app.example.com'),
            WebhookIdentity::of('https://app.example.com/'),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter V2WebhookIdentityTest`

Expected: FAIL — `Class "ExpertSystems\Kudosity\Webhooks\WebhookIdentity" not found`.

If `vendor/` is missing, run `cd packages/kudosity-client && composer install` first.

- [ ] **Step 3: Write the implementation**

Create `packages/kudosity-client/src/Webhooks/WebhookIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

/**
 * The comparison key for "is this registration ours".
 *
 * Scheme, host and path — **never the query string**, which is where the HMAC
 * signature lives. That exclusion is the whole point: rotating the signing key
 * changes the query and nothing else, and a registration whose signature no
 * longer verifies is precisely the one that needs repairing rather than
 * duplicating.
 *
 * Host and scheme are case-insensitive per RFC 3986; the path is not, and is
 * left alone. Userinfo participates, with the password reduced to a marker so
 * the identity stays safe to persist.
 */
final class WebhookIdentity
{
    /**
     * The default port for each scheme, dropped when stated explicitly.
     *
     * `https://a.com:443/x` and `https://a.com/x` are the same endpoint, and
     * treating them as two would register a duplicate.
     */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Normalise a receiver URL to its identity.
     *
     * An unparseable URL falls back to itself, unchanged. Registrations made by
     * other tools can hold anything, and a row that cannot be parsed should
     * simply never match ours — leaving it untouched is safer than guessing.
     */
    public static function of(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $identity = $scheme.'://';

        $user = $parts['user'] ?? '';
        $hasPassword = ($parts['pass'] ?? '') !== '';

        if ($user !== '' || $hasPassword) {
            // Either component alone is a credential, so both are checked:
            // `https://:TOKEN@host` is the bearer-token-as-Basic-Auth convention
            // and must not collapse to the uncredentialed identity, or ensure()
            // would PUT over a foreign registration. parse_url drops userinfo
            // entirely, which is what makes this necessary at all.
            //
            // The password is reduced to a marker rather than carried: this
            // string becomes a key in an on-disk fingerprint store, and a real
            // credential must never be written there. An empty password is
            // treated as no credential, so `https://user:@h` and
            // `https://user@h` are one identity — neither carries a secret.
            $identity .= $user.($hasPassword ? ':***' : '').'@';
        }

        $identity .= strtolower($parts['host']);

        $port = $parts['port'] ?? null;

        if ($port !== null && $port !== (self::DEFAULT_PORTS[$scheme] ?? null)) {
            $identity .= ':'.$port;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return $identity.($path === '' ? '/' : $path);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter V2WebhookIdentityTest`

Expected: PASS, 13 tests (6 + 5 data sets + 2 single tests).

- [ ] **Step 5: Format, analyse, commit**

```bash
composer format
composer analyse
git add packages/kudosity-client/src/Webhooks/WebhookIdentity.php packages/kudosity-client/tests/V2WebhookIdentityTest.php
git commit -m "feat(client): add WebhookIdentity, the receiver comparison key

Scheme, host and path, never the query string. The query holds the HMAC
signature, so including it would make a signing-key rotation look like a
foreign registration and create a duplicate instead of repairing the one
that stopped verifying."
```

---

### Task 2: `ensure()` — the create and unchanged paths

The reconcile itself, minus drift repair. `Skipped` exists from the start because the fingerprint store in Task 6 returns no DTO, and retrofitting nullability later would change a published type.

**Files:**
- Create: `packages/kudosity-client/src/Enums/EnsureAction.php`
- Create: `packages/kudosity-client/src/Data/V2/EnsureResult.php`
- Modify: `packages/kudosity-client/src/Resources/WebhooksResource.php`
- Test: `packages/kudosity-client/tests/V2WebhookEnsureTest.php`

**Interfaces:**
- Consumes: `WebhookIdentity::of(string $url): string` from Task 1.
- Produces:
  - `EnsureAction::Created|Updated|Unchanged|Skipped`, a string-backed enum with values `created`, `updated`, `unchanged`, `skipped`.
  - `EnsureResult` with public readonly `EnsureAction $action`, `?WebhookData $webhook`, `array $duplicates`.
  - `WebhooksResource::ensure(string $name, string $url, array $eventTypes = [], ?WebhookFilter $filter = null, ?int $rateLimit = null, bool $allowInsecureUrl = false): EnsureResult` — the `?WebhookFingerprintStore $store = null` parameter is appended in Task 6.

- [ ] **Step 1: Write the failing test**

Create `packages/kudosity-client/tests/V2WebhookEnsureTest.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\V2\EnsureResult;
use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Reconcile behaviour for WebhooksResource::ensure().
 *
 * CRUD itself is covered in V2WebhooksResourceTest; this file only covers what
 * ensure() adds — identity matching, drift detection, and the two things it must
 * never do (touch a foreign registration, delete anything).
 */
#[CoversClass(WebhooksResource::class)]
#[CoversClass(EnsureResult::class)]
#[CoversClass(EnsureAction::class)]
final class V2WebhookEnsureTest extends TestCase
{
    private const URL = 'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=sig';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function hookBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 'wh_1',
            'name' => 'Prod events',
            'url' => self::URL,
            'filter' => ['event_type' => ['SMS_STATUS', 'SMS_INBOUND']],
            'rate_limit' => 0,
            'is_sandbox' => false,
            'created_at' => '2026-08-10T09:00:00.000000Z',
            'updated_at' => '2026-08-10T09:00:00.000000Z',
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @return array{WebhooksResource, MockClient}
     */
    private static function resourceWith(array $existing, ?MockResponse $write = null): array
    {
        $responses = [
            ListWebhooksRequest::class => MockResponse::make(
                $existing === [] ? [] : ['webhooks' => $existing],
                200,
            ),
        ];

        if ($write !== null) {
            $responses[CreateWebhookRequest::class] = $write;
            $responses[UpdateWebhookRequest::class] = $write;
        }

        $mock = new MockClient($responses);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        return [new WebhooksResource($connector), $mock];
    }

    public function test_creates_a_registration_when_the_account_has_none(): void
    {
        // An empty account answers `{}` with the collection key absent entirely,
        // which is a different shape from `{"webhooks": []}` and has to be handled.
        [$resource, $mock] = self::resourceWith([], MockResponse::make(self::hookBody(), 201));

        $result = $resource->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus]);

        $this->assertSame(EnsureAction::Created, $result->action);
        $this->assertSame('wh_1', $result->webhook?->id);
        $this->assertSame([], $result->duplicates);
        // A create was actually issued, rather than the list response being
        // mistaken for one.
        $this->assertSame('POST', $mock->getLastPendingRequest()?->getMethod()->value);
    }

    public function test_does_nothing_when_the_registration_already_matches(): void
    {
        // The whole point: re-running after every deploy must be free. A second
        // write here would mean a duplicate registration per deploy.
        [$resource, $mock] = self::resourceWith([self::hookBody()]);

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
        ]);

        $this->assertSame(EnsureAction::Unchanged, $result->action);
        $this->assertSame('wh_1', $result->webhook?->id);
        // No CreateWebhookRequest or UpdateWebhookRequest was registered on the mock,
        // so any write attempt would have thrown rather than silently passing.
        $this->assertSame('GET', $mock->getLastPendingRequest()?->getMethod()->value);
    }

    public function test_treats_a_reordered_event_list_as_unchanged(): void
    {
        // Filter arrays are sets, not sequences. Reordering the array in application
        // code must not issue a PUT — otherwise every deploy writes.
        [$resource] = self::resourceWith([self::hookBody()]);

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsInbound,
            WebhookEventType::SmsStatus,
        ]);

        $this->assertSame(EnsureAction::Unchanged, $result->action);
    }

    public function test_rejects_a_plaintext_url_before_it_lists_anything(): void
    {
        // The guard must fire on the unchanged path too. If it only ran inside
        // create()/update(), an existing http:// registration would return Unchanged
        // and the plaintext rule would be silently unenforceable.
        [$resource] = self::resourceWith([]);

        $this->expectException(ValidationException::class);

        $resource->ensure('Prod events', 'http://app.example.com/webhooks/kudosity/events');
    }

    public function test_allows_a_plaintext_url_when_explicitly_opted_in(): void
    {
        [$resource] = self::resourceWith(
            [],
            MockResponse::make(self::hookBody(['url' => 'http://kudosity.test/webhooks/kudosity/events']), 201),
        );

        $result = $resource->ensure(
            'Local events',
            'http://kudosity.test/webhooks/kudosity/events',
            allowInsecureUrl: true,
        );

        $this->assertSame(EnsureAction::Created, $result->action);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter V2WebhookEnsureTest`

Expected: FAIL — `Class "ExpertSystems\Kudosity\Enums\EnsureAction" not found`.

- [ ] **Step 3: Create the two new types**

Create `packages/kudosity-client/src/Enums/EnsureAction.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * What {@see \ExpertSystems\Kudosity\Resources\WebhooksResource::ensure()} did.
 *
 * `Unchanged` and `Skipped` are both "nothing was written", and the difference
 * matters: `Unchanged` read the account and confirmed the registration is
 * correct, while `Skipped` trusted a stored fingerprint and never asked. Only
 * `Skipped` comes back without a DTO.
 */
enum EnsureAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
}
```

Create `packages/kudosity-client/src/Data/V2/EnsureResult.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use ExpertSystems\Kudosity\Enums\EnsureAction;

/**
 * The outcome of a webhook reconcile.
 *
 * `$webhook` is null **only** when `$action` is
 * {@see EnsureAction::Skipped} — a stored fingerprint matched, so no request was
 * made and there is no registration to return. Callers that always need the DTO
 * should pass no fingerprint store.
 *
 * `$duplicates` holds any further registrations sharing the same receiver
 * identity. They are reported rather than deleted: removing one is
 * unrecoverable, and nothing here can know which is load-bearing.
 */
final readonly class EnsureResult
{
    /**
     * @param  array<int, WebhookData>  $duplicates
     */
    public function __construct(
        public EnsureAction $action,
        public ?WebhookData $webhook = null,
        public array $duplicates = [],
    ) {}
}
```

- [ ] **Step 4: Add `ensure()` to the resource**

In `packages/kudosity-client/src/Resources/WebhooksResource.php`, add these imports alongside the existing ones:

```php
use ExpertSystems\Kudosity\Data\V2\EnsureResult;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Webhooks\WebhookIdentity;
```

Then insert `ensure()` immediately after `create()`:

```php
    /**
     * Converge the account on one webhook registration, and report what changed.
     *
     * Idempotent: safe to call on every deploy, and free when nothing has moved.
     * Matching is by **receiver identity** — scheme, host and path, never the
     * query string — see {@see WebhookIdentity}.
     *
     * This exists because the failure worth catching is not a missing
     * registration, which is loud, but a stale one. The receiver URL carries an
     * HMAC signature; rotating the signing key, changing the route prefix or
     * moving the app leaves a registration that still exists and still receives
     * deliveries, every one of which the receiver then rejects — silently,
     * because Kudosity has no channel to report that your endpoint refused it.
     * A "does one exist?" check passes in every one of those cases.
     *
     * Never deletes, and never touches a registration whose identity differs.
     *
     * @param  array<int, WebhookEventType|string>  $eventTypes
     *
     * @throws KudosityException
     */
    public function ensure(
        string $name,
        string $url,
        array $eventTypes = [],
        ?WebhookFilter $filter = null,
        ?int $rateLimit = null,
        bool $allowInsecureUrl = false,
    ): EnsureResult {
        // Up front, not left to create()/update(): on the unchanged path no write
        // request is built, so a guard living only in the request classes would let
        // an existing plaintext registration return Unchanged forever.
        CreateWebhookRequest::guardUrl($url, $allowInsecureUrl);

        $desired = self::mergeEventTypes($filter, $eventTypes);
        $identity = WebhookIdentity::of($url);

        $matches = array_values(array_filter(
            $this->all(),
            static fn (WebhookData $hook): bool => WebhookIdentity::of($hook->url) === $identity,
        ));

        if ($matches === []) {
            return new EnsureResult(
                EnsureAction::Created,
                $this->create(
                    name: $name,
                    url: $url,
                    filter: $desired,
                    rateLimit: $rateLimit,
                    allowInsecureUrl: $allowInsecureUrl,
                ),
            );
        }

        $existing = array_shift($matches);

        if (self::matchesDesired($existing, $name, $url, $desired, $rateLimit)) {
            return new EnsureResult(EnsureAction::Unchanged, $existing, $matches);
        }

        return new EnsureResult(
            EnsureAction::Updated,
            $this->update(
                id: $existing->id,
                name: $name,
                url: $url,
                filter: $desired,
                rateLimit: $rateLimit,
                allowInsecureUrl: $allowInsecureUrl,
            ),
            $matches,
        );
    }
```

Add the two private helpers at the end of the class, after `mergeEventTypes()`:

```php
    /**
     * Whether a registration already expresses the desired state.
     *
     * `$rateLimit` is compared **only when the caller supplied one**. The API
     * echoes `0` to mean "system default" rather than "no requests allowed"
     * ({@see WebhookData::hasRateLimit()}), so treating a caller's `null` as a
     * mismatch against a stored `0` would make every single deploy issue a `PUT`
     * that changes nothing — and the unchanged path would then never run in
     * production.
     */
    private static function matchesDesired(
        WebhookData $existing,
        string $name,
        string $url,
        ?WebhookFilter $desired,
        ?int $rateLimit,
    ): bool {
        if ($existing->name !== $name || $existing->url !== $url) {
            return false;
        }

        if (self::comparableFilter($existing->filter) !== self::comparableFilter($desired)) {
            return false;
        }

        return $rateLimit === null || $existing->rateLimit === $rateLimit;
    }

    /**
     * A filter reduced to something `===` can compare.
     *
     * Filter values are sets, not sequences — the API ORs within one array — so
     * reordering event types in application code must not read as drift. Sorted
     * and keyed for that reason, and an absent filter compares equal to an empty
     * one because both mean "every event".
     *
     * @return array<string, array<int, string>>
     */
    private static function comparableFilter(?WebhookFilter $filter): array
    {
        $comparable = $filter?->toArray() ?? [];

        foreach ($comparable as $key => $values) {
            sort($values);
            $comparable[$key] = array_values(array_unique($values));
        }

        ksort($comparable);

        return $comparable;
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter V2WebhookEnsureTest`

Expected: PASS, 5 tests.

- [ ] **Step 6: Run the whole client suite for regressions**

Run: `cd packages/kudosity-client && vendor/bin/phpunit`

Expected: PASS. `ensure()` is additive, so any failure here is a real regression — most likely `RequestShapeTest` or `V2WebhooksResourceTest` reacting to a changed `WebhooksResource`.

- [ ] **Step 7: Format, analyse, commit**

```bash
composer format
composer analyse
git add packages/kudosity-client/src/Enums/EnsureAction.php packages/kudosity-client/src/Data/V2/EnsureResult.php packages/kudosity-client/src/Resources/WebhooksResource.php packages/kudosity-client/tests/V2WebhookEnsureTest.php
git commit -m "feat(client): add WebhooksResource::ensure()

Lists the account, matches our registration by receiver identity, and
creates it when absent. Guards the URL before listing rather than leaving
it to the request classes: on the unchanged path no write is built, so a
guard living only there would let an existing plaintext registration
return Unchanged forever.

rateLimit is compared only when the caller supplied one. The API echoes 0
for 'system default', so comparing a caller's null against a stored 0
would issue a no-op PUT on every deploy."
```

---

### Task 3: Drift repair

Each of the four drift triggers from the spec, and the `rateLimit` regression guard.

**Files:**
- Modify: `packages/kudosity-client/tests/V2WebhookEnsureTest.php`

**Interfaces:**
- Consumes: `ensure()` and `EnsureAction` from Task 2. No production code changes — `matchesDesired()` already covers these cases, and these tests prove it rather than assuming it.

- [ ] **Step 1: Write the failing tests**

Append these methods to `V2WebhookEnsureTest`:

```php
    public function test_repairs_a_stale_signature_in_place_rather_than_registering_a_duplicate(): void
    {
        // The drift this design exists for. The signing key rotated, so the stored
        // URL's `s` no longer verifies and every delivery is rejected — but the
        // registration still exists, so a presence check sees nothing wrong.
        $stale = self::hookBody(['url' => 'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=OLD']);

        [$resource, $mock] = self::resourceWith([$stale], MockResponse::make(self::hookBody(), 200));

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
        ]);

        $this->assertSame(EnsureAction::Updated, $result->action);
        // Repaired in place: same id, so nothing downstream that recorded it breaks.
        $this->assertSame('wh_1', $result->webhook?->id);
        $this->assertSame('PUT', $mock->getLastPendingRequest()?->getMethod()->value);
    }

    public function test_repairs_a_changed_route_prefix(): void
    {
        // A different path is a different identity, so this registers rather than
        // updating — the old one is left alone for a human to delete, because
        // deleting is unrecoverable and it may still be serving another app.
        [$resource, $mock] = self::resourceWith(
            [self::hookBody(['url' => 'https://app.example.com/hooks/kudosity/events?h=a&s=b'])],
            MockResponse::make(self::hookBody(), 201),
        );

        $result = $resource->ensure('Prod events', self::URL);

        $this->assertSame(EnsureAction::Created, $result->action);
        $this->assertSame('POST', $mock->getLastPendingRequest()?->getMethod()->value);
    }

    public function test_repairs_a_changed_event_subscription(): void
    {
        // Not a reorder — a genuinely different set. Subscribing to fewer events than
        // the code expects means silence, not errors.
        [$resource] = self::resourceWith([self::hookBody()], MockResponse::make(self::hookBody(), 200));

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
            WebhookEventType::LinkHit,
        ]);

        $this->assertSame(EnsureAction::Updated, $result->action);
    }

    public function test_repairs_a_changed_name(): void
    {
        [$resource] = self::resourceWith(
            [self::hookBody(['name' => 'Old name'])],
            MockResponse::make(self::hookBody(), 200),
        );

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
        ]);

        $this->assertSame(EnsureAction::Updated, $result->action);
    }

    public function test_sends_the_whole_shape_on_a_repair_because_put_replaces_rather_than_patches(): void
    {
        // PUT is a replace: omitting the name does not preserve it, the API answers
        // 400. So a repair that only carried the changed field would fail.
        [$resource, $mock] = self::resourceWith(
            [self::hookBody(['name' => 'Old name'])],
            MockResponse::make(self::hookBody(), 200),
        );

        $resource->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], rateLimit: 250);

        $body = (array) $mock->getLastPendingRequest()?->body()?->all();

        $this->assertSame('Prod events', $body['name']);
        $this->assertSame(self::URL, $body['url']);
        $this->assertSame(['event_type' => ['SMS_STATUS']], $body['filter']);
        $this->assertSame(250, $body['rate_limit']);
    }

    public function test_a_null_rate_limit_against_an_echoed_zero_is_not_drift(): void
    {
        // THE regression guard. `rate_limit: 0` from the API means "system default",
        // not "no requests allowed". If a caller-supplied null compared unequal to a
        // stored 0, every deploy would PUT a no-op change, forever, and the
        // Unchanged branch would never execute in production.
        //
        // No write response is registered on the mock, so a PUT attempt throws.
        [$resource] = self::resourceWith([self::hookBody(['rate_limit' => 0])]);

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
        ]);

        $this->assertSame(EnsureAction::Unchanged, $result->action);
    }

    public function test_an_explicit_rate_limit_differing_from_the_stored_one_is_drift(): void
    {
        [$resource] = self::resourceWith(
            [self::hookBody(['rate_limit' => 100])],
            MockResponse::make(self::hookBody(['rate_limit' => 250]), 200),
        );

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
        ], rateLimit: 250);

        $this->assertSame(EnsureAction::Updated, $result->action);
    }
```

- [ ] **Step 2: Run them**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter V2WebhookEnsureTest`

Expected: PASS, 12 tests. These characterise behaviour Task 2 already implements. **If any fails, fix `matchesDesired()` — do not weaken the test.** The most likely genuine failure is `test_a_null_rate_limit_against_an_echoed_zero_is_not_drift`, which fails if the `$rateLimit === null ||` short-circuit was dropped.

- [ ] **Step 3: Commit**

```bash
git add packages/kudosity-client/tests/V2WebhookEnsureTest.php
git commit -m "test(client): cover every webhook drift trigger

Stale signature, changed prefix, changed event set, changed name, and the
rate-limit trap where a caller's null must not read as drift against the
API's echoed 0."
```

---

### Task 4: Duplicate and foreign-registration safety

The two things `ensure()` must never do. One account legitimately serves several apps, so this is the difference between a safe tool and one that breaks a colleague's integration.

**Files:**
- Modify: `packages/kudosity-client/tests/V2WebhookEnsureTest.php`

**Interfaces:**
- Consumes: `ensure()`, `EnsureResult::$duplicates` from Task 2. No production changes expected.

- [ ] **Step 1: Write the tests**

Append to `V2WebhookEnsureTest`:

```php
    public function test_leaves_a_registration_on_another_host_completely_alone(): void
    {
        // One Kudosity account backs every environment here, so staging's
        // registration sits in the same list as production's. Touching it would
        // break another environment's callbacks.
        //
        // A write response IS registered, because a create is the expected
        // outcome. What proves the foreign row was left alone is the pair of
        // assertions below: the action is Created rather than Updated, and the
        // request issued was a POST rather than a PUT. Asserting the action
        // alone would not — `resourceWith()` answers both the create and the
        // update with the same body, so a wrongly-matched PUT would return a
        // populated DTO too.
        $foreign = self::hookBody([
            'id' => 'wh_staging',
            'name' => 'Staging events',
            'url' => 'https://staging.example.com/webhooks/kudosity/events?h=a&s=b',
        ]);

        [$resource, $mock] = self::resourceWith([$foreign], MockResponse::make(self::hookBody(), 201));

        $result = $resource->ensure('Prod events', self::URL);

        $this->assertSame(EnsureAction::Created, $result->action);
        $this->assertSame('wh_1', $result->webhook?->id);
        $this->assertSame([], $result->duplicates);
        $this->assertSame('POST', $mock->getLastPendingRequest()?->getMethod()->value);
    }

    public function test_leaves_a_registration_on_the_same_host_but_a_different_path_alone(): void
    {
        $sibling = self::hookBody([
            'id' => 'wh_other',
            'url' => 'https://app.example.com/webhooks/other-vendor/events?h=a&s=b',
        ]);

        [$resource] = self::resourceWith([$sibling], MockResponse::make(self::hookBody(), 201));

        $this->assertSame(EnsureAction::Created, $resource->ensure('Prod events', self::URL)->action);
    }

    public function test_reports_further_matches_as_duplicates_and_deletes_none_of_them(): void
    {
        // Two registrations on one identity, most likely from the old install
        // command being run twice. Repair the first, report the rest. Deleting is
        // unrecoverable and nothing here can know which one is load-bearing.
        //
        // DeleteWebhookRequest is deliberately absent from the mock: a delete
        // attempt fails the test rather than passing quietly.
        [$resource] = self::resourceWith([
            self::hookBody(['id' => 'wh_1', 'name' => 'Old name']),
            self::hookBody(['id' => 'wh_2']),
            self::hookBody(['id' => 'wh_3']),
        ], MockResponse::make(self::hookBody(), 200));

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
        ]);

        $this->assertSame(EnsureAction::Updated, $result->action);
        $this->assertSame('wh_1', $result->webhook?->id);
        $this->assertCount(2, $result->duplicates);
        $this->assertSame(['wh_2', 'wh_3'], array_map(
            static fn (WebhookData $hook): string => $hook->id,
            $result->duplicates,
        ));
    }

    public function test_reports_duplicates_even_when_the_first_match_needs_no_repair(): void
    {
        // Otherwise a correct-but-duplicated account looks clean, and the duplicate
        // keeps delivering a second copy of every event indefinitely.
        [$resource] = self::resourceWith([
            self::hookBody(['id' => 'wh_1']),
            self::hookBody(['id' => 'wh_2']),
        ]);

        $result = $resource->ensure('Prod events', self::URL, [
            WebhookEventType::SmsStatus,
            WebhookEventType::SmsInbound,
        ]);

        $this->assertSame(EnsureAction::Unchanged, $result->action);
        $this->assertCount(1, $result->duplicates);
    }
```

- [ ] **Step 2: Run them**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter V2WebhookEnsureTest`

Expected: PASS, 16 tests.

- [ ] **Step 3: Commit**

```bash
git add packages/kudosity-client/tests/V2WebhookEnsureTest.php
git commit -m "test(client): pin what ensure() must never touch

One account backs every environment, so staging's registration sits in
production's list. Foreign hosts and foreign paths are left alone, and
duplicates on our own identity are reported rather than deleted."
```

---

### Task 5: The optional fingerprint store

Lets a caller skip the list request when nothing has changed. Off by default; never authoritative — a missing or stale file costs one `GET`, never a wrong outcome.

**Files:**
- Create: `packages/kudosity-client/src/Contracts/WebhookFingerprintStore.php`
- Create: `packages/kudosity-client/src/Webhooks/FileFingerprintStore.php`
- Modify: `packages/kudosity-client/src/Resources/WebhooksResource.php`
- Test: `packages/kudosity-client/tests/V2WebhookFingerprintTest.php`

**Interfaces:**
- Consumes: `ensure()` from Task 2, `WebhookIdentity::of()` from Task 1.
- Produces:
  - `WebhookFingerprintStore` with `get(string $key): ?string` and `put(string $key, string $fingerprint): void`.
  - `FileFingerprintStore::__construct(string $path)`.
  - `ensure()` gains a final parameter `?WebhookFingerprintStore $store = null`.

- [ ] **Step 1: Write the failing test**

Create `packages/kudosity-client/tests/V2WebhookFingerprintTest.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Contracts\WebhookFingerprintStore;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use ExpertSystems\Kudosity\Webhooks\FileFingerprintStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

#[CoversClass(FileFingerprintStore::class)]
#[CoversClass(WebhooksResource::class)]
final class V2WebhookFingerprintTest extends TestCase
{
    private const URL = 'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=sig';

    private string $path;

    protected function setUp(): void
    {
        // Deterministic per test method, so a leftover file from a previous run
        // cannot make a test pass for the wrong reason.
        $this->path = sys_get_temp_dir().'/kudosity-fingerprint-'.$this->name().'.json';
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @return array<string, mixed> */
    private static function hookBody(): array
    {
        return [
            'id' => 'wh_1',
            'name' => 'Prod events',
            'url' => self::URL,
            'filter' => ['event_type' => ['SMS_STATUS']],
            'rate_limit' => 0,
            'is_sandbox' => false,
            'created_at' => '2026-08-10T09:00:00.000000Z',
            'updated_at' => '2026-08-10T09:00:00.000000Z',
        ];
    }

    private function resource(MockClient $mock): WebhooksResource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        return new WebhooksResource($connector);
    }

    public function test_a_file_store_round_trips_a_fingerprint(): void
    {
        $store = new FileFingerprintStore($this->path);

        $this->assertNull($store->get('https://app.example.com/webhooks/kudosity/events'));

        $store->put('https://app.example.com/webhooks/kudosity/events', 'abc123');

        $this->assertSame('abc123', $store->get('https://app.example.com/webhooks/kudosity/events'));
    }

    public function test_a_file_store_keeps_entries_for_different_identities_apart(): void
    {
        $store = new FileFingerprintStore($this->path);

        $store->put('https://a.example.com/h', 'fp-a');
        $store->put('https://b.example.com/h', 'fp-b');

        $this->assertSame('fp-a', $store->get('https://a.example.com/h'));
        $this->assertSame('fp-b', $store->get('https://b.example.com/h'));
    }

    public function test_a_corrupt_store_file_reads_as_empty_rather_than_throwing(): void
    {
        // A half-written file must degrade to one extra GET, not to a crash on boot.
        file_put_contents($this->path, '{not json');

        $this->assertNull((new FileFingerprintStore($this->path))->get('https://a.example.com/h'));
    }

    public function test_an_unwritable_path_throws_rather_than_silently_losing_the_optimisation(): void
    {
        // Loud, because a silent failure turns a once-per-deploy GET into a
        // per-call GET that nobody notices. The registration is already correct by
        // the time this fires, and re-running is idempotent, so failing costs
        // nothing but the operator's attention.
        $store = new FileFingerprintStore('/proc/definitely-not-writable/fingerprints.json');

        $this->expectException(KudosityException::class);

        $store->put('https://a.example.com/h', 'abc');
    }

    public function test_the_second_ensure_makes_no_http_request_at_all(): void
    {
        // The point of the store. An empty MockClient means ANY request throws, so
        // the assertion is that nothing was attempted.
        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
        ]);

        $store = new FileFingerprintStore($this->path);

        $first = $this->resource($mock)->ensure(
            'Prod events',
            self::URL,
            [WebhookEventType::SmsStatus],
            store: $store,
        );

        $this->assertSame(EnsureAction::Unchanged, $first->action);

        $second = $this->resource(new MockClient([]))->ensure(
            'Prod events',
            self::URL,
            [WebhookEventType::SmsStatus],
            store: $store,
        );

        $this->assertSame(EnsureAction::Skipped, $second->action);
        // Skipped is the only action with no DTO: nothing was read, so there is
        // nothing to return.
        $this->assertNull($second->webhook);
    }

    public function test_a_changed_url_re_fires_even_though_a_fingerprint_exists(): void
    {
        // A rotated signing key changes the URL but not the identity, so the stored
        // fingerprint is present and must not be trusted.
        $store = new FileFingerprintStore($this->path);

        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
        ]);

        $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store);

        $rotated = 'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=ROTATED';

        $repaired = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
            \ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest::class => MockResponse::make(
                ['id' => 'wh_1'] + self::hookBody(),
                200,
            ),
        ]);

        $result = $this->resource($repaired)->ensure(
            'Prod events',
            $rotated,
            [WebhookEventType::SmsStatus],
            store: $store,
        );

        $this->assertSame(EnsureAction::Updated, $result->action);
    }

    public function test_a_changed_event_set_re_fires(): void
    {
        $store = new FileFingerprintStore($this->path);

        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
        ]);

        $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store);

        $repaired = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
            \ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest::class => MockResponse::make(self::hookBody(), 200),
        ]);

        $result = $this->resource($repaired)->ensure(
            'Prod events',
            self::URL,
            [WebhookEventType::SmsStatus, WebhookEventType::LinkHit],
            store: $store,
        );

        $this->assertSame(EnsureAction::Updated, $result->action);
    }

    public function test_a_store_is_written_after_a_create_so_the_next_call_skips(): void
    {
        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make([], 200),
            CreateWebhookRequest::class => MockResponse::make(self::hookBody(), 201),
        ]);

        $store = new FileFingerprintStore($this->path);

        $this->assertSame(
            EnsureAction::Created,
            $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store)->action,
        );

        $this->assertSame(
            EnsureAction::Skipped,
            $this->resource(new MockClient([]))->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store)->action,
        );
    }

    public function test_a_custom_store_implementation_is_enough_to_participate(): void
    {
        // The interface is two methods precisely so wrapping a PSR-16 cache, or
        // anything else, needs no adapter shipped here.
        $store = new class implements WebhookFingerprintStore
        {
            /** @var array<string, string> */
            public array $entries = [];

            public function get(string $key): ?string
            {
                return $this->entries[$key] ?? null;
            }

            public function put(string $key, string $fingerprint): void
            {
                $this->entries[$key] = $fingerprint;
            }
        };

        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make([], 200),
            CreateWebhookRequest::class => MockResponse::make(self::hookBody(), 201),
        ]);

        $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store);

        $this->assertArrayHasKey('https://app.example.com/webhooks/kudosity/events', $store->entries);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter V2WebhookFingerprintTest`

Expected: FAIL — `Interface "ExpertSystems\Kudosity\Contracts\WebhookFingerprintStore" not found`.

- [ ] **Step 3: Write the contract**

Create `packages/kudosity-client/src/Contracts/WebhookFingerprintStore.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

/**
 * Somewhere to remember that a webhook registration was already reconciled.
 *
 * Two methods, and no dependency on a caching library: wrapping a PSR-16 cache
 * is a handful of lines in a consumer's own code, which is cheaper than adding
 * a dependency to a package that has two.
 *
 * **Never authoritative.** A store only lets
 * {@see \ExpertSystems\Kudosity\Resources\WebhooksResource::ensure()} skip the
 * list request. A missing, stale or corrupt entry costs one `GET` and can never
 * produce a wrong registration.
 */
interface WebhookFingerprintStore
{
    /**
     * The stored fingerprint for a receiver identity, or null when unknown.
     */
    public function get(string $key): ?string;

    /**
     * Record the fingerprint for a receiver identity.
     */
    public function put(string $key, string $fingerprint): void;
}
```

- [ ] **Step 4: Write the file-backed implementation**

Create `packages/kudosity-client/src/Webhooks/FileFingerprintStore.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use ExpertSystems\Kudosity\Contracts\WebhookFingerprintStore;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use JsonException;

/**
 * A fingerprint store in a JSON file.
 *
 * Shipped because the audience for a dependency-free store is exactly the
 * audience with no cache library — a raw-PHP consumer with a deploy script and
 * a writable directory.
 *
 * Reads are forgiving and writes are not, deliberately. A corrupt or missing
 * file degrades to one extra `GET`, so throwing on read would turn a harmless
 * state into an outage. An unwritable path is a configuration error that would
 * otherwise silently turn a once-per-deploy request into a per-call one, so it
 * throws — and by the time it fires the registration is already correct, with
 * re-running idempotent, so failing loudly costs nothing but attention.
 */
final class FileFingerprintStore implements WebhookFingerprintStore
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function get(string $key): ?string
    {
        $entries = $this->read();

        $value = $entries[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $fingerprint): void
    {
        $entries = $this->read();
        $entries[$key] = $fingerprint;

        try {
            $json = json_encode($entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new KudosityException("Could not encode the webhook fingerprint store: {$e->getMessage()}");
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new KudosityException("Could not create the webhook fingerprint directory: {$directory}");
        }

        if (@file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new KudosityException(
                "Could not write the webhook fingerprint store at {$this->path}. ".
                'It is only an optimisation — drop the store argument to reconcile against the API every time.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A half-written file. One extra GET is the correct cost.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
```

- [ ] **Step 5: Wire the store into `ensure()`**

In `WebhooksResource`, add the import:

```php
use ExpertSystems\Kudosity\Contracts\WebhookFingerprintStore;
```

Change the `ensure()` signature to append the parameter:

```php
        bool $allowInsecureUrl = false,
        ?WebhookFingerprintStore $store = null,
    ): EnsureResult {
```

Immediately after the `guardUrl()` call and the `$desired`/`$identity` assignments, insert the short-circuit:

```php
        $fingerprint = self::fingerprint($name, $url, $desired, $rateLimit);

        if ($store !== null && $store->get($identity) === $fingerprint) {
            // No DTO to return: nothing was read. See EnsureResult.
            return new EnsureResult(EnsureAction::Skipped);
        }
```

Then replace each of the three `return new EnsureResult(...)` statements so the fingerprint is recorded on the way out. The create arm becomes:

```php
        if ($matches === []) {
            $created = $this->create(
                name: $name,
                url: $url,
                filter: $desired,
                rateLimit: $rateLimit,
                allowInsecureUrl: $allowInsecureUrl,
            );

            $store?->put($identity, $fingerprint);

            return new EnsureResult(EnsureAction::Created, $created);
        }
```

The unchanged arm becomes:

```php
        if (self::matchesDesired($existing, $name, $url, $desired, $rateLimit)) {
            $store?->put($identity, $fingerprint);

            return new EnsureResult(EnsureAction::Unchanged, $existing, $matches);
        }
```

And the update arm:

```php
        $updated = $this->update(
            id: $existing->id,
            name: $name,
            url: $url,
            filter: $desired,
            rateLimit: $rateLimit,
            allowInsecureUrl: $allowInsecureUrl,
        );

        $store?->put($identity, $fingerprint);

        return new EnsureResult(EnsureAction::Updated, $updated, $matches);
```

Add the fingerprint helper next to the other private statics:

```php
    /**
     * A stable digest of the desired state.
     *
     * Covers the whole filter rather than event types alone, so changing a sender
     * or status condition re-fires as readily as changing an event type. Values
     * are sorted through {@see self::comparableFilter()} so that reordering an
     * array in application code does not invalidate the entry.
     *
     * `$rateLimit` folds in as the literal string `default` when null, which is
     * distinct from any integer and so cannot collide with an explicit limit.
     */
    private static function fingerprint(
        string $name,
        string $url,
        ?WebhookFilter $filter,
        ?int $rateLimit,
    ): string {
        return hash('sha256', implode("\0", [
            $name,
            $url,
            json_encode(self::comparableFilter($filter)) ?: '{}',
            $rateLimit === null ? 'default' : (string) $rateLimit,
        ]));
    }
```

- [ ] **Step 6: Run the tests**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter 'V2WebhookFingerprintTest|V2WebhookEnsureTest'`

Expected: PASS, 25 tests — 16 from `V2WebhookEnsureTest` plus the 9 defined
above. (An earlier draft of this step said 26 and "10 new tests"; the code block
defines 9. The code is authoritative.)

Note a gap this count exposes: `ensure()` has three `$store?->put()` call sites —
create, unchanged and update — and the tests above assert the effect of only the
first two. `test_a_changed_url_re_fires_even_though_a_fingerprint_exists`
executes the update-path write but asserts nothing about it, so deleting that
third `put()` would leave the suite green. The consequence is a lost
optimisation rather than a wrong registration (the next call does a full GET
instead of skipping), so this is recorded rather than fixed here.

If `test_an_unwritable_path_throws_rather_than_silently_losing_the_optimisation` fails because the sandbox permits writing to `/proc/...`, substitute a path under a directory created with `0o400` in the test's `setUp()`. Do not delete the test — an unwritable store is the failure mode most likely to appear in a real deploy.

- [ ] **Step 7: Full suite, format, analyse, commit**

```bash
cd packages/kudosity-client && vendor/bin/phpunit && cd ../..
composer format
composer analyse
git add packages/kudosity-client/src/Contracts/WebhookFingerprintStore.php packages/kudosity-client/src/Webhooks/FileFingerprintStore.php packages/kudosity-client/src/Resources/WebhooksResource.php packages/kudosity-client/tests/V2WebhookFingerprintTest.php
git commit -m "feat(client): let ensure() skip the list request via a fingerprint

Two-method interface rather than a PSR-16 dependency, plus a file-backed
implementation for the raw-PHP consumers who have no cache library.

Never authoritative: a missing, stale or corrupt entry costs one GET and
can never produce a wrong registration. Reads are forgiving for that
reason; writes throw, because silently degrading to a per-call GET is a
cost nobody would notice."
```

---

### Task 6: Extract the receiver URL guard

Pure refactor, no behaviour change. `sync` must apply exactly the guard `install` applies — if the two drift, `sync` registers a URL `install`'s rules would have rejected, and the receiver then rejects every delivery.

**Files:**
- Create: `packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsReceiverUrl.php`
- Modify: `packages/kudosity-laravel/src/Console/Commands/WebhookInstallCommand.php:42-72`

**Interfaces:**
- Consumes: nothing new.
- Produces: trait `GuardsReceiverUrl` with `protected function resolveReceiverUrl(CallbackUrlBuilder $urls): ?array` returning `['url' => string, 'allowInsecure' => bool]`, or `null` when the URL is rejected (having already printed the explanation). Used by `WebhookInstallCommand` and, in Task 8, `WebhookSyncCommand`.

- [ ] **Step 1: Confirm the existing tests pass before touching anything**

Run: `vendor/bin/pest tests/Unit/WebhookCommandsTest.php`

Expected: PASS. This is the baseline the refactor must preserve — these tests are the specification of the guard's behaviour, including the exact strings `must be HTTPS`, `APP_URL` and `APP_ENV=local`.

- [ ] **Step 2: Create the trait**

Create `packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsReceiverUrl.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns;

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;

/**
 * Resolve and vet the URL a webhook registration should point at.
 *
 * Shared by `install` and `sync` rather than duplicated, because a divergence
 * between them is invisible: `sync` would register a URL `install` would have
 * rejected, and the receiver would then refuse every delivery with a 403 that
 * Kudosity has no way of reporting back.
 *
 * @phpstan-require-extends \Illuminate\Console\Command
 */
trait GuardsReceiverUrl
{
    /**
     * The handler slot carries this marker rather than a class name.
     *
     * V2 deliveries dispatch Laravel events rather than a handler class, so there
     * is no handler to name. But the signature is only produced when `h` or `c` is
     * present, and the receiver requires `h` — so the slot is used to make the URL
     * signed at all. See WebhookController::events().
     */
    public const HANDLER_MARKER = 'kudosity.v2.events';

    /**
     * The vetted receiver URL, or null when it was rejected.
     *
     * @return array{url: string, allowInsecure: bool}|null
     */
    protected function resolveReceiverUrl(CallbackUrlBuilder $urls): ?array
    {
        /** @var string|null $override */
        $override = $this->option('url');

        $url = (string) ($override ?: $urls->build(CallbackType::EVENTS, self::HANDLER_MARKER));

        // Plaintext is allowed only on a local environment. Laravel knows which
        // environment it is; the client package does not, which is why the
        // decision is made here and passed down explicitly.
        $allowInsecure = app()->environment('local') && str_starts_with(strtolower($url), 'http://');

        if ($allowInsecure) {
            $this->components->warn(
                'Registering a plaintext http:// receiver because APP_ENV=local. Deliveries carry message '.
                'content and are unsigned, so never do this outside local development.'
            );
        }

        if (! $allowInsecure && ! str_starts_with(strtolower($url), 'https://')) {
            // Caught here rather than let through to the request class, so the
            // operator gets an explanation naming the cause rather than a
            // ValidationException about a URL they never typed.
            $this->components->error('The receiver URL must be HTTPS.');
            $this->line(
                "  Resolved: <comment>{$url}</comment>\n".
                '  It comes from APP_URL plus kudosity.webhooks.prefix. Set APP_URL to an https:// address, '.
                "or pass --url= explicitly.\n".
                '  Deliveries carry message content and phone numbers and are unsigned, so a plaintext '.
                'endpoint is readable and forgeable in transit.'
            );

            return null;
        }

        return ['url' => $url, 'allowInsecure' => $allowInsecure];
    }
}
```

- [ ] **Step 3: Use the trait in `WebhookInstallCommand`**

In `packages/kudosity-laravel/src/Console/Commands/WebhookInstallCommand.php`:

Add `use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\GuardsReceiverUrl;` to the imports, and remove the now-unused `use ExpertSystems\Kudosity\Callbacks\CallbackType;`.

Inside the class, add `use GuardsReceiverUrl;` as the first line of the body and **delete** the local `HANDLER_MARKER` constant along with its docblock — the trait now owns it, and `WebhookInstallCommand::HANDLER_MARKER` continues to resolve because trait constants are inherited by the using class.

Replace lines 44–72 of `handle()` — everything from `$url = (string) (...)` down to and including the `return self::FAILURE;` block that follows the HTTPS check — with:

```php
        $receiver = $this->resolveReceiverUrl($urls);

        if ($receiver === null) {
            return self::FAILURE;
        }

        ['url' => $url, 'allowInsecure' => $allowInsecure] = $receiver;
```

- [ ] **Step 4: Run the tests to verify nothing changed**

Run: `vendor/bin/pest tests/Unit/WebhookCommandsTest.php`

Expected: PASS, same count as Step 1. A failure here means the extraction changed behaviour — most likely a lost warning string or a dropped `--url` option read.

- [ ] **Step 5: Run the whole root suite, format, analyse, commit**

```bash
composer test
composer format
composer analyse
git add packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsReceiverUrl.php packages/kudosity-laravel/src/Console/Commands/WebhookInstallCommand.php
git commit -m "refactor(laravel): extract the receiver URL guard into a concern

No behaviour change. sync needs the identical guard, and a divergence
between the two would be invisible: sync would register a URL install
would have rejected and the receiver would 403 every delivery."
```

---

### Task 7: The fail-closed environment gate

One Kudosity account backs every environment and every environment sends from the same sender, so no webhook filter can partition traffic. Registering from staging means staging receives production message bodies and phone numbers. This gate is the only control against that, which is why it has no override.

**Files:**
- Create: `packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsEnvironment.php`
- Modify: `packages/kudosity-laravel/src/Console/Commands/WebhookInstallCommand.php`
- Modify: `packages/kudosity-laravel/src/Console/Commands/WebhookDeleteCommand.php`
- Modify: `packages/kudosity-laravel/config/kudosity.php`
- Test: `tests/Unit/WebhookEnvironmentGateTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: trait `GuardsEnvironment` with `protected function environmentPermitsWrites(): bool`, which prints the refusal itself when it returns false. Config key `kudosity.webhooks.sync.environments`, an array of environment names defaulting to `['production']`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/WebhookEnvironmentGateTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Resources\WebhooksResource;

/**
 * Bind a webhooks resource that fails the test if anything touches it.
 *
 * The gate's contract is not "it prints a message" but "it makes no request".
 * A Mockery mock with no expectations throws on any call, so this asserts the
 * absence of API traffic rather than the presence of output.
 */
function forbiddenWebhooks(): void
{
    $resource = Mockery::mock(WebhooksResource::class);
    $resource->shouldNotReceive('all', 'create', 'update', 'delete', 'ensure');

    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('webhooks')->andReturn($resource);
    app()->instance(KudosityClient::class, $client);
}

it('refuses to install from an environment that is not permitted to write', function () {
    // One account backs every environment, so a staging registration receives
    // production's delivery receipts and inbound replies in full.
    app()['env'] = 'staging';
    config()->set('app.url', 'https://staging.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')
        ->expectsOutputToContain('staging')
        ->expectsOutputToContain('one Kudosity account')
        ->assertExitCode(1);
});

it('names the config key so the refusal is actionable', function () {
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')
        ->expectsOutputToContain('kudosity.webhooks.sync.environments')
        ->assertExitCode(1);
});

it('refuses to delete from an environment that is not permitted to write', function () {
    // delete writes to the same shared account. An operator on a staging box
    // deleting "a stale webhook" would be deleting production's.
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:delete', ['id' => 'wh_1'])->assertExitCode(1);
});

it('does not let --force bypass the environment gate', function () {
    // --force skips the confirmation prompt. It is not, and must never become,
    // an environment override — the gate is a security control.
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:delete', ['id' => 'wh_1', '--force' => true])->assertExitCode(1);
});

it('permits writes from a listed environment', function () {
    app()['env'] = 'production';
    config()->set('app.url', 'https://app.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production']);

    fakeWebhooks()->shouldReceive('create')->once()->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')->assertExitCode(0);
});

it('permits writes from any of several listed environments', function () {
    app()['env'] = 'staging';
    config()->set('app.url', 'https://staging.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production', 'staging']);

    fakeWebhooks()->shouldReceive('create')->once()->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')->assertExitCode(0);
});

it('fails closed when the allowlist is empty', function () {
    // An empty list is a refusal, not a wildcard. Getting this backwards would
    // turn a misconfiguration into an exposure.
    app()['env'] = 'production';
    config()->set('kudosity.webhooks.sync.environments', []);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')->assertExitCode(1);
});

it('fails closed when the allowlist is absent entirely', function () {
    // A config published before this feature existed has no key at all. It must
    // refuse rather than default to permitting every environment.
    app()['env'] = 'production';
    config()->set('kudosity.webhooks', ['prefix' => 'webhooks/kudosity']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')->assertExitCode(1);
});

it('leaves the read-only list command ungated', function () {
    // Reading the account changes nothing, and an operator diagnosing a staging
    // problem needs to see what is registered.
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);

    fakeWebhooks()->shouldReceive('all')->once()->andReturn([]);

    $this->artisan('kudosity:webhook:list')->assertExitCode(0);
});
```

Note: `fakeWebhooks()` and `fakeHook()` are already defined in `tests/Unit/WebhookCommandsTest.php`. Pest loads all test files in one process, so they resolve. If a "cannot redeclare" error appears, move both helpers into `tests/Pest.php` and delete them from `WebhookCommandsTest.php`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/WebhookEnvironmentGateTest.php`

Expected: FAIL — the install command runs and reaches `create()`, so `forbiddenWebhooks()` throws or the exit code is 0 rather than 1.

- [ ] **Step 3: Create the trait**

Create `packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsEnvironment.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns;

/**
 * Refuse to write account-level webhook registrations from the wrong environment.
 *
 * Webhook registrations are **account-level**, and one Kudosity account backs
 * every environment here. A registration made from staging therefore receives
 * the whole account's events — every production delivery receipt and inbound
 * reply, with message bodies and phone numbers.
 *
 * No filter can prevent it. `filter.sender` would partition the traffic, but
 * every environment sends from the same sender; `messageRef` is an exact-match
 * list rather than a prefix; and `campaign_id` is not settable on a send. So
 * this gate is the only control, which is why it **fails closed and has no
 * override flag**. An absent or empty allowlist refuses.
 *
 * @phpstan-require-extends \Illuminate\Console\Command
 */
trait GuardsEnvironment
{
    /**
     * Whether this environment may write to the account, explaining any refusal.
     */
    protected function environmentPermitsWrites(): bool
    {
        /** @var array<int, string> $permitted */
        $permitted = (array) config('kudosity.webhooks.sync.environments', []);

        $current = (string) app()->environment();

        if (in_array($current, $permitted, true)) {
            return true;
        }

        $this->components->error("Refusing to write webhook registrations from the {$current} environment.");
        $this->line(
            "  Webhook registrations are account-level, and one Kudosity account backs every\n".
            "  environment — so a registration made here would receive production's delivery\n".
            "  receipts and inbound replies, message bodies and phone numbers included.\n".
            '  Permitted: <comment>'.($permitted === [] ? 'none' : implode(', ', $permitted))."</comment>\n".
            '  Set <comment>kudosity.webhooks.sync.environments</comment> if this environment '.
            'genuinely should own the account webhooks.'
        );

        return false;
    }
}
```

- [ ] **Step 4: Apply the gate to both writing commands**

In `WebhookInstallCommand`, add the import and `use GuardsEnvironment;` next to `use GuardsReceiverUrl;`, then make the gate the **first** statement of `handle()`, before the URL is resolved:

```php
        if (! $this->environmentPermitsWrites()) {
            return self::FAILURE;
        }
```

In `WebhookDeleteCommand`, add the same import and `use GuardsEnvironment;`, and insert the identical block as the first statement of `handle()`, before `$id` is read.

- [ ] **Step 5: Add the config block**

In `packages/kudosity-laravel/config/kudosity.php`, inside the `'webhooks' => [` array and immediately before the closing `],` of that array, add:

```php
        /*
        |----------------------------------------------------------------------
        | Registration Writes
        |----------------------------------------------------------------------
        |
        | Which environments may create, replace or delete account-level webhook
        | registrations, via kudosity:webhook:sync, :install and :delete.
        |
        | Webhook registrations belong to the ACCOUNT, not to an app. One
        | Kudosity account backs every environment, and every environment sends
        | from the same sender, so no filter can separate their traffic: a
        | registration made from staging receives production's delivery receipts
        | and inbound replies in full, message bodies and phone numbers
        | included.
        |
        | This list is therefore the only thing preventing that, and it FAILS
        | CLOSED — an empty or absent list refuses every environment, and there
        | is no command-line override. kudosity:webhook:list is read-only and
        | stays ungated.
        |
        */
        'sync' => [
            'environments' => ['production'],
        ],
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/pest tests/Unit/WebhookEnvironmentGateTest.php`

Expected: PASS, 9 tests.

- [ ] **Step 7: Run the whole root suite**

Run: `composer test`

Expected: PASS. `tests/Unit/WebhookCommandsTest.php` will likely fail first: its install tests do not set an environment, and Testbench defaults to `testing`, which the new gate refuses. Fix those tests by adding `config()->set('kudosity.webhooks.sync.environments', ['testing']);` to each install or delete test — **not** by widening the shipped default, which must stay `['production']`.

- [ ] **Step 8: Format, analyse, commit**

```bash
composer format
composer analyse
git add packages/kudosity-laravel/src/Console/Commands/Concerns/GuardsEnvironment.php packages/kudosity-laravel/src/Console/Commands/WebhookInstallCommand.php packages/kudosity-laravel/src/Console/Commands/WebhookDeleteCommand.php packages/kudosity-laravel/config/kudosity.php tests/Unit/WebhookEnvironmentGateTest.php tests/Unit/WebhookCommandsTest.php
git commit -m "feat(laravel): gate webhook writes behind a fail-closed allowlist

Registrations are account-level and one account backs every environment,
which all send from the same sender — so no filter can partition their
traffic and a staging registration receives production message bodies and
phone numbers.

This is the only control against that, so it fails closed on an empty or
absent list and has no override flag. --force on delete still skips only
the confirmation prompt. list stays ungated, being read-only."
```

---

### Task 8: `kudosity:webhook:sync`

**Files:**
- Create: `packages/kudosity-laravel/src/Console/Commands/WebhookSyncCommand.php`
- Modify: `packages/kudosity-laravel/src/KudosityServiceProvider.php:129-133`
- Modify: `tests/Unit/WebhookCommandsTest.php:286-292`
- Test: `tests/Unit/WebhookSyncCommandTest.php`

**Interfaces:**
- Consumes: `WebhooksResource::ensure()` (Task 2/5), `EnsureAction` (Task 2), `GuardsReceiverUrl::resolveReceiverUrl()` (Task 6), `GuardsEnvironment::environmentPermitsWrites()` (Task 7).
- Produces: the `kudosity:webhook:sync` console command.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/WebhookSyncCommandTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\EnsureResult;
use ExpertSystems\Kudosity\Enums\EnsureAction;

beforeEach(function () {
    // sync writes to the account, so every test here has to clear the gate.
    app()['env'] = 'production';
    config()->set('app.url', 'https://app.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production']);
});

it('reconciles through ensure() rather than creating unconditionally', function () {
    // install creates every time it runs; sync must not. This is the difference
    // between the two commands.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Created, fakeHook(['id' => 'wh_new'])),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('wh_new')
        ->assertExitCode(0);
});

it('registers a signed receiver URL, not a bare one', function () {
    // The receiver 403s an unsigned request, so a bare URL registers a webhook
    // that can never deliver — and fails silently, because Kudosity cannot tell
    // you your endpoint is rejecting it.
    $captured = null;

    fakeWebhooks()->shouldReceive('ensure')->once()->withArgs(function (...$args) use (&$captured) {
        $captured = $args[1];

        return true;
    })->andReturn(new EnsureResult(EnsureAction::Created, fakeHook()));

    $this->artisan('kudosity:webhook:sync')->assertExitCode(0);

    expect($captured)->toStartWith('https://app.example.com/webhooks/kudosity/events?')
        ->and($captured)->toContain('h=')
        ->and($captured)->toContain('s=');
});

it('succeeds and says nothing changed when the registration is already correct', function () {
    // Re-running on every deploy is the intended usage, so the no-op path must be
    // a success rather than a warning an operator learns to ignore.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Unchanged, fakeHook(['id' => 'wh_1'])),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('already correct')
        ->assertExitCode(0);
});

it('prints the resulting URL on a repair', function () {
    // An Updated result means something drifted, and the operator needs to see
    // what the registration now points at. Only the new URL is available —
    // EnsureResult carries no pre-update DTO — so this asserts exactly that and
    // does not claim to show what was replaced.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Updated, fakeHook(['url' => 'https://app.example.com/webhooks/kudosity/events?s=NEW'])),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('s=NEW')
        ->assertExitCode(0);
});

it('reports duplicates and says nothing was deleted', function () {
    // Silence here would let a duplicated account keep delivering two copies of
    // every event indefinitely.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Unchanged, fakeHook(['id' => 'wh_1']), [fakeHook(['id' => 'wh_dup'])]),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('wh_dup')
        ->expectsOutputToContain('kudosity:webhook:delete')
        ->assertExitCode(0);
});

it('refuses a plaintext APP_URL outside a local environment', function () {
    config()->set('app.url', 'http://app.example.com');
    fakeWebhooks()->shouldNotReceive('ensure');

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('must be HTTPS')
        ->assertExitCode(1);
});

it('refuses to run at all from an environment not permitted to write', function () {
    app()['env'] = 'staging';
    fakeWebhooks()->shouldNotReceive('ensure');

    $this->artisan('kudosity:webhook:sync')->assertExitCode(1);
});

it('rejects an unrecognised event type rather than registering a webhook that delivers nothing', function () {
    // The API silently ignores an event type it does not know, so the
    // registration would look correct and deliver nothing.
    fakeWebhooks()->shouldNotReceive('ensure');

    $this->artisan('kudosity:webhook:sync', ['--event' => ['SMS_STATUS', 'TELEPATHY']])
        ->expectsOutputToContain('TELEPATHY')
        ->assertExitCode(1);
});

it('reports a client failure without a stack trace', function () {
    fakeWebhooks()->shouldReceive('ensure')->once()
        ->andThrow(new ExpertSystems\Kudosity\Exceptions\ValidationException('url must be a valid URL'));

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('url must be a valid URL')
        ->assertExitCode(1);
});

it('is registered as a command', function () {
    expect(array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all()))
        ->toContain('kudosity:webhook:sync');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/WebhookSyncCommandTest.php`

Expected: FAIL — `The command "kudosity:webhook:sync" does not exist.`

- [ ] **Step 3: Write the command**

Create `packages/kudosity-laravel/src/Console/Commands/WebhookSyncCommand.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands;

use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\GuardsEnvironment;
use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\GuardsReceiverUrl;
use Illuminate\Console\Command;

/**
 * Converge the account on one webhook registration pointing at this app.
 *
 * The declarative counterpart to `install`, and the one to put in a deploy
 * script: running it twice registers one webhook, not two.
 *
 * It also repairs what a presence check cannot see. The receiver URL carries an
 * HMAC signature, so rotating `KUDOSITY_SIGNING_KEY` or `APP_KEY`, changing
 * `kudosity.webhooks.prefix`, or moving `APP_URL` leaves a registration that
 * still exists and still receives deliveries — every one of which the receiver
 * then rejects with a 403 that Kudosity has no way of reporting back to you.
 */
class WebhookSyncCommand extends Command
{
    use GuardsEnvironment;
    use GuardsReceiverUrl;

    protected $signature = 'kudosity:webhook:sync
        {--name= : A name for the registration, 2-100 characters}
        {--event=* : Event types to subscribe to; omit for all ten}
        {--rate-limit= : Max deliveries per second; omit for the system default}
        {--url= : Override the receiver URL entirely, signature included}';

    protected $description = 'Reconcile this app\'s Kudosity V2 webhook registration, creating or repairing as needed';

    public function handle(KudosityClient $client, CallbackUrlBuilder $urls): int
    {
        if (! $this->environmentPermitsWrites()) {
            return self::FAILURE;
        }

        $receiver = $this->resolveReceiverUrl($urls);

        if ($receiver === null) {
            return self::FAILURE;
        }

        $events = $this->resolveEvents();

        if ($events === null) {
            return self::FAILURE;
        }

        try {
            $result = $client->webhooks()->ensure(
                name: (string) ($this->option('name') ?: config('app.name').' Kudosity events'),
                url: $receiver['url'],
                eventTypes: $events,
                rateLimit: $this->option('rate-limit') !== null ? (int) $this->option('rate-limit') : null,
                allowInsecureUrl: $receiver['allowInsecure'],
            );
        } catch (KudosityException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $hook = $result->webhook;

        match ($result->action) {
            EnsureAction::Created => $this->components->info("Created webhook {$hook?->id}"),
            // The resulting URL, so an operator seeing "Updated" can at least
            // confirm what the registration now points at. The PREVIOUS url is
            // not available to print: EnsureResult carries only the post-write
            // DTO, so nothing here knows what it replaced. Printing the old one
            // would mean widening EnsureResult, which is not worth it — the
            // action plus the new URL is enough to tell a rotated signing key
            // (same path, different query) from a moved route (different path).
            EnsureAction::Updated => $this->components->info(
                "Repaired webhook {$hook?->id}\n  Now: ".($hook->url ?? '')
            ),
            EnsureAction::Unchanged => $this->components->info("Webhook {$hook?->id} is already correct"),
            // Unreachable from this command, which passes no fingerprint store.
            EnsureAction::Skipped => $this->components->info('Skipped: a stored fingerprint already matched'),
        };

        if ($result->duplicates !== []) {
            $ids = implode(', ', array_map(static fn (WebhookData $d): string => $d->id, $result->duplicates));

            $this->components->warn(
                "Other registrations point at the same receiver: {$ids}\n".
                '  Nothing was deleted — each will deliver its own copy of every event. '.
                'Remove the redundant ones with kudosity:webhook:delete once you have checked which is which.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Resolve --event values to enum cases, or null when one is unrecognised.
     *
     * Rejected rather than passed through: an event type the API does not know is
     * silently ignored, so the registration would look correct and deliver
     * nothing.
     *
     * @return array<int, WebhookEventType>|null
     */
    protected function resolveEvents(): ?array
    {
        $events = [];

        /** @var array<int, string> $requested */
        $requested = (array) $this->option('event');

        foreach ($requested as $name) {
            $type = WebhookEventType::fromApi($name);

            if ($type === WebhookEventType::Unknown) {
                $this->components->error("Unrecognised event type: {$name}");
                $this->line('  Valid types: '.implode(', ', array_map(
                    static fn (WebhookEventType $t): string => $t->value,
                    array_filter(
                        WebhookEventType::cases(),
                        static fn (WebhookEventType $t): bool => $t !== WebhookEventType::Unknown,
                    ),
                )));

                return null;
            }

            $events[] = $type;
        }

        return $events;
    }
}
```

`resolveEvents()` is duplicated from `WebhookInstallCommand` at this point. Leave it duplicated for now — Step 6 removes the duplication once both commands are green, so that a refactor failure is distinguishable from a new-feature failure.

- [ ] **Step 4: Register the command**

In `packages/kudosity-laravel/src/KudosityServiceProvider.php`, add the import and add `WebhookSyncCommand::class,` to the `$this->commands([...])` array after `WebhookInstallCommand::class,`.

In `tests/Unit/WebhookCommandsTest.php`, update the registration test — rename it and add the fourth command:

```php
it('registers all four commands', function () {
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('kudosity:webhook:list')
        ->and($commands)->toContain('kudosity:webhook:install')
        ->and($commands)->toContain('kudosity:webhook:sync')
        ->and($commands)->toContain('kudosity:webhook:delete');
});
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Unit/WebhookSyncCommandTest.php`

Expected: PASS, 10 tests.

- [ ] **Step 6: Remove the duplicated event resolution**

Move `resolveEvents()` out of both commands into a new trait
`packages/kudosity-laravel/src/Console/Commands/Concerns/ResolvesEventTypes.php`, in the same namespace as the other two concerns, with the same body and docblock, and marked `@phpstan-require-extends \Illuminate\Console\Command`. Delete the method from `WebhookInstallCommand` and `WebhookSyncCommand`, and add `use ResolvesEventTypes;` to both.

- [ ] **Step 7: Run both command test files**

Run: `vendor/bin/pest tests/Unit/WebhookSyncCommandTest.php tests/Unit/WebhookCommandsTest.php tests/Unit/WebhookEnvironmentGateTest.php`

Expected: PASS. Both commands' unrecognised-event tests must still pass; they now exercise the shared trait.

- [ ] **Step 8: Full suite, format, analyse, commit**

```bash
composer test
composer format
composer analyse
git add packages/kudosity-laravel/src/Console/Commands/WebhookSyncCommand.php packages/kudosity-laravel/src/Console/Commands/Concerns/ResolvesEventTypes.php packages/kudosity-laravel/src/Console/Commands/WebhookInstallCommand.php packages/kudosity-laravel/src/KudosityServiceProvider.php tests/Unit/WebhookSyncCommandTest.php tests/Unit/WebhookCommandsTest.php
git commit -m "feat(laravel): add kudosity:webhook:sync

The declarative counterpart to install, and the one that belongs in a
deploy script: running it twice registers one webhook rather than two, and
it repairs the drift a presence check cannot see.

Reports duplicates without deleting them, and prints the resulting URL on
a repair so an operator can tell a rotated signing key from a moved route."
```

---

### Task 9: The round-trip test, and the docs

The assertion the whole design exists for: the URL `sync` registers must be one the receiver accepts. Everything else can be right and this can still be wrong, and when it is, every delivery 403s in silence.

**Files:**
- Test: `tests/Unit/WebhookSyncCommandTest.php` (append)
- Modify: `packages/kudosity-client/README.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: `kudosity:webhook:sync` (Task 8), `WebhookController::events()` (existing, `packages/kudosity-laravel/src/Http/Controllers/WebhookController.php:72`).
- Produces: no new code.

- [ ] **Step 1: Write the round-trip test**

Append to `tests/Unit/WebhookSyncCommandTest.php`:

```php
it('registers a URL the receiver actually accepts', function () {
    // THE test. Every other assertion can pass while this fails, and when it
    // fails the symptom is that nothing arrives — the receiver 403s each
    // delivery, and Kudosity has no channel to tell you it is being refused.
    //
    // So: capture what sync registered, then POST a real payload to exactly that
    // URL through the app's own routes.
    $captured = null;

    fakeWebhooks()->shouldReceive('ensure')->once()->withArgs(function (...$args) use (&$captured) {
        $captured = $args[1];

        return true;
    })->andReturn(new EnsureResult(EnsureAction::Created, fakeHook()));

    $this->artisan('kudosity:webhook:sync')->assertExitCode(0);

    expect($captured)->not->toBeNull();

    // Everything from the path onwards, so the request goes through this app.
    $path = (string) parse_url((string) $captured, PHP_URL_PATH);
    $query = (string) parse_url((string) $captured, PHP_URL_QUERY);

    $this->postJson($path.'?'.$query, [
        'event_type' => 'SMS_STATUS',
        'status' => [
            'message_ref' => 'ref-1',
            'status' => 'DELIVERED',
        ],
    ])->assertOk();
});

it('would be rejected by the receiver if the signature were stripped', function () {
    // The complement, and the reason the round trip above is meaningful rather
    // than tautological: the receiver really does refuse an unsigned URL, so the
    // test above is asserting the signature works and not that the route is open.
    $captured = null;

    fakeWebhooks()->shouldReceive('ensure')->once()->withArgs(function (...$args) use (&$captured) {
        $captured = $args[1];

        return true;
    })->andReturn(new EnsureResult(EnsureAction::Created, fakeHook()));

    $this->artisan('kudosity:webhook:sync')->assertExitCode(0);

    $path = (string) parse_url((string) $captured, PHP_URL_PATH);

    $this->postJson($path, ['event_type' => 'SMS_STATUS'])->assertStatus(403);
});
```

- [ ] **Step 2: Run them**

Run: `vendor/bin/pest tests/Unit/WebhookSyncCommandTest.php`

Expected: PASS, 12 tests.

If the round trip returns 403, compare the signing key the command used against the one `CallbackUrlParser` was constructed with in `KudosityServiceProvider`. Both read `kudosity.webhooks.signing_key`, falling back to `app.key`. **Do not "fix" this by relaxing `WebhookController::events()`** — a 403 here is the real bug this test exists to catch.

If it returns 404, the webhook routes are not loaded in the test environment; check `kudosity.webhooks.enabled` and `kudosity.webhooks.events.enabled` are both true in `tests/TestCase.php`.

- [ ] **Step 3: Document the raw-PHP path in the client README**

In `packages/kudosity-client/README.md`, add a section after the existing V2 channels material:

```markdown
### Keeping a webhook registered

V2 has no per-send callback URL: delivery receipts and inbound replies arrive
only if an account-level registration points at your receiver. `ensure()`
converges the account on one registration and is safe to run on every deploy.

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

echo $result->action->value.' '.$result->webhook?->id.PHP_EOL;
```

`$result->action` is `created`, `updated`, `unchanged` or `skipped`. Re-running
is free — one `GET` when nothing has moved.

**Always pass a handler marker to `build()`.** With neither `h` nor `c` in the
query, `CallbackUrlParser::parse()` skips signature verification entirely, and
your receiver is then open to anyone who guesses the path — the default is
documented.

**`ensure()` repairs, it does not just create.** Rotating your signing key,
changing your route prefix or moving hosts leaves a registration that still
exists and still receives deliveries, every one of which your receiver rejects.
Nothing reports that back to you, which is why a presence check is not enough.

To skip the `GET` on hot paths, pass a fingerprint store:

```php
use ExpertSystems\Kudosity\Webhooks\FileFingerprintStore;

$result = $client->webhooks()->ensure(
    name: 'My app events',
    url: $signedUrl,
    store: new FileFingerprintStore(__DIR__.'/storage/kudosity-webhooks.json'),
);
```

The store is only an optimisation — a missing or stale entry costs one `GET`,
never a wrong registration. `action` is then `skipped` and `webhook` is `null`,
because nothing was read. Implement `WebhookFingerprintStore` yourself to back
it with a PSR-16 cache or anything else.

**No CLI at all?** Put the same call behind a signed query parameter on the
receiver you already deploy, and hit it once in a browser.

**Tolerate a `message_ref` you do not recognise.** If more than one deployment
shares one Kudosity account and sender, your receiver will be delivered events
for messages another one sent. Look the reference up, and treat a miss as
ordinary rather than an error worth alerting on.
```

- [ ] **Step 4: Document `sync` in the root README**

In `README.md`, in the webhook material, add:

```markdown
#### Keeping the registration correct

```bash
php artisan kudosity:webhook:sync
```

Put this in your deploy script alongside `migrate`. It is idempotent — running
it twice registers one webhook, not two — and it repairs drift that a presence
check cannot see: rotating `KUDOSITY_SIGNING_KEY` or `APP_KEY`, changing
`kudosity.webhooks.prefix`, or moving `APP_URL` all leave a registration that
still receives deliveries the receiver then rejects with a 403 nothing reports
back to you.

`kudosity:webhook:install` remains the imperative one-shot, for registering an
additional, differently-filtered webhook.

**Only permitted environments may run it.** Registrations are account-level, so
one made from staging receives production's delivery receipts and inbound
replies — message bodies and phone numbers included. `kudosity.webhooks.sync.environments`
controls this, defaults to `['production']`, **fails closed** on an empty or
absent list, and has no command-line override. `kudosity:webhook:list` is
read-only and ungated.

If several of your deployments share one Kudosity account and sender, each
receiver will be delivered events for messages the others sent. Write listeners
that treat an unrecognised `message_ref` as ordinary rather than an error.
```

- [ ] **Step 5: Full verification**

```bash
composer test
cd packages/kudosity-client && vendor/bin/phpunit && cd ../..
composer format
composer analyse
```

Expected: all green. Then run the client suite on the declared floor, which no local toolchain provides:

```bash
cd packages/kudosity-client && docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit
```

Expected: PASS. If Docker is unavailable, say so in the final report rather than reporting the floor as verified.

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/WebhookSyncCommandTest.php packages/kudosity-client/README.md README.md
git commit -m "test(laravel): assert sync registers a URL the receiver accepts

The round trip is the assertion this whole design exists for: everything
else can be correct while this fails, and the symptom is silence — the
receiver 403s each delivery and Kudosity cannot report the refusal.

Paired with its complement, so the test proves the signature works rather
than that the route is open.

Documents the raw-PHP recipe, the fingerprint store, the mandatory handler
marker, and the shared-account consequence: a receiver must tolerate a
message_ref it did not issue."
```

---

## Self-Review

**Spec coverage.** Every spec section maps to a task: `ensure()` and its comparison rules → Tasks 2–4; identity normalisation → Task 1; `WebhookFingerprintStore` and `FileFingerprintStore` → Task 5; `GuardsReceiverUrl` extraction → Task 6; the fail-closed environment gate and config block → Task 7; `kudosity:webhook:sync` and provider registration → Task 8; the round trip and both READMEs → Task 9. All six spec success criteria are asserted by a named test, plus the seventh (no HTTP from a gated environment) via `forbiddenWebhooks()`. Out-of-scope items — a scheduled reconcile, deletion of stale registrations, lazy ensure on the send path — appear in no task.

**One correction to the spec.** The spec declares `EnsureResult::$webhook` non-nullable, which is impossible: the fingerprint short-circuit returns without reading the account, so there is no DTO. Resolved by adding `EnsureAction::Skipped` and typing `?WebhookData $webhook`, with the invariant documented on both types — `Skipped` is the only action carrying a null webhook. The spec's `EnsureResult` sketch and its `Skipped`-free action list need updating to match.

**Two deliberate additions beyond the spec's file table.** `WebhookIdentity` is its own class rather than a private method, so normalisation is directly testable; `ResolvesEventTypes` is extracted in Task 8 Step 6 because `sync` would otherwise duplicate 30 lines of `WebhookInstallCommand`.

**Type consistency.** `WebhookIdentity::of()`, `EnsureAction::{Created,Updated,Unchanged,Skipped}`, `EnsureResult::{action,webhook,duplicates}`, `WebhookFingerprintStore::{get,put}`, `FileFingerprintStore::__construct(string)`, `GuardsReceiverUrl::resolveReceiverUrl(): ?array{url,allowInsecure}`, `GuardsEnvironment::environmentPermitsWrites(): bool`, and `ResolvesEventTypes::resolveEvents(): ?array` are each defined once and used with those exact names and shapes throughout. `ensure()`'s parameter list is stated in Task 2 and extended once, in Task 5, by appending `?WebhookFingerprintStore $store = null`.
