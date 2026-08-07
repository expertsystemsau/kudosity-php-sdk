# Kudosity Migration Phase 3: V2 Channels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the four V2 messaging channels — SMS, MMS, WhatsApp, RCS — on top of Phase 2's transport, so `$k->sms()`, `$k->mms()`, `$k->whatsapp()` and `$k->rcs()` send and read real messages.

**Architecture:** Each channel is a resource over Phase 2's `KudosityV2Connector`, with one request class per endpoint and one readonly DTO per response shape. The API's documented traps get encoded as types rather than left to callers: WhatsApp's nested `content` envelope becomes value objects that cannot be flattened, RCS's agent-ID sender is validated before the request leaves the process, and the string-typed numerics are cast in the DTOs.

**Tech Stack:** PHP 8.2+, Saloon v4, Pest 4, PHPStan level 6 via Larastan, Laravel Pint.

## Global Constraints

- Runtime floor stays `"php": "^8.2"` in both packages. Laravel stays `illuminate/* ^11.0||^12.0`.
- `composer analyse` (PHPStan **level 6**) must report `[OK] No errors` at the end of every task.
- `vendor/bin/pint --test` must pass. Run `composer format` to fix.
- Baseline entering this phase: **322 tests, 598 assertions**. The count only goes up.
- The two hostnames are `https://api.transmitsms.com` (V1) and `https://api.transmitmessage.com` (V2). Neither is a Kudosity domain; neither may be "corrected". Never write a sweep pattern like `\btransmitsms\b` — the dots in the hostname are word boundaries, which is how Phase 1 corrupted `BASE_URL`.
- Never modify `.agents/skills/` — vendored upstream API references. Append to `CHANGELOG.md` under the existing `## Unreleased`; never rewrite history.
- **Read the relevant skill in `.agents/skills/` before writing any request class.** They are the authoritative per-endpoint contract, including the gotchas this plan encodes. `kudosity-sms`, `kudosity-mms`, `kudosity-whatsapp`, `kudosity-whatsapp-templates`, `kudosity-rcs`.
- **The removed-symbol audit is mandatory in Task 6.** Both prior phases' final reviews caught the same defect: a removed public symbol missing from `rename-map.json`, because removals land in one task and the map in another. Phase 1 omitted four constants and methods; Phase 2 omitted the deleted class `SmsResource`. Before this phase's final review, diff every public symbol this phase adds, renames or removes against `rename-map.json` and `UPGRADING.md`.
- Note the flagger's shape: `removed` entries are matched with a trailing `(` appended, so they cannot match a `use` statement, a `::class` or a type hint. A removed **class** must go in `classes` with a real target; only methods belong in `removed`.
- Work on a new branch `feat/kudosity-v2-channels` cut from `main` (currently `3490534`). `main` is local-only and unpushed — do not push it.
- `/docs` is gitignored; committing anything under it needs `git add -f`.

## Reference

- Design spec: `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`, sections 2 and 3.
- Phase 2 plan, for the conventions this phase inherits: `docs/superpowers/plans/2026-08-05-kudosity-phase-2-v2-core.md`.

### What Phase 2 built, that this phase consumes

| Piece | Use |
|---|---|
| `KudosityV2Connector` | `api.transmitmessage.com`, `x-api-key`, key only. Implements `HasPagination`; its `paginate()` dispatches on the request's marker contract. |
| `Requests\KudosityV2Request` | Abstract base, **no body**, default `Method::POST`, carries `UnwrapsData`. GET readers extend this. |
| `Requests\KudosityV2BodyRequest` | Extends the above, adds `HasBody` + `HasJsonBody`. **Every request that sends a body extends this one.** |
| `Concerns\UnwrapsData` | `static::payload($response)` resolves both V2 envelope shapes. Already on the request base, so `createDtoFromResponse()` calls it directly. |
| `Concerns\DecodesResponses` | Guards a speculative body read against `JsonException`/`TypeError`. |
| `Exceptions\KudosityException::fromV2Response()` | RFC 9457 → typed exception. `NotFoundException`, `ServerException`, `ValidationException` with `getIssues()`. |
| `Pagination\V2PagedPaginator` | `page`/`limit`, `total_records`. Requests declare `Contracts\PaginatesV2Pages`. |
| `Pagination\V2CursorPaginator` | `limit`/`cursor`/`direction`, `meta.pagination`. Requests declare `Contracts\PaginatesV2Cursor` and implement `paginationDirection()`. |

**Payload resolution lives on the request**, not the DTO. `UnwrapsData` is already on `KudosityV2Request`, so a concrete request's `createDtoFromResponse()` does `SomeData::fromArray(static::payload($response))`. Follow that; do not put the trait on DTOs.

### The endpoint inventory this phase implements

| Channel | Endpoints | Envelope | Pagination |
|---|---|---|---|
| SMS | `POST /v2/sms`, `GET /v2/sms/{id}`, `GET /v2/sms` | **flat** | paged (`smses`) |
| MMS | `POST /v2/mms`, `GET /v2/mms/{id}` | **flat** | — |
| WhatsApp | `POST /v2/whatsapp/messages`, `GET /v2/whatsapp/messages/{id}`, `GET /v2/whatsapp/messages` | **wrapped in `data`** | cursor (`data.messages`) |
| RCS | `POST /v2/rcs/messages`, `GET /v2/rcs/messages/{id}`, `GET /v2/rcs/messages`, `POST /v2/rcs/capabilities` | **wrapped in `data`** | cursor (`data.messages`) |

Out of scope: webhooks and senders (Phase 4), Laravel channels (Phase 5), the client package's standalone PHPUnit suite and the release (Phase 6).

### A note on this plan's code blocks

Four channels repeat the same request/DTO/resource shape. Where a shape repeats, the plan gives the **first instance in full** and the siblings as exact endpoint, field, type and signature specifications. That is deliberate: pasting four near-identical DTOs would obscure the differences that matter. Every sibling spec names every field and its cast, so nothing is left to guess — but if a spec is ambiguous when you reach it, ask rather than inferring.

---

## File Structure

### New — shared (Task 1)

