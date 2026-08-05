# Kudosity Migration Phase 2: V2 Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the transport, error, envelope and pagination machinery the V2 API needs, and reshape the V1 resource surface so V2 can take the `sms()` name in Phase 3 — with no V2 endpoint classes yet.

**Architecture:** A second Saloon connector (`api.transmitmessage.com`, `x-api-key`, JSON body) alongside the existing V1 one, with the retry policy they share extracted into a trait. Three seams absorb V2's documented inconsistencies: `UnwrapsData` for its two envelope shapes, `KudosityException::fromV2Response()` for RFC 9457 Problem Details, and two new paginators for its two pagination schemes. Then the V1 `SmsResource` splits into `bulk()`, `reporting()` and `numbers()`, freeing the `sms()` name.

**Tech Stack:** PHP 8.2+, Saloon v4 (`saloonphp/saloon`, `saloonphp/pagination-plugin`), Pest 4, PHPStan level 6 via Larastan, Laravel Pint.

## Global Constraints

- Runtime floor stays `"php": "^8.2"` in both package `composer.json` files. Laravel support stays `illuminate/* ^11.0||^12.0`.
- `composer analyse` (PHPStan **level 6**) must report `[OK] No errors` at the end of every task.
- `vendor/bin/pint --test` must pass at the end of every task. Run `composer format` to fix.
- Baseline entering this phase: **256 tests, 473 assertions passing**. The count only goes up.
- **The V1 hostname is `https://api.transmitsms.com` and the V2 hostname is `https://api.transmitmessage.com`.** Neither is a Kudosity-branded domain and neither may be "corrected". Phase 1 broke this once: a sweep using `\btransmitsms\b` matched *inside* `api.transmitsms.com`, because the dots are word boundaries. Never write a pattern like that, and if you ever sweep for branding, grep for the *corrupted* form too — the original string is gone by the time you look for it.
- **Every consumer-visible rename or move in this phase must land in `rename-map.json` and `UPGRADING.md` in the same commit as the code.** Phase 1's final review caught exactly this gap: Task 2 removed public symbols, Task 4 wrote the map, and nothing connected them, so the codemod silently emitted code that fatals. Moving `sms()->sendToList()` to `bulk()->sendToList()` is precisely that class of change.
- Never modify anything under `.agents/skills/` — vendored upstream Kudosity references. Never rewrite `CHANGELOG.md`'s historical entries; append under `## Unreleased`.
- If any task needs a repo-wide `sed`, exclude `.agents/*`, `docs/*`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod` and `tests/Unit/CodemodTest.php`. The last four legitimately contain old-brand strings.
- V2 authentication is the API **key only**. The V2 connector must never see, store, or send the API secret.
- `.github/workflows/split.yml` keeps `actions/checkout@v4` and its comment. Release tags must be `v`-prefixed.
- `/docs` is gitignored; committing anything under it needs `git add -f`.
- Work on a new branch `feat/kudosity-v2-core` cut from `main` (currently `5b0e397`). `main` is local-only and unpushed — do not push it.

## Reference

- Design spec: `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`. Sections 2 and 3 are authoritative for this phase.
- Phase 1 plan, for the conventions this phase inherits: `docs/superpowers/plans/2026-08-04-kudosity-phase-1-rebrand.md`.
- Vendored API references: `.agents/skills/kudosity-sms/SKILL.md` (envelope and `sms_count` string), `.agents/skills/kudosity-rcs/SKILL.md` (RFC 9457 error shape with `issues[]`), `.agents/skills/kudosity-webhooks/SKILL.md` (the plain `{"error": "..."}` shape). **Read the relevant skill before writing anything that touches a wire contract.**

This is **Phase 2 of 6**. It adds no endpoint classes: `POST /v2/sms`, MMS, WhatsApp, RCS, webhooks and senders are Phase 3 and 4. Phase 2's paginators are therefore exercised by test-only stub requests, which is deliberate — the machinery is proven before there is anything real to hang on it.

### V2 facts this phase encodes

| Concern | V2 behaviour |
|---|---|
| Base URL | `https://api.transmitmessage.com` |
| Auth | header `x-api-key: {key}`; the secret is never used |
| Body | `application/json` |
| Paths | under `/v2/`, no `.json` suffix |
| Envelope | SMS and MMS return the object **flat**; WhatsApp, RCS, RCS capabilities and sender registrations wrap it in `data`, alongside `request` and `meta` |
| Errors | RFC 9457 Problem Details under `error`, with an `issues[]` array naming every failed field; webhook endpoints instead return `{"error": "plain string"}` |
| Paged lists | `GET /v2/sms`: query `page` and `limit` (default 100), items under `smses`, plus `total_records` and `total_segments` **as strings** |
| Cursor lists | `GET /v2/whatsapp/messages` and `GET /v2/rcs/messages`: query `limit` (1–100, default 25), `cursor`, `direction` (`next`\|`prev`); items under `data.messages`; `meta.pagination.{has_next,next_cursor,has_prev,prev_cursor}` |
| Created | `POST /v2/webhook` returns **201** |

---

## File Structure

### New files

| Path | Responsibility |
|---|---|
| `packages/kudosity-client/src/Concerns/HasRetryPolicy.php` | Retry configuration and the retry decision, shared by both connectors |
| `packages/kudosity-client/src/Concerns/UnwrapsData.php` | Resolve V2's two envelope shapes to one payload array |
| `packages/kudosity-client/src/KudosityV2Connector.php` | V2 transport: base URL, `x-api-key`, JSON, failure detection, paginator dispatch |
| `packages/kudosity-client/src/Requests/KudosityV2Request.php` | Abstract base for V2 requests: JSON body, `/v2/` paths |
| `packages/kudosity-client/src/Exceptions/NotFoundException.php` | V2 404 |
| `packages/kudosity-client/src/Exceptions/ServerException.php` | V2 5xx |
| `packages/kudosity-client/src/Exceptions/ProblemIssue.php` | One entry of an RFC 9457 `issues[]` array |
| `packages/kudosity-client/src/Contracts/PaginatesV2Pages.php` | Marks a V2 request as page-paginated |
| `packages/kudosity-client/src/Contracts/PaginatesV2Cursor.php` | Marks a V2 request as cursor-paginated |
| `packages/kudosity-client/src/Pagination/V2PagedPaginator.php` | `page`/`limit` pagination with `total_records` termination |
| `packages/kudosity-client/src/Pagination/V2CursorPaginator.php` | `limit`/`cursor`/`direction` pagination via `meta.pagination` |
| `packages/kudosity-client/src/Resources/V2Resource.php` | Base for V2 resources: holds the V2 connector, sends and DTOs |
| `packages/kudosity-client/src/Resources/BulkSmsResource.php` | V1 multi-recipient, list and scheduled sends, and cancel |
| `packages/kudosity-client/src/Concerns/FormatsPhoneNumbers.php` | Offline phone-number helpers, usable from any send resource |
| `tests/Unit/V2ConnectorTest.php` | V2 transport: URL, auth header, secret absence, body, envelope |
| `tests/Unit/V2ErrorTest.php` | All three V2 error shapes and every status mapping |
| `tests/Unit/V2PaginationTest.php` | Both V2 paginators, including termination and dispatch |
| `tests/Unit/BulkSmsResourceTest.php` | The V1 send surface after the split |

### Modified files

| Path | Change |
|---|---|
| `packages/kudosity-client/src/KudosityV1Connector.php` | Use `HasRetryPolicy`; throw a clear error when the secret is missing |
| `packages/kudosity-client/src/Exceptions/KudosityException.php` | Add `fromV2Response()`, `getIssues()`, and the V2 status map |
| `packages/kudosity-client/src/KudosityClient.php` | Hold both connectors; add `v1()`, `v2()`, `bulk()`; drop `sms()`; make the secret optional |
| `packages/kudosity-client/src/Resources/Resource.php` | Rename to make its V1-ness explicit in docs; unchanged behaviour |
| `packages/kudosity-client/src/Resources/ReportingResource.php` | Gain the reply/response readers moved off `SmsResource` |
| `packages/kudosity-client/src/Resources/NumbersResource.php` | Gain the API-backed `formatNumber()` |
| `packages/kudosity-client/src/Resources/SmsResource.php` | Deleted; its members redistributed |
| `packages/kudosity-laravel/src/Notifications/KudosityChannel.php` | Send via `bulk()` instead of `sms()` |
| `packages/kudosity-laravel/src/Facades/Kudosity.php` | `@method` annotations for the new accessors |
| `rename-map.json` | New `methods` / `removed` entries for every moved accessor |
| `UPGRADING.md` | A "Resource surface changes" section and the new env/auth note |
| `CHANGELOG.md` | Append to `## Unreleased` |
| `tests/Unit/PaginationTest.php`, `tests/Unit/KudosityChannelTest.php`, `tests/ArchTest.php` | Follow the renames |

---

## Task 1: Extract the retry policy into a shared trait

Both connectors need identical retry behaviour. Extracting first means Task 2 consumes a proven trait rather than copying code — and the existing `RetryConfigurationTest` proves the extraction changed nothing.

**Files:**
- Create: `packages/kudosity-client/src/Concerns/HasRetryPolicy.php`
- Modify: `packages/kudosity-client/src/KudosityV1Connector.php`
- Test: `tests/Unit/RetryConfigurationTest.php` (existing, must keep passing unchanged)

**Interfaces:**
- Consumes: nothing.
- Produces: trait `ExpertSystems\Kudosity\Concerns\HasRetryPolicy` with `withRetry(int $tries = 3, int $intervalMs = 1000, bool $useExponentialBackoff = true, bool $throwOnMaxTries = true): static`, `withoutRetry(): static`, and `handleRetry(FatalRequestException|RequestException $exception, Request $request): bool`. Task 2's `KudosityV2Connector` uses it.

- [ ] **Step 1: Record the baseline**

```bash
git checkout -b feat/kudosity-v2-core
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
```

Expected: `Tests: 256 passed (473 assertions)` and `[OK] No errors`. If either differs, stop and report.

- [ ] **Step 2: Create the trait**

Create `packages/kudosity-client/src/Concerns/HasRetryPolicy.php`. Move the three methods out of `KudosityV1Connector` verbatim, changing only the return types from `self` to `static` so both connectors get accurate fluent types:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;

/**
 * Shared retry behaviour for the V1 and V2 connectors.
 *
 * Both APIs fail the same transient ways — 429 rate limits, 5xx, dropped
 * connections — so the policy lives here rather than in either connector.
 *
 * @see https://docs.saloon.dev/digging-deeper/retrying-requests
 */
trait HasRetryPolicy
{
    /**
     * Configure automatic retry behavior for transient failures.
     *
     * @param  int  $tries  Maximum attempts, including the initial request
     * @param  int  $intervalMs  Initial interval between retries, in milliseconds
     * @param  bool  $useExponentialBackoff  Double the interval after each retry
     * @param  bool  $throwOnMaxTries  Throw once all retries are exhausted
     */
    public function withRetry(
        int $tries = 3,
        int $intervalMs = 1000,
        bool $useExponentialBackoff = true,
        bool $throwOnMaxTries = true
    ): static {
        $this->tries = $tries;
        $this->retryInterval = $intervalMs;
        $this->useExponentialBackoff = $useExponentialBackoff;
        $this->throwOnMaxTries = $throwOnMaxTries;

        return $this;
    }

    /**
     * Disable automatic retries.
     */
    public function withoutRetry(): static
    {
        $this->tries = null;
        $this->retryInterval = null;
        $this->useExponentialBackoff = null;
        $this->throwOnMaxTries = null;

        return $this;
    }

    /**
     * Decide whether a failed request should be retried.
     *
     * Retries connection failures, 429s and 5xx. Never retries other 4xx —
     * a validation error will fail identically however many times it is sent.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        $status = $exception->getResponse()->status();

        if ($status === 429) {
            return true;
        }

        return $status >= 500 && $status < 600;
    }
}
```

- [ ] **Step 3: Use the trait in the V1 connector**

In `packages/kudosity-client/src/KudosityV1Connector.php`, add the import and the `use` statement inside the class body:

```php
use ExpertSystems\Kudosity\Concerns\HasRetryPolicy;
```

```php
class KudosityV1Connector extends Connector implements HasPagination
{
    use AcceptsJson;
    use HasRetryPolicy;
```

Then delete `withRetry()`, `withoutRetry()` and `handleRetry()` from the connector, along with the `Retry Configuration` banner comment that introduced them. Remove the now-unused `FatalRequestException` and `RequestException` imports if nothing else in the file references them — check before deleting, `getRequestException()` may not need them.

- [ ] **Step 4: Verify the extraction changed nothing**

```bash
vendor/bin/pest tests/Unit/RetryConfigurationTest.php
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: `RetryConfigurationTest` passes untouched, full suite still 256 passed, `[OK] No errors`, Pint clean. The point of this task is that the test file did not need editing.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: extract the shared retry policy into HasRetryPolicy"
```

---

## Task 2: V2 connector, request base and envelope unwrapping

**Files:**
- Create: `packages/kudosity-client/src/KudosityV2Connector.php`
- Create: `packages/kudosity-client/src/Requests/KudosityV2Request.php`
- Create: `packages/kudosity-client/src/Concerns/UnwrapsData.php`
- Test: `tests/Unit/V2ConnectorTest.php`

**Interfaces:**
- Consumes: `HasRetryPolicy` from Task 1.
- Produces:
  - `ExpertSystems\Kudosity\KudosityV2Connector` with `public const BASE_URL = 'https://api.transmitmessage.com';`, `__construct(string $apiKey, string $baseUrl = self::BASE_URL, int $timeout = 30)`, `getApiKey(): string`, `getBaseUrl(): string`, `setBaseUrl(string $baseUrl): self`, `getTimeout(): int`, `setTimeout(int $timeout): self`. Implements Saloon's `HasPagination`. **No secret parameter of any kind.**
  - `ExpertSystems\Kudosity\Requests\KudosityV2Request` — abstract, `extends Request implements HasBody`, `use HasJsonBody`, default method `Method::POST`.
  - `ExpertSystems\Kudosity\Concerns\UnwrapsData` with `protected static function payload(Response $response): array` and `protected static function payloadFrom(array $json): array`.
- Task 3 adds this connector's failure detection; Task 4 adds its `paginate()`; Task 6 wires it into the client.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/V2ConnectorTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Concerns\UnwrapsData;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;

/** A stand-in for the real V2 requests that arrive in Phase 3. */
class StubV2SendRequest extends KudosityV2Request
{
    public function __construct(protected string $message) {}

    public function resolveEndpoint(): string
    {
        return '/v2/sms';
    }

    protected function defaultBody(): array
    {
        return ['message' => $this->message];
    }
}

class StubV2GetRequest extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/sms/abc-123';
    }
}

/** Exposes the trait's protected helpers for testing. */
class DataUnwrapper
{
    use UnwrapsData;

    public static function of(Response $response): array
    {
        return static::payload($response);
    }

    public static function ofArray(array $json): array
    {
        return static::payloadFrom($json);
    }
}

it('resolves the V2 base URL', function () {
    expect(KudosityV2Connector::BASE_URL)->toBe('https://api.transmitmessage.com')
        ->and((new KudosityV2Connector('key'))->resolveBaseUrl())
        ->toBe('https://api.transmitmessage.com');
});

it('authenticates with the x-api-key header and never sends a secret', function () {
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'x'], 200)]);

    $connector = new KudosityV2Connector('my-key');
    $connector->withMockClient($mock);
    $connector->send(new StubV2SendRequest('hi'));

    $headers = $mock->getLastPendingRequest()->headers()->all();

    expect($headers)->toHaveKey('x-api-key')
        ->and($headers['x-api-key'])->toBe('my-key')
        ->and($headers)->not->toHaveKey('Authorization');
});

it('sends a JSON body, not a form body', function () {
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'x'], 200)]);

    $connector = new KudosityV2Connector('my-key');
    $connector->withMockClient($mock);
    $connector->send(new StubV2SendRequest('hello world'));

    $pending = $mock->getLastPendingRequest();

    expect($pending->headers()->get('Content-Type'))->toBe('application/json')
        ->and((string) $pending->body())->toBe('{"message":"hello world"}');
});

it('does not append .json to V2 endpoints', function () {
    $mock = new MockClient([StubV2GetRequest::class => MockResponse::make(['id' => 'x'], 200)]);

    $connector = new KudosityV2Connector('my-key');
    $connector->withMockClient($mock);
    $connector->send(new StubV2GetRequest);

    expect((string) $mock->getLastPendingRequest()->getUri())
        ->toBe('https://api.transmitmessage.com/v2/sms/abc-123');
});

it('accepts a custom base URL and timeout', function () {
    $connector = new KudosityV2Connector('key', 'https://example.test', 60);

    expect($connector->resolveBaseUrl())->toBe('https://example.test')
        ->and($connector->getTimeout())->toBe(60);

    $connector->setBaseUrl('https://other.test')->setTimeout(5);

    expect($connector->getBaseUrl())->toBe('https://other.test')
        ->and($connector->getTimeout())->toBe(5);
});

it('unwraps a data-wrapped envelope', function () {
    $json = ['data' => ['id' => 'abc', 'sender' => 'DemoSender'], 'request' => [], 'meta' => []];

    expect(DataUnwrapper::ofArray($json))->toBe(['id' => 'abc', 'sender' => 'DemoSender']);
});

it('passes a flat envelope through untouched', function () {
    $json = ['id' => 'abc', 'recipient' => '61400000000', 'sms_count' => '1'];

    expect(DataUnwrapper::ofArray($json))->toBe($json);
});

it('treats a null or non-array data key as flat', function () {
    expect(DataUnwrapper::ofArray(['data' => null, 'id' => 'abc']))->toBe(['data' => null, 'id' => 'abc'])
        ->and(DataUnwrapper::ofArray(['data' => 'scalar']))->toBe(['data' => 'scalar']);
});