| Path | Responsibility |
|---|---|
| `src/Resources/V2Resource.php` | Base for V2 resources: holds the V2 connector, sends, throws, DTOs |
| `src/Enums/MessageStatus.php` | The union of every documented status across V2's three vocabularies |
| `src/Data/V2/SmsFallback.php` | The `sms_fallback` object shared by WhatsApp and RCS |

### New — per channel (Tasks 2–5)

| Channel | Requests | Data | Resource |
|---|---|---|---|
| SMS | `SendSmsV2Request`, `GetSmsV2Request`, `ListSmsV2Request` | `V2\SmsMessageData`, `V2\SmsListData` | `SmsV2Resource` |
| MMS | `SendMmsRequest`, `GetMmsRequest` | `V2\MmsMessageData` | `MmsResource` |
| WhatsApp | `SendWhatsAppRequest`, `GetWhatsAppRequest`, `ListWhatsAppRequest` | `V2\WhatsAppMessageData`, `V2\Content\{TextContent,TemplateContent,CustomContent}`, `Contracts\WhatsAppContent` | `WhatsAppResource` |
| RCS | `SendRcsRequest`, `GetRcsRequest`, `ListRcsRequest`, `CheckRcsCapabilitiesRequest` | `V2\RcsMessageData`, `V2\RcsCapabilityData`, `Enums\RcsCapabilityCode` | `RcsResource` |

New `Data/V2/` and `Enums/` directories keep the V2 DTOs from colliding with the V1 ones already in `Data/` — note `Data\SmsListData` already exists as a V1 shape, which is why the V2 one is namespaced.

### Modified (Task 6)

`src/KudosityClient.php`, `packages/kudosity-laravel/src/Facades/Kudosity.php`, `rename-map.json`, `UPGRADING.md`, `CHANGELOG.md`, `packages/kudosity-client/README.md`, `README.md`, `CLAUDE.md`.

---

## Task 1: Shared V2 foundations

**Files:**
- Create: `src/Resources/V2Resource.php`, `src/Enums/MessageStatus.php`, `src/Data/V2/SmsFallback.php`
- Test: `tests/Unit/V2FoundationsTest.php`

**Interfaces:**
- Consumes: `KudosityV2Connector`, `KudosityV2Request` from Phase 2.
- Produces:
  - `abstract Resources\V2Resource` with `__construct(protected KudosityV2Connector $connector)`, `protected function sendAndDto(KudosityV2Request $request): mixed`, and `protected function paginate(KudosityV2Request $request): Paginator`.
  - `enum Enums\MessageStatus: string` with 14 cases and `public static function fromApi(?string $value): self`.
  - `final readonly Data\V2\SmsFallback` with `__construct(public string $message, public ?string $sender = null)`, `toArray(): array`, `fromArray(array $data): self`.

- [ ] **Step 1: Record the baseline**

```bash
git checkout -b feat/kudosity-v2-channels
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
```

Expected: `322 passed (598 assertions)`, `[OK] No errors`.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/V2FoundationsTest.php`:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Enums\MessageStatus;

it('maps every documented status across all three V2 vocabularies', function (string $api, MessageStatus $expected) {
    expect(MessageStatus::fromApi($api))->toBe($expected);
})->with([
    // GET /v2/sms filter vocabulary (13)
    ['PENDING', MessageStatus::Pending],
    ['SENT', MessageStatus::Sent],
    ['FAILED', MessageStatus::Failed],
    ['DELIVERED', MessageStatus::Delivered],
    ['ACCEPTED', MessageStatus::Accepted],
    ['SOFT_BOUNCE', MessageStatus::SoftBounce],
    ['HARD_BOUNCE', MessageStatus::HardBounce],
    ['OTHER', MessageStatus::Other],
    ['REJECTED', MessageStatus::Rejected],
    ['PENDING_APPROVAL', MessageStatus::PendingApproval],
    ['SUBMITTED', MessageStatus::Submitted],
    ['UNDELIVERABLE', MessageStatus::Undeliverable],
    ['READ', MessageStatus::Read],
    // WhatsApp/RCS list vocabulary contributes one more
    ['QUEUED', MessageStatus::Queued],
]);

it('resolves an unrecognised or absent status to Unknown rather than throwing', function () {
    // The docs state these lists will grow, so a new upstream value must not
    // break a client that is only reading messages.
    expect(MessageStatus::fromApi('TELEPORTED'))->toBe(MessageStatus::Unknown)
        ->and(MessageStatus::fromApi(null))->toBe(MessageStatus::Unknown)
        ->and(MessageStatus::fromApi(''))->toBe(MessageStatus::Unknown);
});

it('accepts a lowercase status, since the send response returns lowercase', function () {
    // POST /v2/sms answers "status": "delivered"; the list filter uses
    // "DELIVERED". Same value, two casings, one enum.
    expect(MessageStatus::fromApi('delivered'))->toBe(MessageStatus::Delivered)
        ->and(MessageStatus::fromApi('pending'))->toBe(MessageStatus::Pending);
});

it('builds an sms_fallback body with the sender omitted when absent', function () {
    expect((new SmsFallback('Shorter plain text'))->toArray())
        ->toBe(['message' => 'Shorter plain text']);
});

it('includes the sender when given', function () {
    expect((new SmsFallback('Body', '61491570017'))->toArray())
        ->toBe(['message' => 'Body', 'sender' => '61491570017']);
});

it('rejects an empty fallback message, which the API requires', function () {
    new SmsFallback('');
})->throws(ExpertSystems\Kudosity\Exceptions\ValidationException::class, 'message');

it('round-trips from an API payload', function () {
    $f = SmsFallback::fromArray(['message' => 'Body', 'sender' => '614810']);

    expect($f->message)->toBe('Body')->and($f->sender)->toBe('614810');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/V2FoundationsTest.php`
Expected: FAIL — `MessageStatus` and `SmsFallback` do not exist.

- [ ] **Step 4: Write the enum**