it('unwraps straight from a response', function () {
    $mock = new MockClient([
        StubV2GetRequest::class => MockResponse::make(['data' => ['id' => 'abc']], 200),
    ]);

    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    expect(DataUnwrapper::of($connector->send(new StubV2GetRequest)))->toBe(['id' => 'abc']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/V2ConnectorTest.php`
Expected: FAIL — `Class "ExpertSystems\Kudosity\KudosityV2Connector" not found`, and likewise for the request base and the trait.

- [ ] **Step 3: Write the request base**

Create `packages/kudosity-client/src/Requests/KudosityV2Request.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Abstract base for Kudosity V2 API requests.
 *
 * V2 differs from V1 on every axis of the transport: a JSON body rather than
 * form-encoded, paths under `/v2/` with no `.json` suffix, and a key-only
 * header credential. Endpoints are written out in full by each request —
 * there is no suffix helper to forget to call.
 */
abstract class KudosityV2Request extends Request implements HasBody
{
    use HasJsonBody;

    /**
     * Most V2 endpoints that carry a body are POSTs; readers override this.
     */
    protected Method $method = Method::POST;

    abstract public function resolveEndpoint(): string;
}
```

- [ ] **Step 4: Write the envelope trait**

Create `packages/kudosity-client/src/Concerns/UnwrapsData.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use Saloon\Http\Response;

/**
 * Resolves the V2 API's two envelope shapes to a single payload array.
 *
 * SMS and MMS return their object flat. WhatsApp, RCS, RCS capabilities and
 * sender registrations wrap it: `{"data": {...}, "request": {}, "meta": {}}`.
 * Code written against one shape and reused for the other reads null, which
 * is the most common way to misread this API — so every DTO factory resolves
 * the payload through here rather than reaching into `json()` directly.
 *
 * Per-endpoint shapes are tabulated in the client package README.
 */
trait UnwrapsData
{
    /**
     * Resolve the payload of a response, whichever envelope it used.
     *
     * @return array<string, mixed>
     */
    protected static function payload(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? static::payloadFrom($json) : [];
    }

    /**
     * Resolve the payload of an already-decoded body.
     *
     * A `data` key holding anything other than an array is left alone: that is
     * a flat payload whose own field happens to be called `data`, not an
     * envelope.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    protected static function payloadFrom(array $json): array
    {
        return isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
    }
}
```

- [ ] **Step 5: Write the connector**

Create `packages/kudosity-client/src/KudosityV2Connector.php`. Leave `hasRequestFailed()`, `getRequestException()` and `paginate()` out for now — Tasks 3 and 4 add them, and `HasPagination` is not yet declared:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity;

use ExpertSystems\Kudosity\Concerns\HasRetryPolicy;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Connector for the Kudosity V2 API (`api.transmitmessage.com`).
 *
 * V2 covers single-recipient SMS, MMS, WhatsApp, RCS, webhooks and sender
 * registrations. It authenticates with the API **key only** — the API secret
 * belongs to V1 and is deliberately absent from this class, so there is no
 * path by which it could be sent to the wrong host.
 *
 * @see https://developers.kudosity.com/reference/authentication
 */
class KudosityV2Connector extends Connector
{
    use AcceptsJson;
    use HasRetryPolicy;

    public const BASE_URL = 'https://api.transmitmessage.com';

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = self::BASE_URL,
        protected int $timeout = 30,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
        ];
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/V2ConnectorTest.php`
Expected: PASS, 9 tests.

If the `Content-Type` assertion fails, check whether `HasJsonBody` already sets it and the connector's `defaultHeaders()` is being overridden or duplicated — assert on the value that actually reaches the wire, and keep whichever single source of truth is correct rather than setting it twice.

- [ ] **Step 7: Verify the whole suite**

```bash
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: 265 passed (256 + 9), `[OK] No errors`, Pint clean.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add the V2 connector, request base and envelope unwrapping"
```

---

## Task 3: The V2 error path

**Files:**
- Create: `packages/kudosity-client/src/Exceptions/NotFoundException.php`
- Create: `packages/kudosity-client/src/Exceptions/ServerException.php`
- Create: `packages/kudosity-client/src/Exceptions/ProblemIssue.php`
- Modify: `packages/kudosity-client/src/Exceptions/KudosityException.php`
- Modify: `packages/kudosity-client/src/KudosityV2Connector.php`
- Test: `tests/Unit/V2ErrorTest.php`

**Interfaces:**
- Consumes: `KudosityV2Connector` from Task 2.
- Produces:
  - `ExpertSystems\Kudosity\Exceptions\NotFoundException extends KudosityException`
  - `ExpertSystems\Kudosity\Exceptions\ServerException extends KudosityException`
  - `final readonly ExpertSystems\Kudosity\Exceptions\ProblemIssue` with public `string $name` and `string $message`, and `public static function fromArray(array $issue): self`
  - `KudosityException::fromV2Response(Response $response): self`
  - `KudosityException::getIssues(): array<int, ProblemIssue>` — empty array for V1 exceptions
  - `KudosityException::getProblemType(): ?string` — the RFC 9457 `type` URI
  - `KudosityV2Connector::hasRequestFailed(Response $response): ?bool` and `getRequestException(Response $response, ?Throwable $senderException): ?Throwable`
- Task 5's resources surface these; Phase 3's endpoint classes rely on them.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/V2ErrorTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\AccessDeniedException;
use ExpertSystems\Kudosity\Exceptions\AuthenticationException;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\ServerException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The RFC 9457 shape the V2 messaging endpoints return, as documented in
 * .agents/skills/kudosity-rcs/SKILL.md.
 */
function problemBody(int $status, array $issues = []): array
{
    return ['error' => array_filter([
        'type' => 'https://developers.kudosity.com/reference/errors#input-validation',
        'title' => 'Invalid Request',
        'detail' => 'Request validation failed',
        'status' => $status,
        'issues' => $issues,
    ])];
}

function v2Exception(int $status, array $body): KudosityException
{
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make($body, $status)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $response = $connector->send(new StubV2SendRequest('hi'));

    return KudosityException::fromV2Response($response);
}

it('maps every documented status to its exception class', function (int $status, string $class) {
    expect(v2Exception($status, problemBody($status)))->toBeInstanceOf($class);
})->with([
    'validation (registry says 422)' => [422, ValidationException::class],
    'validation (endpoint docs say 400)' => [400, ValidationException::class],
    'unauthorized' => [401, AuthenticationException::class],
    'forbidden' => [403, AccessDeniedException::class],
    'not found' => [404, NotFoundException::class],
    'rate limited' => [429, RateLimitException::class],
    'server error' => [500, ServerException::class],
    'bad gateway' => [502, ServerException::class],
]);

it('extracts every failed field from issues[] at once', function () {
    $e = v2Exception(400, problemBody(400, [
        ['name' => 'sender', 'message' => 'sender is required'],
        ['name' => 'recipient', 'message' => 'recipient must be E.164'],
    ]));

    $issues = $e->getIssues();

    expect($issues)->toHaveCount(2)
        ->and($issues[0]->name)->toBe('sender')
        ->and($issues[0]->message)->toBe('sender is required')
        ->and($issues[1]->name)->toBe('recipient')
        ->and($e->getMessage())->toContain('sender is required')
        ->and($e->getMessage())->toContain('recipient must be E.164');
});

it('exposes the problem type URI and the HTTP status as the code', function () {
    $e = v2Exception(422, problemBody(422));

    expect($e->getProblemType())->toBe('https://developers.kudosity.com/reference/errors#input-validation')
        ->and($e->getCode())->toBe(422);
});

it('falls back to detail then title when there are no issues', function () {
    expect(v2Exception(500, ['error' => ['detail' => 'boom', 'status' => 500]])->getMessage())->toBe('boom')
        ->and(v2Exception(500, ['error' => ['title' => 'Server Error', 'status' => 500]])->getMessage())
        ->toBe('Server Error');
});

it('handles the plain-string error shape the webhook endpoints use', function () {
    $e = v2Exception(404, ['error' => 'SMS not found']);

    expect($e)->toBeInstanceOf(NotFoundException::class)
        ->and($e->getMessage())->toBe('SMS not found')
        ->and($e->getIssues())->toBe([]);
});

it('produces a useful message when the body carries no error at all', function () {
    $e = v2Exception(503, []);

    expect($e)->toBeInstanceOf(ServerException::class)
        ->and($e->getMessage())->toContain('503');
});

it('reports no issues for a V1 exception', function () {
    $mock = new MockClient([
        StubV2SendRequest::class => MockResponse::make(
            ['error' => ['code' => 'FIELD_EMPTY', 'description' => 'Required field is empty.']],
            400
        ),
    ]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    expect(KudosityException::fromV1Response($connector->send(new StubV2SendRequest('hi')))->getIssues())
        ->toBe([]);
});

it('treats a 201 as success, not a failure', function () {
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'abc'], 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $response = $connector->send(new StubV2SendRequest('hi'));

    expect($response->failed())->toBeFalse()
        ->and($connector->hasRequestFailed($response))->not->toBeTrue();
});

it('throws the mapped exception through Saloon throw()', function () {
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(problemBody(404), 404)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $connector->send(new StubV2SendRequest('hi'))->throw();
})->throws(NotFoundException::class);
```

This file reuses `StubV2SendRequest` from `tests/Unit/V2ConnectorTest.php`. Pest loads every test file in the suite, so the class is available — but if running this file alone reports the class missing, require it explicitly at the top rather than duplicating the stub.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/V2ErrorTest.php`
Expected: FAIL — `fromV2Response` is undefined, and `NotFoundException` / `ServerException` / `ProblemIssue` do not exist.

- [ ] **Step 3: Write the value object and the two new exceptions**

`packages/kudosity-client/src/Exceptions/ProblemIssue.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * One entry of an RFC 9457 `issues[]` array.
 *
 * The V2 API reports every failed field in a single response rather than one
 * per attempt, so a validation failure is a list of these.
 */
final readonly class ProblemIssue
{
    public function __construct(
        public string $name,
        public string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $issue
     */
    public static function fromArray(array $issue): self
    {
        return new self(
            name: is_string($issue['name'] ?? null) ? $issue['name'] : '',
            message: is_string($issue['message'] ?? null) ? $issue['message'] : '',
        );
    }
}
```

`packages/kudosity-client/src/Exceptions/NotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * The requested resource does not exist (V2 HTTP 404).
 */
class NotFoundException extends KudosityException {}
```

`packages/kudosity-client/src/Exceptions/ServerException.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * The API failed on its own side (V2 HTTP 5xx). Safe to retry with backoff.
 */
class ServerException extends KudosityException {}
```

- [ ] **Step 4: Add the V2 factory to `KudosityException`**

In `packages/kudosity-client/src/Exceptions/KudosityException.php`, add the status map beside the existing `$errorMap`, the issue storage, the factory, and the two accessors. Do not touch `fromV1Response()` or `$errorMap` — V1 behaviour must not change.

```php
    /**
     * V2 HTTP status to exception class.
     *
     * 400 and 422 both map to ValidationException on purpose: the error
     * registry documents InputValidationProblem as 422, while the RCS and
     * WhatsApp endpoint references show 400 for the same condition. Handling
     * both means we do not depend on which is true today.
     *
     * @var array<int, class-string<KudosityException>>
     */
    protected static array $v2StatusMap = [
        400 => ValidationException::class,
        401 => AuthenticationException::class,
        403 => AccessDeniedException::class,
        404 => NotFoundException::class,
        422 => ValidationException::class,
        429 => RateLimitException::class,
    ];

    /**
     * @var array<int, ProblemIssue>
     */
    protected array $issues = [];

    protected ?string $problemType = null;
```

```php
    /**
     * Create an exception from a V2 API response.
     *
     * Handles all three shapes V2 returns: RFC 9457 Problem Details under
     * `error` (the messaging endpoints), a plain string under `error` (the
     * webhook endpoints and `GET /v2/sms/{id}`'s 404), and no error key at all.
     *
     * @see https://developers.kudosity.com/reference/errors
     */
    public static function fromV2Response(Response $response): self
    {
        $status = $response->status();
        $json = $response->json();
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        $issues = [];
        $problemType = null;

        if (is_string($error) && $error !== '') {
            $message = $error;
        } elseif (is_array($error)) {
            $problemType = is_string($error['type'] ?? null) ? $error['type'] : null;

            foreach (is_array($error['issues'] ?? null) ? $error['issues'] : [] as $issue) {
                if (is_array($issue)) {
                    $issues[] = ProblemIssue::fromArray($issue);
                }
            }

            $message = self::messageFromProblem($error, $issues, $status);
        } else {
            $message = sprintf('API request failed with HTTP %d', $status);
        }

        $exceptionClass = static::$v2StatusMap[$status]
            ?? ($status >= 500 ? ServerException::class : self::class);

        if ($exceptionClass === RateLimitException::class) {
            $exception = RateLimitException::fromResponseWithMetadata($response, $message, null);
        } else {
            $exception = new $exceptionClass(
                message: $message,
                code: $status,
                response: $response,
            );
        }

        $exception->issues = $issues;
        $exception->problemType = $problemType;

        return $exception;
    }

    /**
     * Build a message from a Problem Details object.
     *
     * Prefers the per-field issues, because they name what the caller has to
     * change. Falls back to `detail`, then `title`, then the bare status.
     *
     * @param  array<string, mixed>  $error
     * @param  array<int, ProblemIssue>  $issues
     */
    protected static function messageFromProblem(array $error, array $issues, int $status): string
    {
        if ($issues !== []) {
            return implode('; ', array_map(
                static fn (ProblemIssue $issue): string => $issue->name !== ''
                    ? sprintf('%s: %s', $issue->name, $issue->message)
                    : $issue->message,
                $issues
            ));
        }

        foreach (['detail', 'title'] as $key) {
            if (is_string($error[$key] ?? null) && $error[$key] !== '') {
                return $error[$key];
            }
        }

        return sprintf('API request failed with HTTP %d', $status);
    }

    /**
     * Every field the V2 API reported as invalid. Empty for V1 errors.
     *
     * @return array<int, ProblemIssue>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * The RFC 9457 problem type URI, when the response carried one.
     */
    public function getProblemType(): ?string
    {
        return $this->problemType;
    }
```

If PHPStan objects to assigning `$exception->issues` on a `KudosityException` typed as the mapped subclass, keep the properties `protected` and assign inside a small private static helper, or widen the local variable's type — do not weaken the property visibility to `public`.

- [ ] **Step 5: Add failure detection to the V2 connector**

V2 has no `error.code: SUCCESS` quirk — the HTTP status is authoritative, and `201` from `POST /v2/webhook` is a success that Saloon already treats as one. In `packages/kudosity-client/src/KudosityV2Connector.php`:

```php
    /**
     * Determine whether the request failed.
     *
     * Unlike V1, which returns an `error` object even on success, V2 signals
     * failure purely by HTTP status — so Saloon's default 4xx/5xx handling is
     * exactly right and this returns null to defer to it. Notably `POST
     * /v2/webhook` answers 201, which is a success.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return null;
    }

    /**
     * Map a failed V2 response onto a typed exception.
     */
    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return KudosityException::fromV2Response($response);
    }
```

Add the imports: `ExpertSystems\Kudosity\Exceptions\KudosityException`, `Saloon\Http\Response`, `Throwable`.

`hasRequestFailed()` returning `null` unconditionally is the whole point — it documents that V2 needs no special-casing, in a codebase where V1 does. Keep it rather than omitting the override.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/V2ErrorTest.php`
Expected: PASS, 16 tests (8 from the dataset plus 8 individual cases).

- [ ] **Step 7: Verify the whole suite**

```bash
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: 281 passed, `[OK] No errors`, Pint clean.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: map V2 RFC 9457 errors onto typed exceptions"
```

---

## Task 4: The two V2 paginators

**Files:**
- Create: `packages/kudosity-client/src/Contracts/PaginatesV2Pages.php`
- Create: `packages/kudosity-client/src/Contracts/PaginatesV2Cursor.php`
- Create: `packages/kudosity-client/src/Pagination/V2PagedPaginator.php`
- Create: `packages/kudosity-client/src/Pagination/V2CursorPaginator.php`
- Modify: `packages/kudosity-client/src/KudosityV2Connector.php`
- Test: `tests/Unit/V2PaginationTest.php`

**Interfaces:**
- Consumes: `KudosityV2Connector` from Task 2, and the existing `Contracts\PaginatesResults` (which declares `paginationItemsKey(): string`).
- Produces:
  - `Contracts\PaginatesV2Pages extends PaginatesResults` — marker only.
  - `Contracts\PaginatesV2Cursor extends PaginatesResults` — marker only, adds `public function paginationDirection(): string` returning `next` or `prev`.
  - `Pagination\V2PagedPaginator extends PagedPaginator` with `public const DEFAULT_LIMIT = 100;`
  - `Pagination\V2CursorPaginator extends CursorPaginator` with `public const DEFAULT_LIMIT = 25;`
  - `KudosityV2Connector implements HasPagination` with `paginate(Request $request): V2PagedPaginator|V2CursorPaginator`, throwing `KudosityException` for a request declaring neither marker.
- Phase 3's `GET /v2/sms` request implements `PaginatesV2Pages` with items key `smses`; its WhatsApp and RCS list requests implement `PaginatesV2Cursor` with items key `data.messages`.

Two facts about Saloon's base `Paginator` this task depends on, both verified against `vendor/saloonphp/pagination-plugin/src/Paginator.php`: `$this->currentPage` is **0-indexed** (`$page` is deprecated), and `$this->perPageLimit` is null unless `setPerPageLimit()` was called. `Response::json('a.b')` resolves dot notation, which is how the cursor paginator reaches `data.messages`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/V2PaginationTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

class StubListSmsRequest extends KudosityV2Request implements PaginatesV2Pages
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/sms';
    }

    public function paginationItemsKey(): string
    {
        return 'smses';
    }
}

class StubListRcsRequest extends KudosityV2Request implements PaginatesV2Cursor
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/rcs/messages';
    }

    public function paginationItemsKey(): string
    {
        return 'data.messages';
    }

    public function paginationDirection(): string
    {
        return 'next';
    }
}

class StubUnpaginatedRequest extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/sms/abc';
    }
}

function pagedConnector(array $responses): KudosityV2Connector
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return $connector;
}

/** `total_records` is a string in real responses — that is the point. */
function smsPage(array $ids, string $totalRecords): MockResponse
{
    return MockResponse::make([
        'smses' => array_map(static fn (string $id): array => ['id' => $id], $ids),
        'total_records' => $totalRecords,
        'total_segments' => $totalRecords,
    ], 200);
}

function rcsPage(array $ids, bool $hasNext, ?string $nextCursor): MockResponse
{
    return MockResponse::make([
        'data' => ['messages' => array_map(static fn (string $id): array => ['id' => $id], $ids)],
        'meta' => ['pagination' => [
            'type' => 'cursor',
            'next_cursor' => $nextCursor,
            'prev_cursor' => null,
            'has_next' => $hasNext,
            'has_prev' => false,
        ]],
    ], 200);
}

it('dispatches to the right paginator from the request markers', function () {
    $connector = pagedConnector([StubListSmsRequest::class => smsPage(['a'], '1')]);

    expect($connector->paginate(new StubListSmsRequest))->toBeInstanceOf(V2PagedPaginator::class)
        ->and($connector->paginate(new StubListRcsRequest))->toBeInstanceOf(V2CursorPaginator::class);
});

it('refuses to paginate a request that declares no scheme', function () {
    pagedConnector([])->paginate(new StubUnpaginatedRequest);
})->throws(KudosityException::class);

it('sends page and limit, and walks pages until total_records is exhausted', function () {
    $connector = pagedConnector([
        StubListSmsRequest::class => MockResponse::make(['smses' => [['id' => 'a'], ['id' => 'b']], 'total_records' => '3'], 200),
    ]);

    $paginator = $connector->paginate(new StubListSmsRequest);
    $paginator->setPerPageLimit(2);

    $ids = [];
    foreach ($paginator->items() as $item) {
        $ids[] = $item['id'];
        if (count($ids) >= 4) {
            break; // guard against a runaway paginator failing the suite slowly
        }
    }

    expect($ids)->toBe(['a', 'b', 'a', 'b']);
});