Create `src/Enums/MessageStatus.php`. The 14 cases are the **union of three disagreeing vocabularies** — the `GET /v2/sms` filter documents 13, webhook status events document 8, and the WhatsApp/RCS lists document 5 including `QUEUED`, which appears in neither of the others. One enum holds all of them:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * Every message status the V2 API documents, across three vocabularies.
 *
 * `GET /v2/sms`'s filter accepts 13 values; webhook status events document 8;
 * the WhatsApp and RCS list endpoints document 5, one of which — QUEUED —
 * appears nowhere else. Rather than three enums that disagree, this is their
 * union, and each endpoint's README section records which subset it emits.
 *
 * The upstream docs say these lists will grow, so resolution goes through
 * {@see self::fromApi()} and lands on {@see self::Unknown} rather than
 * throwing: a client reading its own message history must not break because
 * Kudosity added a status.
 */
enum MessageStatus: string
{
    case Pending = 'PENDING';
    case Queued = 'QUEUED';
    case Submitted = 'SUBMITTED';
    case Sent = 'SENT';
    case Accepted = 'ACCEPTED';
    case Delivered = 'DELIVERED';
    case Read = 'READ';
    case Failed = 'FAILED';
    case Rejected = 'REJECTED';
    case Undeliverable = 'UNDELIVERABLE';
    case SoftBounce = 'SOFT_BOUNCE';
    case HardBounce = 'HARD_BOUNCE';
    case PendingApproval = 'PENDING_APPROVAL';
    case Other = 'OTHER';
    case Unknown = 'UNKNOWN';

    /**
     * Resolve a status from the API, tolerating case and novelty.
     *
     * The send endpoints answer lowercase (`"status": "delivered"`) while the
     * list filter uses uppercase (`DELIVERED`) — same value, two casings.
     */
    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }

    /**
     * Whether this status means the message reached the handset.
     *
     * `ACCEPTED` is deliberately excluded — it means the carrier took the
     * message, not that it arrived, and treating it as delivered is the
     * documented cause of over-reported success rates.
     */
    public function isDelivered(): bool
    {
        return $this === self::Delivered || $this === self::Read;
    }

    /**
     * Whether this status is final — no further status event will follow.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Read, self::Failed, self::Rejected,
            self::Undeliverable, self::HardBounce => true,
            default => false,
        };
    }
}
```

`Unknown` is a real case with the string value `'UNKNOWN'`, because the webhook `OTHER`/`UNKNOWN` distinction is upstream's, not ours to erase.

- [ ] **Step 5: Write the fallback value object**

Create `src/Data/V2/SmsFallback.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * The `sms_fallback` object accepted by the WhatsApp and RCS send endpoints.
 *
 * When the rich leg cannot be delivered, an SMS goes instead. `message` is
 * required whenever the object is present; `sender` is optional but should be
 * a sender registered to the account.
 *
 * The fallback is billed as a real SMS and is subject to SMS length limits, so
 * write a separate shorter body rather than reusing a 3072-character RCS one.
 */