it('starts at page 1, not page 0', function () {
    $mock = new MockClient([StubListSmsRequest::class => smsPage(['a'], '1')]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array($connector->paginate(new StubListSmsRequest)->items());

    expect($mock->getLastPendingRequest()->query()->get('page'))->toBe(1);
});

it('stops on an empty page', function () {
    $connector = pagedConnector([
        StubListSmsRequest::class => MockResponse::make(['smses' => [], 'total_records' => '0'], 200),
    ]);

    expect(iterator_to_array($connector->paginate(new StubListSmsRequest)->items()))->toBe([]);
});

it('stops on a short page when total_records is absent', function () {
    $connector = pagedConnector([
        StubListSmsRequest::class => MockResponse::make(['smses' => [['id' => 'a']]], 200),
    ]);

    $paginator = $connector->paginate(new StubListSmsRequest);
    $paginator->setPerPageLimit(10);

    expect(iterator_to_array($paginator->items()))->toHaveCount(1);
});

it('does arithmetic on the string total_records without concatenating it', function () {
    // total_records arrives as "10", not 10. ceil(10/5) is 2 pages; a version
    // that treated the total as a string would not land on 2.
    $connector = pagedConnector([
        StubListSmsRequest::class => smsPage(['a', 'b', 'c', 'd', 'e'], '10'),
    ]);

    $paginator = $connector->paginate(new StubListSmsRequest);
    $paginator->setPerPageLimit(5);

    expect(iterator_to_array($paginator->items()))->toHaveCount(10);
});

it('omits the cursor on the first request and sends limit and direction', function () {
    $mock = new MockClient([StubListRcsRequest::class => rcsPage(['a'], false, null)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $paginator = $connector->paginate(new StubListRcsRequest);
    $paginator->setPerPageLimit(25);
    iterator_to_array($paginator->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('cursor'))->toBeNull()
        ->and($query->get('limit'))->toBe(25)
        ->and($query->get('direction'))->toBe('next');
});

it('reads items from the nested data.messages path', function () {
    $connector = pagedConnector([StubListRcsRequest::class => rcsPage(['a', 'b'], false, null)]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))
        ->toHaveCount(2);
});

it('stops when has_next is false', function () {
    $connector = pagedConnector([StubListRcsRequest::class => rcsPage(['a'], false, 'ignored')]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))->toHaveCount(1);
});

it('stops when has_next is true but no cursor came back', function () {
    $connector = pagedConnector([StubListRcsRequest::class => rcsPage(['a'], true, null)]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))->toHaveCount(1);
});

it('stops when meta.pagination is missing entirely', function () {
    $connector = pagedConnector([
        StubListRcsRequest::class => MockResponse::make(['data' => ['messages' => [['id' => 'a']]]], 200),
    ]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))->toHaveCount(1);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/V2PaginationTest.php`
Expected: FAIL — the contracts, both paginators and `paginate()` do not exist.

- [ ] **Step 3: Write the two marker contracts**

`packages/kudosity-client/src/Contracts/PaginatesV2Pages.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;

/**
 * Marks a V2 request as using page-number pagination.
 *
 * `GET /v2/sms` takes `page` and `limit` and reports `total_records`.
 *
 * @see V2PagedPaginator
 */
interface PaginatesV2Pages extends PaginatesResults {}
```

`packages/kudosity-client/src/Contracts/PaginatesV2Cursor.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;

/**
 * Marks a V2 request as using cursor pagination.
 *
 * The WhatsApp and RCS list endpoints take `limit`, `cursor` and `direction`,
 * and report `meta.pagination`. Their items sit at `data.messages`, so the
 * inherited `paginationItemsKey()` returns a dot path.
 *
 * @see V2CursorPaginator
 */
interface PaginatesV2Cursor extends PaginatesResults
{
    /**
     * Which way to walk the cursor: `next` (default) or `prev`.
     */
    public function paginationDirection(): string;
}
```

- [ ] **Step 4: Write the paged paginator**

`packages/kudosity-client/src/Pagination/V2PagedPaginator.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Pagination;

use ExpertSystems\Kudosity\Contracts\PaginatesResults;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\PagedPaginator;

/**
 * Page-number pagination for the V2 API.
 *
 * `GET /v2/sms` takes `page` (1-indexed) and `limit`, and returns its items
 * under `smses` alongside `total_records`.
 *
 * **`total_records` is a string** — `"3"`, not `3` — so it is cast before any
 * arithmetic. Adding to it unconverted quietly concatenates.
 */
class V2PagedPaginator extends PagedPaginator
{
    /**
     * The API's own default when `limit` is not sent.
     */
    public const DEFAULT_LIMIT = 100;

    protected function applyPagination(Request $request): Request
    {
        // Saloon counts pages from 0 internally; the API counts from 1.
        $request->query()->add('page', $this->currentPage + 1);

        if ($this->perPageLimit !== null) {
            $request->query()->add('limit', $this->perPageLimit);
        }

        return $request;
    }

    /**
     * @return array<int, mixed>
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json($this->itemsKey($request)) ?? [];
    }

    protected function isLastPage(Response $response): bool
    {
        $items = $response->json($this->itemsKey($response->getRequest())) ?? [];

        if ($items === []) {
            return true;
        }

        $limit = $this->perPageLimit ?? self::DEFAULT_LIMIT;
        $total = $response->json('total_records');

        if ($total !== null) {
            $pages = (int) ceil(((int) $total) / $limit);

            return ($this->currentPage + 1) >= max(1, $pages);
        }

        // No total to work from: a page shorter than the limit is the last one.
        return count($items) < $limit;
    }

    protected function itemsKey(Request $request): string
    {
        return $request instanceof PaginatesResults ? $request->paginationItemsKey() : 'smses';
    }
}
```

- [ ] **Step 5: Write the cursor paginator**

`packages/kudosity-client/src/Pagination/V2CursorPaginator.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Pagination;

use ExpertSystems\Kudosity\Contracts\PaginatesResults;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\CursorPaginator;

/**
 * Cursor pagination for the V2 WhatsApp and RCS list endpoints.
 *
 * Takes `limit`, `cursor` and `direction`; reports `meta.pagination` with
 * `has_next` and `next_cursor`. Items sit at `data.messages`, so the request's
 * items key is a dot path.
 *
 * Saloon's own CursorPaginator sends `per_page`, which this API does not
 * understand, so applyPagination is replaced rather than extended.
 */
class V2CursorPaginator extends CursorPaginator
{
    /**
     * The API's own default when `limit` is not sent.
     */
    public const DEFAULT_LIMIT = 25;

    protected function applyPagination(Request $request): Request
    {
        // No cursor on the first request — there is nothing to continue from.
        if ($this->currentResponse instanceof Response) {
            $cursor = $this->nextCursorOrNull($this->currentResponse);

            if ($cursor !== null) {
                $request->query()->add('cursor', $cursor);
            }
        }

        if ($this->perPageLimit !== null) {
            $request->query()->add('limit', $this->perPageLimit);
        }

        if ($request instanceof PaginatesV2Cursor) {
            $request->query()->add('direction', $request->paginationDirection());
        }

        return $request;
    }

    protected function getNextCursor(Response $response): int|string
    {
        return $this->nextCursorOrNull($response) ?? '';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json($this->itemsKey($request)) ?? [];
    }

    protected function isLastPage(Response $response): bool
    {
        if (($response->json($this->itemsKey($response->getRequest())) ?? []) === []) {
            return true;
        }

        if ($response->json('meta.pagination.has_next') !== true) {
            return true;
        }

        // has_next says there is more, but without a cursor we cannot ask for
        // it — stop rather than re-requesting page one forever.
        return $this->nextCursorOrNull($response) === null;
    }

    protected function nextCursorOrNull(Response $response): ?string
    {
        $cursor = $response->json('meta.pagination.next_cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    protected function itemsKey(Request $request): string
    {
        return $request instanceof PaginatesResults ? $request->paginationItemsKey() : 'data.messages';
    }
}
```

- [ ] **Step 6: Dispatch from the connector**

In `packages/kudosity-client/src/KudosityV2Connector.php`, declare the interface and add the method. Add imports for `PaginatesV2Cursor`, `PaginatesV2Pages`, `V2CursorPaginator`, `V2PagedPaginator`, `Saloon\Http\Request` and `Saloon\PaginationPlugin\Contracts\HasPagination`:

```php
class KudosityV2Connector extends Connector implements HasPagination
```

```php
    /**
     * Build the paginator the request declares.
     *
     * V2 uses two incompatible schemes — page numbers on `GET /v2/sms`, cursors
     * on the WhatsApp and RCS lists — so the request names which one it speaks
     * and this picks the matching paginator.
     *
     * @throws KudosityException If the request declares no pagination scheme
     */
    public function paginate(Request $request): V2PagedPaginator|V2CursorPaginator
    {
        if ($request instanceof PaginatesV2Cursor) {
            return new V2CursorPaginator($this, $request);
        }

        if ($request instanceof PaginatesV2Pages) {
            return new V2PagedPaginator($this, $request);
        }

        throw new KudosityException(sprintf(
            '%s is not paginatable. Implement PaginatesV2Pages or PaginatesV2Cursor to page through it.',
            $request::class
        ));
    }
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/V2PaginationTest.php`
Expected: PASS, 12 tests.

If a test hangs, a paginator is not terminating — Saloon's own infinite-loop detector compares body checksums across five pages and will eventually throw, but fix the `isLastPage` logic rather than relying on that.

- [ ] **Step 8: Verify the whole suite**

```bash
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: 293 passed, `[OK] No errors`, Pint clean.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add the V2 paged and cursor paginators"
```

---

## Task 5: Split the V1 `SmsResource`

Phase 3 needs the `sms()` name for V2. The V1 send surface becomes `bulk()`, the reply readers move to `reporting()`, and the API-backed number formatter moves to `numbers()`.

**Files:**
- Create: `packages/kudosity-client/src/Resources/BulkSmsResource.php`
- Create: `packages/kudosity-client/src/Concerns/FormatsPhoneNumbers.php`
- Delete: `packages/kudosity-client/src/Resources/SmsResource.php`
- Modify: `packages/kudosity-client/src/Resources/ReportingResource.php`
- Modify: `packages/kudosity-client/src/Resources/NumbersResource.php`
- Modify: `packages/kudosity-laravel/src/Notifications/KudosityChannel.php`
- Modify: `rename-map.json`, `UPGRADING.md`
- Test: `tests/Unit/BulkSmsResourceTest.php`
- Modify: `tests/Unit/KudosityChannelTest.php`

**Interfaces:**
- Consumes: nothing from Tasks 1–4 — this is V1 work.
- Produces:
  - `Resources\BulkSmsResource` with `send(string $message, string $to, ?string $from = null, ?callable $configure = null): SmsData`, `sendToList(string $message, int $listId, ?string $from = null, ?callable $configure = null): SmsData`, `sendRequest(SendSmsRequest $request): SmsData`, `schedule(string $message, string $to, string|DateTimeInterface $at, ?string $from = null): SmsData`, `cancel(int $messageId): bool`, and the `FormatsPhoneNumbers` helpers.
  - `Concerns\FormatsPhoneNumbers` with `formatNumberLocal(string $number, ?string $countryCode = null): string`, `isValidNumber(string $number): bool`, `validateNumbers(string $numbers): array{valid: string[], invalid: string[]}`, `isValidSenderId(string $senderId): bool`. It reads `$this->connector->getDefaultCountryCode()`.
  - `ReportingResource` additionally: `getResponses(int $messageId)`, `getResponsesByKeywordId(int $keywordId)`, `getResponsesByKeyword(string $keyword, string $number)`, `getResponsesRequest(GetSmsResponsesRequest $request)`, `getAllResponses()`, `getAllResponsesRequest(GetUserSmsResponsesRequest $request)` — all returning `V1PagedPaginator`, all moved verbatim.
  - `NumbersResource::formatNumber(string $number, ?string $countryCode = null): FormattedNumberData`, moved verbatim.
- Task 6 exposes `bulk()` on the client and removes `sms()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/BulkSmsResourceTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Requests\CancelSmsRequest;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use ExpertSystems\Kudosity\Resources\BulkSmsResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function sendSmsSuccess(): MockResponse
{
    return MockResponse::make([
        'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
        'message_id' => 7788,
        'send_at' => '2026-08-05 10:00:00',
        'recipients' => 2,
        'cost' => 0.16,
        'sms' => 1,
    ], 200);
}

function bulkResource(array $responses): BulkSmsResource
{
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient(new MockClient($responses));

    return new BulkSmsResource($connector);
}

it('sends to multiple comma-separated recipients', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    $result = (new BulkSmsResource($connector))->send('Sale starts tomorrow', '61400000000,61400000001');

    expect($result)->toBeInstanceOf(SmsData::class)
        ->and($result->messageId)->toBe(7788)
        ->and($result->recipients)->toBe(2)
        ->and($mock->getLastPendingRequest()->body()->all()['to'])
        ->toBe('61400000000,61400000001');
});

it('sends to a contact list', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->sendToList('Sale', 4213644);

    expect($mock->getLastPendingRequest()->body()->all()['list_id'])->toBe(4213644);
});

it('schedules a send', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->schedule('Reminder', '61400000000', '2026-09-01 09:00:00');

    expect($mock->getLastPendingRequest()->body()->all()['send_at'])->toBe('2026-09-01 09:00:00');
});

it('applies the connector default sender and country code', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->setDefaultFrom('MyBrand')->setDefaultCountryCode('AU');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->send('Hi', '0400000000');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['from'])->toBe('MyBrand')
        ->and($body['countrycode'])->toBe('AU');
});

it('lets an explicit sender override the connector default', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->setDefaultFrom('MyBrand');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->send('Hi', '61400000000', from: 'Override');

    expect($mock->getLastPendingRequest()->body()->all()['from'])->toBe('Override');
});

it('passes the request to the configure closure after defaults are applied', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->send(
        'Hi',
        '61400000000',
        configure: fn (SendSmsRequest $r) => $r->validity(60)
    );

    expect($mock->getLastPendingRequest()->body()->all()['validity'])->toBe(60);
});

it('reports whether a cancel succeeded', function () {
    expect(bulkResource([
        CancelSmsRequest::class => MockResponse::make(['error' => ['code' => 'SUCCESS']], 200),
    ])->cancel(7788))->toBeTrue();
});

it('exposes the offline phone helpers', function () {
    $resource = bulkResource([]);

    expect($resource->isValidNumber('61400000000'))->toBeTrue()
        ->and($resource->isValidSenderId('MyBrand'))->toBeTrue()
        ->and($resource->formatNumberLocal('0400000000', 'AU'))->toBe('61400000000');
});
```

Before writing the resource, confirm the two helper expectations against the shipped `Support\PhoneNumber`: read it and check that `toInternational('0400000000', 'AU')` really returns `61400000000` with no `+`, and that `isValidSenderId('MyBrand')` is true. If either differs, fix the *test* to match the shipped behaviour and say so in your report — this task must not change `PhoneNumber`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/BulkSmsResourceTest.php`
Expected: FAIL — `BulkSmsResource` does not exist.

- [ ] **Step 3: Write the phone-helper trait**

Create `packages/kudosity-client/src/Concerns/FormatsPhoneNumbers.php`, moving the four offline helpers out of `SmsResource` verbatim:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use ExpertSystems\Kudosity\Support\PhoneNumber;

/**
 * Offline phone-number helpers, available wherever you are sending from.
 *
 * These do no I/O. The API-backed formatter lives on the numbers resource,
 * because it is a V1 endpoint call rather than a local utility.
 *
 * Requires the using class to expose a `$connector` with
 * `getDefaultCountryCode()`.
 */
trait FormatsPhoneNumbers
{
    /**
     * Format a number to E.164 locally, without an API call.
     */
    public function formatNumberLocal(string $number, ?string $countryCode = null): string
    {
        return PhoneNumber::toInternational($number, $countryCode ?? $this->connector->getDefaultCountryCode());
    }

    public function isValidNumber(string $number): bool
    {
        return PhoneNumber::isValid($number);
    }

    /**
     * @param  string  $numbers  Comma-separated numbers
     * @return array{valid: string[], invalid: string[]}
     */
    public function validateNumbers(string $numbers): array
    {
        return PhoneNumber::validateMultiple($numbers);
    }

    public function isValidSenderId(string $senderId): bool
    {
        return PhoneNumber::isValidSenderId($senderId);
    }
}
```

- [ ] **Step 4: Write `BulkSmsResource`**

Create `packages/kudosity-client/src/Resources/BulkSmsResource.php` by moving `send()`, `sendToList()`, `sendRequest()`, `cancel()` and `applyDefaults()` from `SmsResource` **verbatim**, adding `use FormatsPhoneNumbers;`, and adding one new method. Do not redesign the moved methods; this task is a move, and the review will check that.

The class docblock must say what it is and why it is not called `sms()`:

```php
/**
 * V1 bulk SMS: multiple recipients, contact lists, and scheduled sends.
 *
 * These are the sends V2 cannot do. `POST /v2/sms` takes exactly one recipient
 * and has no `send_at`, so multi-recipient, list and scheduled sends stay on
 * V1's `send-sms.json` — which is why this is `bulk()` and not `sms()`.
 *
 * @see https://developers.kudosity.com/reference/transmit-sms-api
 */
```

The one new method, which makes the scheduling path explicit rather than something you have to know to reach through `configure`:

```php
    /**
     * Send at a future time.
     *
     * Scheduling is V1-only — `POST /v2/sms` has no `send_at`.
     *
     * @param  string|DateTimeInterface  $at  ISO8601 `YYYY-MM-DD HH:MM:SS` in UTC, or a DateTimeInterface
     *
     * @throws KudosityException
     */
    public function schedule(
        string $message,
        string $to,
        string|DateTimeInterface $at,
        ?string $from = null,
    ): SmsData {
        return $this->send($message, $to, $from, static fn (SendSmsRequest $request) => $request->scheduledAt($at));
    }
```

Then delete `packages/kudosity-client/src/Resources/SmsResource.php`.

- [ ] **Step 5: Move the reply readers to `ReportingResource`**

Move `getResponses()`, `getResponsesByKeywordId()`, `getResponsesByKeyword()`, `getResponsesRequest()`, `getAllResponses()` and `getAllResponsesRequest()` from the deleted `SmsResource` into `packages/kudosity-client/src/Resources/ReportingResource.php` verbatim, carrying their docblocks. Add the imports they need (`GetSmsResponsesRequest`, `GetUserSmsResponsesRequest`, `V1PagedPaginator`).

Add a line to the class docblock explaining the grouping: replies are reads, and every other read already lives here.

- [ ] **Step 6: Move the API-backed formatter to `NumbersResource`**

Move `formatNumber()` from the deleted `SmsResource` into `packages/kudosity-client/src/Resources/NumbersResource.php` verbatim, with its imports (`FormatNumberRequest`, `FormattedNumberData`, `KudosityException`). It calls `format-number.json`, so it belongs with the other number endpoints.

- [ ] **Step 7: Point the Laravel channel at `bulk()`**

In `packages/kudosity-laravel/src/Notifications/KudosityChannel.php`, the final send currently reads:

```php
        return $this->client->sms()->sendRequest($request);