final readonly class SmsFallback
{
    /**
     * @throws ValidationException If the message is empty
     */
    public function __construct(
        public string $message,
        public ?string $sender = null,
    ) {
        if ($message === '') {
            throw new ValidationException(
                message: 'An sms_fallback message is required when a fallback is given.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }

    /**
     * @return array{message: string, sender?: string}
     */
    public function toArray(): array
    {
        $body = ['message' => $this->message];

        if ($this->sender !== null) {
            $body['sender'] = $this->sender;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            message: is_string($data['message'] ?? null) ? $data['message'] : '',
            sender: is_string($data['sender'] ?? null) ? $data['sender'] : null,
        );
    }
}
```

Note `fromArray()` can throw on a malformed payload where `message` is absent. That is correct for a request-shaped object, but if a *response* ever carries an empty fallback message this would throw while reading. If a test in Task 4 or 5 hits that, report it rather than loosening the constructor — the right fix would be a separate response path, not a weakened invariant.

- [ ] **Step 6: Write the V2 resource base**

Create `src/Resources/V2Resource.php`. It mirrors the V1 `Resource` base but takes the V2 connector and exposes pagination, which V1's base does not:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\PaginationPlugin\Paginator;

/**
 * Base for the V2 channel resources.
 *
 * Distinct from {@see Resource}, the V1 base, in three ways: it holds the V2
 * connector, it exposes pagination (V2 has two schemes and V1's resources
 * reach the paginator through the connector directly), and its failures come
 * back as RFC 9457 problem details rather than V1 error codes.
 */
abstract class V2Resource
{
    public function __construct(
        protected KudosityV2Connector $connector,
    ) {}

    /**
     * Send a request and return its DTO, throwing a typed exception on failure.
     *
     * `throw()` routes through the connector's `getRequestException()`, which
     * maps the response onto `ValidationException`, `AuthenticationException`,
     * `NotFoundException`, `RateLimitException` or `ServerException`.
     *
     * @throws KudosityException
     */
    protected function sendAndDto(KudosityV2Request $request): mixed
    {
        $response = $this->connector->send($request);

        $response->throw();

        return $response->dto();
    }

    /**
     * Build the paginator the request declares.
     *
     * @throws KudosityException If the request declares no pagination scheme
     */
    protected function paginate(KudosityV2Request $request): Paginator
    {
        return $this->connector->paginate($request);
    }
}
```

- [ ] **Step 7: Verify**

```bash
composer dump-autoload
vendor/bin/pest tests/Unit/V2FoundationsTest.php
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: the focused file passes (21 cases — 14 from the dataset plus 7), full suite 343, `[OK] No errors`, Pint clean.

`V2Resource` has no consumer until Task 2. If PHPStan objects to an abstract class with no subclass, it should not — unlike a trait, an unused abstract class is not an error. If it does complain, report it rather than adding a placeholder subclass.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add the V2 resource base, message-status enum and SMS fallback"
```

---

## Task 2: The SMS channel

The first channel, and the template the other three follow. Its shape is: one request per endpoint, one DTO per response shape, one resource, all tested against `MockClient` fixtures taken from the vendored skill.

**Files:**
- Create: `src/Requests/V2/SendSmsV2Request.php`, `src/Requests/V2/GetSmsV2Request.php`, `src/Requests/V2/ListSmsV2Request.php`
- Create: `src/Data/V2/SmsMessageData.php`, `src/Data/V2/SmsListData.php`
- Create: `src/Resources/SmsV2Resource.php`
- Test: `tests/Unit/V2SmsTest.php`

**Interfaces:**
- Consumes: Task 1's `V2Resource`, `MessageStatus`.
- Produces:
  - `Resources\SmsV2Resource extends V2Resource` with `send(string $message, string $to, string $from, ?string $messageRef = null, bool $trackLinks = false): SmsMessageData`, `get(string $id): SmsMessageData`, `list(?MessageStatus $status = null, ?string $recipient = null, ?string $sender = null, ?string $messageRef = null, ?string $direction = null): V2PagedPaginator`.
  - `Data\V2\SmsMessageData` — see the field table below.
  - `Data\V2\SmsListData` with `public array $messages` (of `SmsMessageData`), `public int $totalRecords`, `public int $totalSegments`.
- Tasks 3–5 copy this shape.

**Read `.agents/skills/kudosity-sms/SKILL.md` first.** It carries the flat-envelope warning and the `sms_count`-is-a-string warning that this task encodes.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/V2SmsTest.php`. The fixture is the exact response body from the skill:

```php
<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\GetSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\ListSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;
use ExpertSystems\Kudosity\Resources\SmsV2Resource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** Verbatim from .agents/skills/kudosity-sms/SKILL.md — note the FLAT envelope. */
function smsSendBody(array $overrides = []): array
{
    return array_merge([
        'id' => '2d2c8fb6-e514-4f5f-9706-0672b0259218',
        'recipient' => '61491570018',
        'recipient_country' => 'AU',
        'sender' => '61491570017',
        'sender_country' => 'AU',
        'message_ref' => 'ncc1701d',
        'message' => 'Report to the ready room!',
        'status' => 'delivered',
        'sms_count' => '1',
        'is_gsm' => true,
        'routed_via' => '',
        'track_links' => true,
        'direction' => 'OUT',
        'created_at' => '2022-03-28T06:12:52.450674000Z',
        'updated_at' => '2022-03-28T06:12:52.450674000Z',
    ], $overrides);
}

function smsResource(array $responses): SmsV2Resource
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return new SmsV2Resource($connector);
}

it('sends a single-recipient SMS and returns a typed DTO', function () {
    $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $sms = (new SmsV2Resource($connector))->send('Report to the ready room!', '61491570018', '61491570017');

    expect($sms)->toBeInstanceOf(SmsMessageData::class)
        ->and($sms->id)->toBe('2d2c8fb6-e514-4f5f-9706-0672b0259218')
        ->and($sms->status)->toBe(MessageStatus::Delivered)
        ->and($sms->recipientCountry)->toBe('AU');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body)->toBe([
        'message' => 'Report to the ready room!',
        'sender' => '61491570017',
        'recipient' => '61491570018',
    ]);
});

it('casts the string sms_count to an int', function () {
    // The API returns "1", not 1. Arithmetic on the raw value silently
    // concatenates — "1" + 1 gives "11".
    $sms = smsResource([SendSmsV2Request::class => MockResponse::make(smsSendBody(['sms_count' => '3']), 200)])
        ->send('Hi', '61491570018', '61491570017');

    expect($sms->smsCount)->toBe(3)->and($sms->smsCount)->toBeInt();
});

it('omits optional fields from the body rather than sending nulls', function () {
    $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SmsV2Resource($connector))->send('Hi', '61491570018', '61491570017');

    expect($mock->getLastPendingRequest()->body()->all())
        ->not->toHaveKey('message_ref')
        ->and($mock->getLastPendingRequest()->body()->all())->not->toHaveKey('track_links');
});

it('sends message_ref and track_links when given', function () {
    $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SmsV2Resource($connector))->send('Hi', '61491570018', '61491570017', messageRef: 'order-1', trackLinks: true);

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['message_ref'])->toBe('order-1')->and($body['track_links'])->toBeTrue();
});

it('rejects a message_ref longer than the documented 500 characters', function () {
    new SendSmsV2Request('Hi', '61491570018', '61491570017', messageRef: str_repeat('a', 501));
})->throws(ValidationException::class, '500');