```

Change it to:

```php
        return $this->client->bulk()->sendRequest($request);
```

Phase 5 rewires this channel for V2 properly; this keeps it working meanwhile. Update any `sms()` expectation in `tests/Unit/KudosityChannelTest.php` to match — run the file and let the failures tell you which lines.

- [ ] **Step 8: Record the moves for consumers**

This is the constraint that Phase 1's final review existed to enforce. Both files, in this commit.

In `rename-map.json`, these are method *moves*, not renames — the method name is unchanged, the accessor before it changed. A text codemod cannot safely rewrite `->sms()->sendToList(` to `->bulk()->sendToList(` without risking a consumer's own `sms()`. So add them to the `removed` group as manual-review pointers rather than to `classes` or `methods`:

```json
  "removed": {
    "useSmsUrl": null,
    "useMmsUrl": null,
    "BASE_URL_MMS": null,
    "sendToList": null,
    "getResponses": null,
    "getAllResponses": null
  },
```

Verify after editing that `php bin/kudosity-codemod packages` still reports 0 changes and that the new entries produce `review by hand` lines rather than rewrites.

In `UPGRADING.md`, add a **Resource surface changes** section with a table:

| 1.x | 2.x |
|---|---|
| `$client->sms()->send($msg, $to)` — multiple recipients | `$client->bulk()->send($msg, $to)` |
| `$client->sms()->sendToList($msg, $listId)` | `$client->bulk()->sendToList($msg, $listId)` |
| `$client->sms()->sendRequest($request)` | `$client->bulk()->sendRequest($request)` |
| `$client->sms()->cancel($id)` | `$client->bulk()->cancel($id)` |
| `$client->sms()->getResponses($id)` | `$client->reporting()->getResponses($id)` |
| `$client->sms()->getResponsesByKeywordId($id)` | `$client->reporting()->getResponsesByKeywordId($id)` |
| `$client->sms()->getResponsesByKeyword($kw, $n)` | `$client->reporting()->getResponsesByKeyword($kw, $n)` |
| `$client->sms()->getAllResponses()` | `$client->reporting()->getAllResponses()` |
| `$client->sms()->formatNumber($n)` | `$client->numbers()->formatNumber($n)` |
| `$client->sms()->formatNumberLocal($n)` | `$client->bulk()->formatNumberLocal($n)` |
| `$client->sms()->isValidNumber($n)` | `$client->bulk()->isValidNumber($n)` |
| `$client->sms()->validateNumbers($n)` | `$client->bulk()->validateNumbers($n)` |
| `$client->sms()->isValidSenderId($s)` | `$client->bulk()->isValidSenderId($s)` |

State plainly why: `sms()` is reserved for the V2 single-recipient API arriving in the next release, and the codemod cannot automate these because it cannot tell your `sms()` from ours. Add `$client->bulk()->schedule($msg, $to, $at)` as the new explicit way to schedule.

- [ ] **Step 9: Verify**

```bash
composer dump-autoload
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
php bin/kudosity-codemod packages 2>&1 | tail -3
git grep -rn 'sms()' -- packages | grep -v 'bulk()' || echo "no stale sms() calls"
```

Expected: 301 passed (293 + 8), `[OK] No errors`, Pint clean, codemod reporting 0 changes for `packages`, and no remaining `->sms()` call inside the packages. Editing `KudosityChannelTest` to say `bulk()` should not change its case count — if it does, say which case you added or removed and why.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "refactor!: split the V1 SmsResource into bulk(), reporting() and numbers()"
```

---

## Task 6: Wire both connectors into the client

**Files:**
- Modify: `packages/kudosity-client/src/KudosityClient.php`
- Modify: `packages/kudosity-client/src/KudosityV1Connector.php`
- Modify: `packages/kudosity-laravel/src/Facades/Kudosity.php`
- Modify: `tests/ArchTest.php` if it names `SmsResource`
- Modify: `CHANGELOG.md`, `UPGRADING.md`
- Test: `tests/Unit/KudosityClientTest.php` (new)

**Interfaces:**
- Consumes: `KudosityV2Connector` (Task 2), `BulkSmsResource` (Task 5).
- Produces:
  - `KudosityClient::__construct(string $apiKey, string $apiSecret = '', ?string $v1BaseUrl = null, ?string $v2BaseUrl = null, int $timeout = 30)`
  - `KudosityClient::v1(): KudosityV1Connector`, `v2(): KudosityV2Connector`, `connector(): KudosityV1Connector` (retained, delegates to `v1()`)
  - `KudosityClient::fromConnectors(?KudosityV1Connector $v1 = null, ?KudosityV2Connector $v2 = null): self`; `fromConnector(KudosityV1Connector $connector): self` retained as a V1-only shorthand
  - `KudosityClient::bulk(): BulkSmsResource`; `sms()` **removed**
  - `KudosityV1Connector` throws `KudosityException` with a clear message when the secret is empty at send time
- Phase 3 adds `sms()`, `mms()`, `whatsapp()`, `rcs()`; Phase 4 adds `webhooks()`, `senders()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/KudosityClientTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\GetBalanceRequest;
use ExpertSystems\Kudosity\Resources\BulkSmsResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('exposes both connectors, each on its own host', function () {
    $client = new KudosityClient('key', 'secret');

    expect($client->v1())->toBeInstanceOf(KudosityV1Connector::class)
        ->and($client->v2())->toBeInstanceOf(KudosityV2Connector::class)
        ->and($client->v1()->resolveBaseUrl())->toBe('https://api.transmitsms.com')
        ->and($client->v2()->resolveBaseUrl())->toBe('https://api.transmitmessage.com');
});

it('keeps connector() as the V1 connector', function () {
    $client = new KudosityClient('key', 'secret');

    expect($client->connector())->toBe($client->v1());
});

it('never sends the secret over V2', function () {
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'x'], 200)]);

    $client = new KudosityClient('key', 'super-secret');
    $client->v2()->withMockClient($mock);
    $client->v2()->send(new StubV2SendRequest('hi'));

    $headers = $mock->getLastPendingRequest()->headers()->all();

    expect($client->v2()->getApiKey())->toBe('key')
        ->and($headers)->not->toHaveKey('Authorization')
        ->and(implode('|', array_map('strval', $headers)))->not->toContain('super-secret');
});

it('constructs without a secret for V2-only use', function () {
    $client = new KudosityClient('key');

    expect($client->v2()->getApiKey())->toBe('key');
});

it('fails a V1 call with a clear message when no secret was given', function () {
    $connector = new KudosityV1Connector('key', '');
    $connector->withMockClient(new MockClient([GetBalanceRequest::class => MockResponse::make([], 200)]));

    $connector->send(new GetBalanceRequest);
})->throws(KudosityException::class, 'V1 API requires both');

it('accepts per-API base URL overrides', function () {
    $client = new KudosityClient('key', 'secret', 'https://v1.test', 'https://v2.test');

    expect($client->v1()->resolveBaseUrl())->toBe('https://v1.test')
        ->and($client->v2()->resolveBaseUrl())->toBe('https://v2.test');
});

it('exposes bulk() and caches the instance', function () {
    $client = new KudosityClient('key', 'secret');

    expect($client->bulk())->toBeInstanceOf(BulkSmsResource::class)
        ->and($client->bulk())->toBe($client->bulk());
});

it('no longer exposes sms(), which V2 will claim', function () {
    expect(method_exists(KudosityClient::class, 'sms'))->toBeFalse();
});

it('builds from a pair of pre-configured connectors', function () {
    $v1 = new KudosityV1Connector('k', 's', 'https://v1.test');
    $v2 = new KudosityV2Connector('k', 'https://v2.test');

    $client = KudosityClient::fromConnectors($v1, $v2);

    expect($client->v1())->toBe($v1)
        ->and($client->v2())->toBe($v2);
});

it('builds from a V1 connector alone and still provides a V2 connector', function () {
    $v1 = new KudosityV1Connector('k', 's');

    $client = KudosityClient::fromConnector($v1);

    expect($client->v1())->toBe($v1)
        ->and($client->v2())->toBeInstanceOf(KudosityV2Connector::class)
        ->and($client->v2()->getApiKey())->toBe('k');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/KudosityClientTest.php`
Expected: FAIL — `v1()`, `v2()`, `bulk()` and `fromConnectors()` do not exist, and `sms()` still does.

- [ ] **Step 3: Guard the V1 secret**

In `packages/kudosity-client/src/KudosityV1Connector.php`, make the missing-secret case explain itself rather than surfacing as a 401 from the wire:

```php
    /**
     * Define the default authentication.
     *
     * V1 needs both halves of the credential. A client built for V2 only has
     * no secret, so say so plainly rather than letting the API answer 401.
     *
     * @throws KudosityException
     */
    protected function defaultAuth(): BasicAuthenticator
    {
        if ($this->apiSecret === '') {
            throw new KudosityException(
                'The Kudosity V1 API requires both an API key and an API secret. '
                .'Set KUDOSITY_API_SECRET (Developers → API Settings in the dashboard). '
                .'The V2 API needs only the key.'
            );
        }

        return new BasicAuthenticator($this->apiKey, $this->apiSecret);
    }
```

Add the `KudosityException` import. Note the test asserts on the substring `V1 API requires both`, so keep that wording.

- [ ] **Step 4: Rework the client**

In `packages/kudosity-client/src/KudosityClient.php`:

Replace the single `$connector` property with two, and add the `BulkSmsResource` cache while removing the `SmsResource` one:

```php
    protected KudosityV1Connector $v1Connector;

    protected KudosityV2Connector $v2Connector;

    protected ?BulkSmsResource $bulkResource = null;
```

Replace the constructor:

```php
    /**
     * Create a new Kudosity client.
     *
     * Kudosity runs two APIs under one account. V2 (`api.transmitmessage.com`)
     * authenticates with the key alone and covers single-recipient SMS, MMS,
     * WhatsApp, RCS, webhooks and senders. V1 (`api.transmitsms.com`) needs the
     * key and secret and covers contact lists, bulk and scheduled sends,
     * reporting and balance. Omit the secret if you only need V2.
     *
     * @param  string  $apiKey  Your Kudosity API key — used by both APIs
     * @param  string  $apiSecret  Your Kudosity API secret — V1 only
     * @param  string|null  $v1BaseUrl  Override the V1 host
     * @param  string|null  $v2BaseUrl  Override the V2 host
     * @param  int  $timeout  Request timeout in seconds, applied to both
     */
    public function __construct(
        string $apiKey,
        string $apiSecret = '',
        ?string $v1BaseUrl = null,
        ?string $v2BaseUrl = null,
        int $timeout = 30,
    ) {
        $this->v1Connector = new KudosityV1Connector(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            baseUrl: $v1BaseUrl ?? KudosityV1Connector::BASE_URL,
            timeout: $timeout,
        );

        $this->v2Connector = new KudosityV2Connector(
            apiKey: $apiKey,
            baseUrl: $v2BaseUrl ?? KudosityV2Connector::BASE_URL,
            timeout: $timeout,
        );
    }
```

Replace `fromConnector()` and add `fromConnectors()`:

```php
    /**
     * Build from pre-configured connectors, for a container or a shared setup.
     *
     * A connector you do not supply is constructed from the other's API key,
     * which both APIs share.
     */
    public static function fromConnectors(
        ?KudosityV1Connector $v1 = null,
        ?KudosityV2Connector $v2 = null,
    ): self {
        // Written this way rather than `$v1?->getApiKey() ?? $v2->getApiKey()`
        // so PHPStan can see that $v2 is non-null on the branch that uses it.
        if ($v1 !== null) {
            $apiKey = $v1->getApiKey();
        } elseif ($v2 !== null) {
            $apiKey = $v2->getApiKey();
        } else {
            throw new KudosityException('Provide at least one connector.');
        }

        $client = new self($apiKey);

        $client->v1Connector = $v1 ?? $client->v1Connector;
        $client->v2Connector = $v2 ?? $client->v2Connector;

        return $client;
    }

    /**
     * Build from a V1 connector alone. The V2 connector is derived from its key.
     */
    public static function fromConnector(KudosityV1Connector $connector): self
    {
        return self::fromConnectors(v1: $connector);
    }
```

Note that `fromConnectors(v1: $x)` loses `$x`'s secret when deriving the V2 connector — which is correct, V2 must not have it — but it also means the derived V2 connector inherits neither `$x`'s base URL nor its timeout. That is intentional: they are different hosts. Say so in a comment so nobody "fixes" it.

Replace the accessors:

```php
    /**
     * The V1 connector (`api.transmitsms.com`, key + secret).
     */
    public function v1(): KudosityV1Connector
    {
        return $this->v1Connector;
    }

    /**
     * The V2 connector (`api.transmitmessage.com`, key only).
     */
    public function v2(): KudosityV2Connector
    {
        return $this->v2Connector;
    }

    /**
     * The V1 connector. Kept for callers that predate the two-connector split.
     */
    public function connector(): KudosityV1Connector
    {
        return $this->v1();
    }

    /**
     * V1 bulk SMS: multiple recipients, contact lists, scheduled sends, cancel.
     *
     * V2's `sms()` — arriving in the next release — takes exactly one recipient
     * and cannot schedule, so these stay on V1.
     */
    public function bulk(): BulkSmsResource
    {
        return $this->bulkResource ??= new BulkSmsResource($this->v1Connector);
    }
```

Delete `sms()` and the `SmsResource` import and property. Point every remaining `$this->connector` reference in the file at `$this->v1Connector`, including inside `send()`, `sendAndGetJson()`, `validateResponse()` and `setBaseUrl()`. `setBaseUrl()` sets the **V1** base URL — rename it `setV1BaseUrl()` and keep `setBaseUrl()` delegating to it, with a docblock saying which host it means, since an ambiguous `setBaseUrl()` on a two-API client is a trap.

Also update `validateResponse()` — it uses the V1 `error.code` convention, so its docblock must say it is V1-only, and `send()`/`sendAndGetJson()` keep taking `KudosityV1Request`.

- [ ] **Step 5: Update the facade annotations**

In `packages/kudosity-laravel/src/Facades/Kudosity.php`, replace the `sms()` `@method` line with `bulk()`, add `v1()` and `v2()`, and drop any `@method` naming a removed member:

```php
 * @method static \ExpertSystems\Kudosity\KudosityV1Connector v1()
 * @method static \ExpertSystems\Kudosity\KudosityV2Connector v2()
 * @method static \ExpertSystems\Kudosity\Resources\BulkSmsResource bulk()
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/KudosityClientTest.php`
Expected: PASS, 10 tests. Full suite reaches 311.

The missing-secret case relies on `defaultAuth()` being resolved even for a mocked send. If Saloon short-circuits authentication under `MockClient` and the exception never fires, move the guard to `boot(PendingRequest $pendingRequest)` instead — the requirement is that a V1 send with no secret throws a `KudosityException` naming the problem, not that it throws from one particular hook. Report whichever you used.

- [ ] **Step 7: Record the changes for consumers**

Append to `CHANGELOG.md` under the existing `## Unreleased`, in its `### Breaking` and `### Added` subsections rather than creating new ones:

```markdown
- `KudosityClient` now holds two connectors. `v1()` and `v2()` return them; `connector()` still returns the V1 connector. `fromConnector()` takes a V1 connector as before, and `fromConnectors()` accepts either or both. The constructor's `$baseUrl` parameter is replaced by `$v1BaseUrl` and `$v2BaseUrl`, and `$apiSecret` is now optional — omit it for V2-only use. `setBaseUrl()` is now `setV1BaseUrl()`, with the old name delegating to it.
- Removed `KudosityClient::sms()`. The V1 send surface is `bulk()`, the reply readers moved to `reporting()`, and the API-backed `formatNumber()` moved to `numbers()`. `sms()` returns in the next release as the V2 single-recipient API. See UPGRADING.md.
- A V1 call with no API secret now throws `KudosityException` explaining that V1 needs both credentials, instead of failing with a 401 from the API.
```

```markdown
- `KudosityV2Connector` for the V2 API (`api.transmitmessage.com`, `x-api-key`), with `KudosityV2Request` as the JSON-body request base.
- `KudosityException::fromV2Response()` maps V2's RFC 9457 Problem Details onto typed exceptions, adding `NotFoundException` and `ServerException`, and exposes every failed field via `getIssues()`.
- `V2PagedPaginator` and `V2CursorPaginator` for V2's two pagination schemes, selected by the `PaginatesV2Pages` and `PaginatesV2Cursor` contracts.
- `BulkSmsResource::schedule()` makes a scheduled V1 send explicit.
- `Concerns\HasRetryPolicy`, `Concerns\UnwrapsData` and `Concerns\FormatsPhoneNumbers`.
```

In `UPGRADING.md`, extend the Resource surface changes table from Task 5 with the client-level changes, and add a short **Two connectors** subsection covering `v1()`/`v2()`, the optional secret, and the `setBaseUrl()` rename.

- [ ] **Step 8: Verify the whole phase**

```bash
composer dump-autoload
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
composer validate --strict
(cd packages/kudosity-client && composer validate --strict)
(cd packages/kudosity-laravel && composer validate --strict)
php bin/kudosity-codemod packages 2>&1 | tail -2
php bin/kudosity-codemod tests 2>&1 | tail -2
git grep -in 'transmitsms' -- . \
  ':(exclude).agents/*' ':(exclude)docs/*' ':(exclude)CHANGELOG.md' \
  ':(exclude)UPGRADING.md' ':(exclude)rename-map.json' \
  ':(exclude)bin/kudosity-codemod' ':(exclude)tests/Unit/CodemodTest.php' \
  | grep -v 'api\.transmitsms\.com' | grep -v 'TransmitSMS is now' || echo "sweep clean"
```

Expected: full suite green, `[OK] No errors`, Pint clean, three valid manifests, `packages` → 0 codemod changes, `tests` → exactly `Unit/CodemodTest.php`, sweep clean.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat!: hold both connectors on KudosityClient and expose bulk()"
```

---

## Definition of done

- Both connectors exist, each on its own host, and the V2 connector has no path to the API secret — asserted, not assumed.
- All three V2 error shapes map to typed exceptions, and `getIssues()` reports every failed field from one response.
- Both V2 paginators terminate correctly on every documented signal, and `total_records` is cast before arithmetic.
- `UnwrapsData` resolves both envelope shapes, including the case where a flat payload has its own `data` field.
- `sms()` is free for Phase 3. Every method that moved off it is reachable at its new home and recorded in both `rename-map.json` and `UPGRADING.md`.
- `php bin/kudosity-codemod packages` reports 0 changes; the new `removed` entries produce `review by hand` pointers rather than rewrites.
- Suite green and above 256; PHPStan level 6 clean; Pint clean; three manifests valid; branding sweep clean.
- No V2 endpoint class exists yet — that is Phase 3, and adding one here is scope creep.