it('reads one SMS by id', function () {
    $mock = new MockClient([GetSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $sms = (new SmsV2Resource($connector))->get('2d2c8fb6-e514-4f5f-9706-0672b0259218');

    expect($sms->id)->toBe('2d2c8fb6-e514-4f5f-9706-0672b0259218')
        ->and((string) $mock->getLastPendingRequest()->getUri())
        ->toBe('https://api.transmitmessage.com/v2/sms/2d2c8fb6-e514-4f5f-9706-0672b0259218');
});

it('sends no body on the GET reader', function () {
    // Phase 2 split the request bases precisely so readers do not ship a JSON
    // body; a V2 GET carrying one is stripped or rejected by some gateways.
    $mock = new MockClient([GetSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SmsV2Resource($connector))->get('abc');

    $pending = $mock->getLastPendingRequest();

    expect($pending->headers()->get('Content-Type'))->toBeNull()
        ->and((string) $pending->body())->toBe('');
});

it('turns a 404 into a NotFoundException', function () {
    smsResource([GetSmsV2Request::class => MockResponse::make(['error' => 'SMS not found'], 404)])->get('nope');
})->throws(NotFoundException::class, 'SMS not found');

it('pages the list endpoint and casts its string totals', function () {
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient([
        ListSmsV2Request::class => MockResponse::make([
            'smses' => [smsSendBody(), smsSendBody(['id' => 'second'])],
            'total_records' => '2',
            'total_segments' => '2',
        ], 200),
    ]));

    $items = iterator_to_array((new SmsV2Resource($connector))->list()->items());

    expect($items)->toHaveCount(2)->and($items[0]['id'])->toBe('2d2c8fb6-e514-4f5f-9706-0672b0259218');
});

it('passes list filters through as query parameters', function () {
    $mock = new MockClient([
        ListSmsV2Request::class => MockResponse::make(['smses' => [smsSendBody()], 'total_records' => '1'], 200),
    ]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array((new SmsV2Resource($connector))->list(status: MessageStatus::Delivered, recipient: '61491570018')->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('status'))->toBe('DELIVERED')
        ->and($query->get('recipient'))->toBe('61491570018');
});

it('declares itself paged so the connector picks the right paginator', function () {
    expect(new ListSmsV2Request)->toBeInstanceOf(ExpertSystems\Kudosity\Contracts\PaginatesV2Pages::class)
        ->and((new ListSmsV2Request)->paginationItemsKey())->toBe('smses');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/V2SmsTest.php`
Expected: FAIL — the request, DTO and resource classes do not exist.

- [ ] **Step 3: Write the DTO**

Create `src/Data/V2/SmsMessageData.php` with these fields, in this order, with these casts. Every field is from `GET /v2/sms`'s documented schema; the send response omits `is_sandbox`, so it is nullable:

| Property | Type | From | Cast |
|---|---|---|---|
| `id` | `string` | `id` | — |
| `recipient` | `string` | `recipient` | — |
| `recipientCountry` | `?string` | `recipient_country` | — |
| `sender` | `string` | `sender` | — |
| `senderCountry` | `?string` | `sender_country` | — |
| `messageRef` | `?string` | `message_ref` | — |
| `message` | `string` | `message` | — |
| `status` | `MessageStatus` | `status` | `MessageStatus::fromApi()` |
| `smsCount` | `int` | `sms_count` | **`(int)` — arrives as a string** |
| `isGsm` | `bool` | `is_gsm` | `(bool)` |
| `routedVia` | `?string` | `routed_via` | empty string → `null` |
| `trackLinks` | `bool` | `track_links` | `(bool)` |
| `direction` | `?string` | `direction` | — |
| `createdAt` | `?DateTimeImmutable` | `created_at` | RFC3339 parse, null on failure |
| `updatedAt` | `?DateTimeImmutable` | `updated_at` | RFC3339 parse, null on failure |

`final readonly class`, constructor-promoted, plus `public static function fromArray(array $data): self`. `routed_via` maps an empty string to null because the API sends `""` when no shared number was used, and "absent" is the honest reading.

Timestamps arrive as `2022-03-28T06:12:52.450674000Z` — **nine fractional digits**, which `DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, ...)` will not parse. Use a permissive parse (`new DateTimeImmutable($value)` inside a try/catch returning null) and add a test asserting that exact string parses. If it does not, report it — do not silently drop the field.

Also create `src/Data/V2/SmsListData.php`: `public array $messages` (typed `array<int, SmsMessageData>` in PHPDoc), `public int $totalRecords`, `public int $totalSegments`, both cast from strings, with `fromArray()`.

- [ ] **Step 4: Write the three requests**

`src/Requests/V2/SendSmsV2Request.php` — extends `KudosityV2BodyRequest` (it sends a body):

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use Saloon\Http\Response;

/**
 * Send a single-recipient SMS.
 *
 * `POST /v2/sms` takes exactly one recipient and has no scheduling. For
 * multiple recipients, a contact list, or a future send time, use the V1
 * bulk surface — `$client->bulk()`.
 *
 * @see https://developers.kudosity.com/reference/post_v2-sms
 */
class SendSmsV2Request extends KudosityV2BodyRequest
{
    /**
     * The documented maximum for the caller's own reference field.
     */
    public const MAX_MESSAGE_REF_LENGTH = 500;

    /**
     * @throws ValidationException If message_ref exceeds its documented maximum
     */
    public function __construct(
        protected string $message,
        protected string $recipient,
        protected string $sender,
        protected ?string $messageRef = null,
        protected bool $trackLinks = false,
    ) {
        if ($messageRef !== null && mb_strlen($messageRef) > self::MAX_MESSAGE_REF_LENGTH) {
            throw new ValidationException(
                message: sprintf(
                    'message_ref length (%d) exceeds the maximum of %d characters',
                    mb_strlen($messageRef),
                    self::MAX_MESSAGE_REF_LENGTH,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/sms';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'message' => $this->message,
            'sender' => $this->sender,
            'recipient' => $this->recipient,
        ];

        if ($this->messageRef !== null) {
            $body['message_ref'] = $this->messageRef;
        }

        if ($this->trackLinks) {
            $body['track_links'] = true;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): SmsMessageData
    {
        // payload() comes from UnwrapsData on the base. SMS is flat, but going
        // through it keeps every V2 request identical regardless of envelope.
        return SmsMessageData::fromArray(static::payload($response));
    }
}
```

`src/Requests/V2/GetSmsV2Request.php` — extends `KudosityV2Request` (**no body**), `protected Method $method = Method::GET;`, `__construct(protected string $id)`, endpoint `'/v2/sms/'.$this->id`, same `createDtoFromResponse()`.

`src/Requests/V2/ListSmsV2Request.php` — extends `KudosityV2Request`, `Method::GET`, `implements PaginatesV2Pages`, `paginationItemsKey(): string` returns `'smses'`, endpoint `'/v2/sms'`. Constructor takes the nullable filters `status: ?MessageStatus`, `recipient`, `sender`, `messageRef`, `direction`, `startDate`, `endDate` and emits them via `defaultQuery()` — omitting any that are null, and sending `$status->value` for the enum. Its `createDtoFromResponse()` returns `SmsListData`.

Note `defaultQuery()` must not collide with the paginator's `page`/`limit`, which the paginator adds itself.

- [ ] **Step 5: Write the resource**

Create `src/Resources/SmsV2Resource.php` extending `V2Resource`, with the three methods from the Interfaces block. `send()` and `get()` go through `sendAndDto()`; `list()` returns `$this->paginate(new ListSmsV2Request(...))`.

Its class docblock must state the V1/V2 division plainly — one recipient, no scheduling, `bulk()` for the rest — because that is the single most likely misunderstanding for a consumer arriving from 1.x.

- [ ] **Step 6: Run the test, then verify the suite**

```bash
composer dump-autoload
vendor/bin/pest tests/Unit/V2SmsTest.php
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: the focused file passes (12 tests), full suite 355, `[OK] No errors`, Pint clean.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add the V2 SMS channel — send, get and paged list"
```

---

## Task 3: The MMS channel

Same shape as Task 2, flat envelope, no list endpoint. The differences that matter are the media constraints.

**Files:**
- Create: `src/Requests/V2/SendMmsRequest.php`, `src/Requests/V2/GetMmsRequest.php`
- Create: `src/Data/V2/MmsMessageData.php`
- Create: `src/Resources/MmsResource.php`
- Test: `tests/Unit/V2MmsTest.php`

**Interfaces:**
- Produces: `Resources\MmsResource extends V2Resource` with `send(string $to, string $from, array $contentUrls, ?string $subject = null, ?string $message = null, ?string $messageRef = null, bool $trackLinks = false): MmsMessageData` and `get(string $id): MmsMessageData`.

**Read `.agents/skills/kudosity-mms/SKILL.md` first.**

`Data\V2\MmsMessageData` fields: `id`, `recipient`, `sender`, `country` (`?string` — note MMS returns a single `country`, not the `recipient_country`/`sender_country` pair SMS returns), `subject` (`?string`), `message` (`?string`), `messageRef` (`?string`), `contentUrls` (`array<int, string>`), `status` (`MessageStatus::fromApi()`), `trackLinks` (`bool`), `createdAt`/`updatedAt` (`?DateTimeImmutable`).

The constraints to encode in `SendMmsRequest`, each with a test:

- `content_urls` is **required and must be non-empty**; throw `ValidationException` on an empty array.
- **Only one media file per message.** More than one URL throws `ValidationException` naming the limit.
- Every URL must be **absolute** — reuse `Support\Url::validate()`, which already exists and is tested.
- `subject` is **max 20 characters, ASCII only**. Throw on either violation; the ASCII check is the one a caller will not expect.
- `message` is max 1,000 characters.
- `message_ref` max 500, as SMS.

Tests must also cover: the flat envelope resolving through `payload()`; `status: 'pending'` on the immediate response mapping to `MessageStatus::Pending` **without** being treated as a failure (the skill is explicit that submission-pending is not an error); the GET reader sending no body; and a 404 becoming `NotFoundException`.

Add one test asserting the resource's docblock claim that MMS is Australia-only is not enforced client-side — i.e. a non-AU recipient is *not* rejected locally, because that is the API's judgement to make. State that reasoning in the test name so nobody later "fixes" it into a validation rule.

- [ ] **Step 1: Write the failing test** — `tests/Unit/V2MmsTest.php`, following Task 2's structure, with the fixture taken verbatim from the MMS skill's response example.
- [ ] **Step 2: Run it and confirm it fails** for missing classes.
- [ ] **Step 3: Write `MmsMessageData`** per the field list above.
- [ ] **Step 4: Write `SendMmsRequest`** (extends `KudosityV2BodyRequest`) with every constraint above, and `GetMmsRequest` (extends `KudosityV2Request`, `Method::GET`).
- [ ] **Step 5: Write `MmsResource`** extending `V2Resource`.
- [ ] **Step 6: Verify** — focused file, full suite, PHPStan, Pint.
- [ ] **Step 7: Commit** — `git commit -m "feat: add the V2 MMS channel with media constraints"`

---

## Task 4: The WhatsApp channel

The riskiest channel, because its `content` field is a nested envelope that the documentation names as the most common integration mistake. This task encodes that in types.

**Files:**
- Create: `src/Contracts/WhatsAppContent.php`
- Create: `src/Data/V2/Content/TextContent.php`, `TemplateContent.php`, `CustomContent.php`
- Create: `src/Requests/V2/SendWhatsAppRequest.php`, `GetWhatsAppRequest.php`, `ListWhatsAppRequest.php`
- Create: `src/Data/V2/WhatsAppMessageData.php`
- Create: `src/Resources/WhatsAppResource.php`
- Test: `tests/Unit/V2WhatsAppTest.php`

**Interfaces:**
- Produces:
  - `interface Contracts\WhatsAppContent` with `contentType(): string` and `toArray(): array`.
  - `TextContent(string $message)`, `TemplateContent(string $name, array $parameters = [], ?string $locale = null)`, `CustomContent(array $payload)` — each `final readonly`, each implementing the contract.
  - `Resources\WhatsAppResource extends V2Resource` with `text(string $message, string $to, ?string $from = null, ?SmsFallback $fallback = null, ?string $messageRef = null): WhatsAppMessageData`, `template(string $name, array $parameters, string $to, ?string $locale = null, ?string $from = null, ?SmsFallback $fallback = null, ?string $messageRef = null): WhatsAppMessageData`, `custom(array $payload, string $to, ...): WhatsAppMessageData`, `send(WhatsAppContent $content, string $to, ...): WhatsAppMessageData`, `get(string $id): WhatsAppMessageData`, `list(...): V2CursorPaginator`.

**Read `.agents/skills/kudosity-whatsapp/SKILL.md` and `kudosity-whatsapp-templates/SKILL.md` first.** Both are load-bearing here.

### The nesting, which is the whole point of the value objects

The body is `{"content_type": "template", "content": {"template": {"name": ..., "parameters": [...]}}}` — **`content.template.name`, not `content.name`**. Likewise `content.text.message`. The skill calls flattening this the most common mistake. So:

- `TextContent::contentType()` returns `'text'`; `toArray()` returns `['text' => ['message' => $this->message]]`.
- `TemplateContent::contentType()` returns `'template'`; `toArray()` returns `['template' => array_filter(['name' => ..., 'parameters' => ..., 'locale' => ...])]` — omitting `parameters` when empty and `locale` when null.
- `CustomContent::contentType()` returns `'custom'`; `toArray()` returns `['custom' => $this->payload]`.

`SendWhatsAppRequest` takes a `WhatsAppContent` and derives both `content_type` and `content` from it, so the two **cannot disagree**. A test must assert exactly that: given a `TemplateContent`, the body's `content_type` is `template` and `content` has the nested `template` key. Add a test asserting the body has **no** top-level `name` key — the flattened shape — so a future refactor that flattens fails.

`TemplateContent` must also enforce the documented name rule: lowercase alphanumeric and underscores only. Throw `ValidationException` naming the rule. Template parameters are **positional strings**; assert that a non-string parameter is rejected or stringified deliberately, and say which you chose.

### Other constraints

- `recipient` must be E.164 without spaces, dashes or a leading `+`. `0411 122 211` → `61411122211`. Reuse `Support\PhoneNumber` for normalisation, and test that a spaced local number is normalised rather than sent raw.
- `sender` is optional — **omit it and the account default is used.** Do not substitute a placeholder.
- `sms_fallback` uses Task 1's `SmsFallback`, serialised via `toArray()`.
- Response is **wrapped in `data`** — `payload()` handles it, and a test must prove the DTO reads `data.id` and not a top-level `id`.
- `ListWhatsAppRequest` implements `PaginatesV2Cursor` with `paginationItemsKey()` returning `'data.messages'` and `paginationDirection()` returning `'next'`. Its filters are `date_range` (`last_week`|`last_thirty`|`last_month`|`all`|`custom_date`), `start_date`, `end_date`, `campaign_id`. **`start_date` and `end_date` are required when `date_range` is `custom_date`** — validate that pairing client-side and test it, because the API's error for it is a generic 400.

`Data\V2\WhatsAppMessageData` fields: `id`, `messageRef` (`?string`), `campaignId` (`?string`), `sender` (`?string`), `recipient`, `contentType` (`string`), `content` (`array<string, mixed>` — kept raw; the response echoes whichever variant was sent and re-parsing it into a value object is not this phase's job), `status` (`MessageStatus::fromApi()`, nullable since the send response omits it), `smsFallback` (`?SmsFallback`), `createdAt` (`?DateTimeImmutable`).

- [ ] **Step 1: Write the failing test** — `tests/Unit/V2WhatsAppTest.php`, fixtures from the skill (the wrapped `{"data": {...}}` send response and the `{"data":{"messages":[...]},"meta":{"pagination":{...}}}` list response).
- [ ] **Step 2: Run it and confirm it fails.**
- [ ] **Step 3: Write the contract and the three content value objects.**
- [ ] **Step 4: Write `WhatsAppMessageData`.**
- [ ] **Step 5: Write the three requests**, deriving `content_type` from the content object.
- [ ] **Step 6: Write `WhatsAppResource`** with the four send helpers plus `send()`, `get()`, `list()`.
- [ ] **Step 7: Verify** — focused file, full suite, PHPStan, Pint.
- [ ] **Step 8: Commit** — `git commit -m "feat: add the V2 WhatsApp channel with typed content envelopes"`

---

## Task 5: The RCS channel

**Files:**
- Create: `src/Requests/V2/SendRcsRequest.php`, `GetRcsRequest.php`, `ListRcsRequest.php`, `CheckRcsCapabilitiesRequest.php`
- Create: `src/Data/V2/RcsMessageData.php`, `src/Data/V2/RcsCapabilityData.php`, `src/Enums/RcsCapabilityCode.php`
- Create: `src/Resources/RcsResource.php`
- Test: `tests/Unit/V2RcsTest.php`

**Interfaces:**
- Produces: `Resources\RcsResource extends V2Resource` with `send(string $message, string $to, string $agentId, ?SmsFallback $fallback = null, ?string $messageRef = null): RcsMessageData`, `get(string $id): RcsMessageData`, `list(...): V2CursorPaginator`, `capabilities(array $phoneNumbers, string $agentId): array` returning `array<int, RcsCapabilityData>`.

**Read `.agents/skills/kudosity-rcs/SKILL.md` first.**

### The trap this task encodes

**RCS does not send from a phone number.** `sender` is a registered RCS **agent ID** — alphanumeric or numeric, e.g. `DemoSender`. Passing a phone number fails validation upstream. `SendRcsRequest` must reject a sender that looks like a phone number *before the request leaves the process*, with a message that says agent ID rather than repeating the API's generic validation error. Test it with a plausible E.164 value.

Use `Support\PhoneNumber::isValid()` to make the determination rather than inventing a second notion of what a phone number looks like — and note in the code why an agent ID that happens to be all digits is still allowed if it is not a valid phone number, since the docs permit numeric agent IDs.

### Other specifics

- `content_type` is currently only `text`; `content` is `{"text": {"message": ...}}`. Model it the same nested way as WhatsApp but without the value-object hierarchy, since there is exactly one variant — and say so in a comment, so Phase 4+ knows where to generalise if RCS gains variants.
- Message length: up to **3072** characters, full UTF-8. Validate and test the boundary.
- `sms_fallback` should be set on almost every send — the skill is emphatic. The resource's docblock must say so; do not make it required, because the API does not.
- Response is **wrapped in `data`**.
- `ListRcsRequest` mirrors `ListWhatsAppRequest`: `PaginatesV2Cursor`, items at `'data.messages'`, the same `date_range`/`custom_date` pairing rule.

### Capabilities

`POST /v2/rcs/capabilities` takes `sender` (the agent ID — capability is **per agent**, so a check without it is meaningless) and `phone_numbers`. Up to 100 per request; the docs recommend batches of 1–10 for routing-time latency. Validate the 100 ceiling; document the 1–10 recommendation in the method docblock rather than enforcing it.

Response: `{"data": {"results": [{"phone_number": "...", "code": "ENABLED"}]}}`, **one per number in request order**.

`Enums\RcsCapabilityCode: string` cases: `Enabled`, `Unreachable`, `RejectedNetwork`, `RejectedRouteNotAvailable`, `RequestFailed`, `ProcessingError`, `InvalidDestinationAddress`, `Unknown` — with a `fromApi()` resolving unrecognised values to `Unknown`, same pattern as `MessageStatus`.

Add `RcsCapabilityCode::isReachable(): bool` returning true for `Enabled` **and `Unknown`**. That is deliberate and must be commented: the skill says to treat `UNKNOWN` as reachable, send anyway, and let `sms_fallback` carry what does not land — the check is best-effort and must not be a hard gate. A test must pin `Unknown` being reachable, because an implementer's instinct is the opposite.

- [ ] **Step 1: Write the failing test** — `tests/Unit/V2RcsTest.php`, fixtures from the skill including the two-result capabilities response.
- [ ] **Step 2: Run it and confirm it fails.**
- [ ] **Step 3: Write `RcsCapabilityCode`** with `fromApi()` and `isReachable()`.
- [ ] **Step 4: Write `RcsMessageData` and `RcsCapabilityData`.**
- [ ] **Step 5: Write the four requests**, including the agent-ID rejection.
- [ ] **Step 6: Write `RcsResource`.**
- [ ] **Step 7: Verify** — focused file, full suite, PHPStan, Pint.
- [ ] **Step 8: Commit** — `git commit -m "feat: add the V2 RCS channel with capability checks"`

---

## Task 6: Wire the channels onto the client, and the consumer contract

**Files:**
- Modify: `src/KudosityClient.php`, `packages/kudosity-laravel/src/Facades/Kudosity.php`
- Modify: `rename-map.json`, `UPGRADING.md`, `CHANGELOG.md`
- Modify: `packages/kudosity-client/README.md`, `README.md`, `CLAUDE.md`
- Test: `tests/Unit/KudosityClientTest.php` (extend)

**Interfaces:**
- Produces: `KudosityClient::sms(): SmsV2Resource`, `mms(): MmsResource`, `whatsapp(): WhatsAppResource`, `rcs(): RcsResource` — each lazily constructed against `$this->v2Connector` and cached, matching the existing accessor pattern.

- [ ] **Step 1: Write the failing test**

Extend `tests/Unit/KudosityClientTest.php` with: each of the four accessors returns its resource type; each caches (`toBe` on two calls); and each is constructed with the **V2** connector, not V1 — assert that by sending through the resource with a mock on `$client->v2()` and confirming the request went to `api.transmitmessage.com`.

- [ ] **Step 2: Run it and confirm it fails.**

- [ ] **Step 3: Add the four accessors** to `KudosityClient`, with properties and docblocks matching the existing style. `sms()`'s docblock must state that it is the V2 single-recipient endpoint and point at `bulk()` for multi-recipient, list and scheduled sends.

- [ ] **Step 4: Add the four `@method` lines** to the facade, using short imported class names to match the file's existing style.

- [ ] **Step 5: Run the mandatory removed-symbol audit**

This is the Global Constraint both prior phases' final reviews existed to catch. Produce the audit explicitly, in your report:

```bash
# Every public symbol this phase adds, renames or removes:
git diff --stat main..HEAD -- packages/
git diff main..HEAD -- packages/ | grep -E '^\-.*(public function|public const|class |interface |enum )' | sort -u
```

For every symbol on the removed side, confirm it appears in `rename-map.json` and `UPGRADING.md`. This phase should remove nothing — it is purely additive — so the expected result is an empty removed list. **If it is not empty, that is the finding, and it must be recorded in both files before you commit.**

- [ ] **Step 6: Revisit `rename-map.json`'s `"sms": null` entry — this needs a decision, not a mechanical edit**

Phase 2 added `"sms": null` to `removed` because `KudosityClient::sms()` had been deleted. **This phase re-adds `sms()` with entirely different semantics**: V1's took multiple comma-separated recipients and could schedule; V2's takes exactly one recipient and cannot.

So a 1.x consumer whose code reads `$client->sms()->send($msg, '61400000000,61400000001')` will now **silently hit the V2 endpoint with a comma-joined string as a single recipient** rather than failing. That is worse than the fatal it produced after Phase 2 — a silent wrong-behaviour beats a loud break every time, and this is the loud break turning silent.

Therefore: **keep the `"sms": null` flag**, and strengthen its `UPGRADING.md` entry to say explicitly that `sms()` exists again but means something different, with the multi-recipient case called out as the dangerous one. Add a row to the resource-surface table for the new `sms()` alongside the existing `bulk()` rows.

If you disagree with keeping the flag, say so with reasoning rather than removing it — this is a judgement call and I would rather discuss it than have it quietly reversed.

- [ ] **Step 7: Update the documentation**

- `CHANGELOG.md` under the existing `## Unreleased` `### Added`: the four channels, the enums, the content value objects, `SmsFallback`, and the new `sms()` semantics.
- `UPGRADING.md`: the strengthened `sms()` entry from Step 6, plus a short section on the four channels with one runnable example each.
- `packages/kudosity-client/README.md`: a V2 channels section with the **per-endpoint envelope table** (SMS/MMS flat; WhatsApp/RCS wrapped) and the note that `sms_count`/`total_records` arrive as strings. This is the table the design spec asks for and the first place a reader will look when their code reads null.
- `README.md`: update the resource list.
- `CLAUDE.md`: the architecture section now needs the V2 resources; and the line saying no V2 endpoint classes exist is no longer true.

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

Expected: green suite, `[OK] No errors`, Pint clean, three valid manifests, `packages` → 0 codemod changes, sweep clean.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: expose the four V2 channels on KudosityClient"
```

---

## Definition of done

- `$k->sms()`, `$k->mms()`, `$k->whatsapp()` and `$k->rcs()` each send and read against `api.transmitmessage.com`, with typed DTOs.
- The documented traps are types, not comments: WhatsApp's `content` cannot be flattened, RCS rejects a phone-number sender locally, `sms_count`/`total_records` are ints in the DTOs, and every status resolves through an enum that tolerates a value the docs have not published yet.
- Both envelope shapes are handled by the same `payload()` seam, and a test proves the wrapped case reads `data.id` rather than a top-level `id`.
- Both list endpoints paginate — SMS by page, WhatsApp and RCS by cursor — through Phase 2's paginators, selected by marker contract.
- The removed-symbol audit ran and is recorded in the Task 6 report. `rename-map.json`'s `"sms": null` flag is retained with a decision recorded.
- Suite green and above 322; PHPStan level 6 clean; Pint clean; three manifests valid; branding sweep clean; codemod reports 0 changes for `packages`.
- No webhook, sender, or Laravel-channel code exists — those are Phases 4 and 5, and adding them here is scope creep.
