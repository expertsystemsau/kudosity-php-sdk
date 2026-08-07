# Kudosity Migration Phase 6: Tests, CI, Docs and Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the client package real coverage of its own, running on the PHP 8.2 floor it declares, then ship 2.0.

**Architecture:** No production code changes are planned. Phase 6 moves coverage rather than adding behaviour: the framework-agnostic surface gets a PHPUnit 11 suite inside `packages/kudosity-client`, executed standalone on 8.2/8.3/8.4, while the root Pest suite keeps the Laravel integration. The harness for this already exists (commit `ffd2188`) — what remains is the coverage, the de-duplication decision, and the release.

**Tech Stack:** PHP 8.2/8.3/8.4, PHPUnit 11 (client package), Pest 4 + Orchestra Testbench (root), Saloon v4, PHPStan level 6, Laravel Pint, Docker for local 8.2 runs.

## Global Constraints

- Runtime floor stays `"php": "^8.2"` in both packages. **This phase exists to make that true rather than aspirational** — if any task is tempted to raise it, stop and ask.
- **PHPUnit 11, never 12.** PHPUnit 12 requires PHP >= 8.3, which would silently delete 8.2 coverage while every job stayed green.
- `composer analyse` must report `[OK] No errors` at the end of every task. PHPStan now analyses `phpVersion: {min: 80200, max: 80499}`, so it fails on 8.3-only syntax — do not narrow that range to make an error go away.
- `vendor/bin/pint --test` must pass. Run `composer format` to fix. **Pint's `fully_qualified_strict_types` fixer rewrites an inline `\Some\Class::class` into a `use` import.** Where a test deliberately names a class that must not exist, write it as a **string literal**, or Pint plants the very import the test denies.
- Both suites must pass at the end of every task:
  - Root: `vendor/bin/pest --compact` — baseline `844 passed (1660 assertions)` at `ffd2188`.
  - Client: `cd packages/kudosity-client && vendor/bin/phpunit` — baseline `22 tests, 43 assertions` at `ffd2188`.
  - **Re-measure both at Step 1. Do not trust these numbers if commits have landed.**
- **Do not predict exact test counts.** Say "at least N". Phase 3's plan double-counted a dataset's own `it()` block and every later prediction inherited the error.
- The two hostnames are `https://api.transmitsms.com` (V1) and `https://api.transmitmessage.com` (V2). Never write a sweep pattern like `\btransmitsms\b` — the dots are word boundaries. Any sweep needs a negative check for the corrupted `api.kudosity.com` form, with the same exclusions as the positive sweep (`docs/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, `bin/kudosity-codemod`, `tests/Unit/CodemodTest.php`, `HANDOFF.md`, `.ai-skills/*`).
- Never modify `.agents/skills/`. Append to `CHANGELOG.md` under `## Unreleased` until Task 7 renames it.
- `/docs` is gitignored; committing anything under it needs `git add -f`.
- `.github/workflows/split.yml` **must keep `actions/checkout@v4`**. See `HANDOFF.md` Warnings.
- Work on a branch `feat/kudosity-phase-6` cut from `main`, in the **primary checkout** — symlinking `vendor/` into a worktree makes `vendor/bin/pest` run the tracked tree's code, so a deliberate regression appears to pass.

## Reference

- Design spec: `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`, **section 6**.
- `HANDOFF.md` — current state, the full Failed Approaches list, and the release checklist.
- `tests/Fixtures/V2Webhooks/README.md` — **read before writing any webhook test.** Real captured deliveries; several behaviours contradict the upstream docs.
- `tests/Fixtures/V2Senders/README.md` — what is and is not verified about the sender item shape.

### What already exists (commit `ffd2188`) — do not rebuild it

| Piece | State |
|---|---|
| `packages/kudosity-client/phpunit.xml.dist` | Written. Strict flags on (`failOnWarning`, `failOnRisky`, `failOnNotice`, `failOnDeprecation`), random order, `bootstrap="vendor/autoload.php"`. |
| `packages/kudosity-client/tests/StandaloneInstallTest.php` | Asserts the `^8.2` constraint, that Laravel is absent from the standalone tree, and that no framework is in `require`. |
| `packages/kudosity-client/tests/V2TransportTest.php` | V2 auth headers, the no-secret-parameter reflection guard, both hostnames, all three error-body shapes, the status→exception map, flat-envelope parsing, the send body shape. |
| `.github/workflows/run-tests.yml` job `test-client` | 8.2/8.3/8.4, installs the package alone, greps its tree for a framework, runs PHPUnit. **Green on all three.** |
| `phpstan.neon.dist` `phpVersion` | `min: 80200, max: 80499`. |
| `.gitignore` | `packages/*/vendor`, `packages/*/phpunit.xml`. |

### The one rule that decides where a test lives

**Ownership of the class under test, not the symbols the test file happens to import.**

A survey at `ffd2188` found 27 of 34 root test files use no Laravel symbol — but that number is misleading and acting on it would break the build. `tests/Unit/KudosityMessageTest.php` and `KudosityMessageRoutingTest.php` import nothing from Laravel, yet they test `ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage`, which lives in the **Laravel** package. The client package's standalone autoloader cannot see that class, so moving those tests turns the 8.2 job red with a class-not-found error that looks like a dependency problem and is not.

Apply this test: **is the class under test under `packages/kudosity-client/src`?** If yes it belongs in the client suite. If no it stays at root. `tests/Unit/CodemodTest.php` covers `bin/kudosity-codemod`, which is in neither package, and stays at root permanently — `split.yml` publishes only `packages/*`, so the codemod never reaches a consumer anyway.

### The defect class this project keeps producing

Tests that cannot fail. It has appeared in every phase. **The discipline: revert the behaviour, watch the test fail, restore, and put the transcript in the task's report.** Two corollaries — give each validation rule a **single-violation** input, and assert on something **only** that rule produces.

This phase is unusually exposed to it, because a test being *ported* is assumed to work. A Pest `expect($x)->toBe($y)` mistranslated into PHPUnit as `assertTrue(...)` against a truthy value passes forever. **Port by rewriting the assertion from the behaviour, not by mechanically transliterating the Pest call.**

### Running 8.2 locally

CI covers it, but do not wait for CI:

```bash
cd packages/kudosity-client
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit --no-coverage
```

For a clean-install check (proves dependency constraints resolve on 8.2, not just that the code runs):

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli sh -c '
  apt-get update -qq >/dev/null 2>&1 && apt-get install -y -qq git unzip >/dev/null 2>&1
  php -r "copy(\"https://getcomposer.org/installer\",\"/tmp/ci.php\");" && php /tmp/ci.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -rf /tmp/fresh && mkdir -p /tmp/fresh && cp composer.json phpunit.xml.dist /tmp/fresh/ && cp -r src tests /tmp/fresh/
  cd /tmp/fresh && composer install --no-interaction --no-progress && vendor/bin/phpunit --no-coverage'
```

## File Structure

```
packages/kudosity-client/
  phpunit.xml.dist                  exists
  tests/
    Fixtures/
      Fixtures.php                  Task 1 — the loader, plus the payload builders
      V2Webhooks/*.json             Task 1 — moved here from tests/Fixtures/V2Webhooks
      V2Senders/*.json              Task 1 — moved, if any exist
    StandaloneInstallTest.php       exists
    V2TransportTest.php             exists
    WebhookPayloadTest.php          Task 2 — all ten event types, InboundMedia
    WebhookGuardsTest.php           Task 2 — StatusPrecedence, SignedMessageRef
    RequestShapeTest.php            Task 3 — all 22 V2 requests: path, method, body
    DtoTest.php                     Task 4 — both envelopes, casts, absent optionals
    PaginatorTest.php               Task 5 — all three paginators
    EnumToleranceTest.php           Task 5 — every tolerant enum
    ValueObjectTest.php             Task 6 — WhatsApp content, guards, PhoneNumber
tests/                              root, Laravel only after Task 7
```

Fixtures move rather than being copied. **A copy drifts**, and these are captured artefacts whose whole value is that they are exactly what the API sent. The root suite reaches the moved files through a one-line re-export so its existing tests keep working unedited — which is also how Task 7 proves the move changed nothing.

---

## Task 1: Move the fixtures so both suites read one copy

**Files:**
- Create: `packages/kudosity-client/tests/Fixtures/Fixtures.php`
- Move: `tests/Fixtures/V2Webhooks/` → `packages/kudosity-client/tests/Fixtures/V2Webhooks/` (use `git mv`, including `README.md`)
- Move: `tests/Fixtures/V2Senders/` → `packages/kudosity-client/tests/Fixtures/V2Senders/` (same, if present)
- Modify: `tests/Fixtures/WebhookPayloads.php` — re-point at the new location
- Modify: `packages/kudosity-laravel/composer.json` — nothing; the root suite reaches the files by path, not autoload

**Interfaces:**
- Consumes: nothing.
- Produces: `ExpertSystems\Kudosity\Tests\Fixtures\Fixtures::webhook(string $name): array` and `Fixtures::path(string $relative): string`. Tasks 2–6 use these. The root helper `webhookFixture(string $name): array` keeps its signature and its callers.

**Why the client package owns them:** they are captured responses from the framework-agnostic API. The Laravel package has no claim on them, and `split.yml` publishes `packages/*` — so a consumer of `kudosity-php-client` currently gets a package whose fixtures live in a repo they never see.

- [ ] **Step 1: Write the failing test**

`packages/kudosity-client/tests/Fixtures/FixturesTest.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests\Fixtures;

use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    public function test_it_loads_a_captured_webhook_delivery(): void
    {
        $payload = Fixtures::webhook('sms-status-delivered');

        $this->assertSame('SMS_STATUS', $payload['event_type']);
        $this->assertSame('DELIVERED', $payload['status']['status']);
    }

    public function test_it_names_the_missing_fixture_rather_than_returning_null(): void
    {
        // A typo'd fixture name that silently yields [] makes every assertion
        // against it pass vacuously — the exact defect class this repo keeps
        // producing.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no-such-fixture/');

        Fixtures::webhook('no-such-fixture');
    }

    public function test_every_captured_delivery_is_valid_json_with_an_event_type(): void
    {
        $files = glob(Fixtures::path('V2Webhooks/*.json'));

        $this->assertNotEmpty($files, 'The fixtures did not move, or the path is wrong.');

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('event_type', $decoded, basename($file));
        }
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter FixturesTest`
Expected: FAIL — `Class "ExpertSystems\Kudosity\Tests\Fixtures\Fixtures" not found`.

- [ ] **Step 3: Move the fixture files**

```bash
git mv tests/Fixtures/V2Webhooks packages/kudosity-client/tests/Fixtures/V2Webhooks
git mv tests/Fixtures/V2Senders packages/kudosity-client/tests/Fixtures/V2Senders 2>/dev/null || true
```

- [ ] **Step 4: Write the loader**

`packages/kudosity-client/tests/Fixtures/Fixtures.php`:

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests\Fixtures;

use InvalidArgumentException;

/**
 * Captured API artefacts, owned by the package whose API produced them.
 *
 * Read `V2Webhooks/README.md` before using any of these. They are real
 * deliveries, and several of them contradict the upstream documentation.
 */
final class Fixtures
{
    public static function path(string $relative): string
    {
        return __DIR__.'/'.$relative;
    }

    /** @return array<string, mixed> */
    public static function webhook(string $name): array
    {
        $file = self::path('V2Webhooks/'.$name.'.json');

        if (! is_file($file)) {
            // Named rather than empty: a fixture that silently resolves to []
            // makes every assertion against it pass while testing nothing.
            throw new InvalidArgumentException("No such webhook fixture: {$name} (looked in {$file})");
        }

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
}
```

Add to `packages/kudosity-client/composer.json` so the suite autoloads it:

```json
"autoload-dev": {
    "psr-4": {
        "ExpertSystems\\Kudosity\\Tests\\": "tests/"
    }
}
```

(Already present at `ffd2188` — verify rather than duplicate.)

- [ ] **Step 5: Re-point the root helper without changing its signature**

`tests/Fixtures/WebhookPayloads.php`:

```php
function webhookFixture(string $name): array
{
    // One copy, owned by the client package. The root suite reads through to
    // it so a captured payload cannot drift between the two suites.
    $path = __DIR__.'/../../packages/kudosity-client/tests/Fixtures/V2Webhooks/'.$name.'.json';

    if (! is_file($path)) {
        throw new InvalidArgumentException("No such webhook fixture: {$name} (looked in {$path})");
    }

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
```

- [ ] **Step 6: Run both suites — the root one must pass completely unedited**

Run: `cd packages/kudosity-client && vendor/bin/phpunit`
Expected: PASS, at least 25 tests.

Run: `vendor/bin/pest --compact`
Expected: PASS at the Step 1 baseline. **Not one root test file may be edited in this task.** If any needed editing, the re-export is wrong — fix the re-export, not the test.

- [ ] **Step 7: Prove the missing-fixture guard cannot pass vacuously**

Temporarily rename one fixture file, run the root suite, confirm it fails loudly with the fixture name in the message rather than failing on an unrelated assertion. Restore. Record the transcript.

- [ ] **Step 8: Update the paths that name the old location**

`CLAUDE.md`, `HANDOFF.md` and `packages/kudosity-client/README.md` all say `tests/Fixtures/V2Webhooks/README.md`. Grep and fix:

```bash
grep -rn "tests/Fixtures/V2Webhooks\|tests/Fixtures/V2Senders" --exclude-dir=vendor --exclude-dir=.git . | grep -v "packages/kudosity-client/tests"
```

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "test: move the captured fixtures into the package whose API produced them"
```

---

## Task 2: Webhook payloads and guards in the client suite

**Files:**
- Create: `packages/kudosity-client/tests/WebhookPayloadTest.php`
- Create: `packages/kudosity-client/tests/WebhookGuardsTest.php`

**Interfaces:**
- Consumes: `Fixtures::webhook()` from Task 1.
- Produces: nothing later tasks depend on.

**Read `packages/kudosity-client/tests/Fixtures/V2Webhooks/README.md` first.** This is the surface where its findings live, and every one of them was discovered by a live capture contradicting the docs.

Cover, at minimum:

| Behaviour | Why it is not obvious |
|---|---|
| Each captured fixture resolves to its payload class | Ten event types, four classes plus `UnknownEvent` |
| All ten documented types map to the right class | `WHATSAPP_STATUS` and `RCS_STATUS` share `StatusEvent` with SMS |
| `UnknownEvent` is **returned, not thrown** | A receiver does not choose what it is sent |
| `messageRef()` reads a different path per event type | `status.message_ref`, `mo.last_message.message_ref`, `link_hit.source_message.message_ref`, `opt_out.source_message.message_ref` |
| Inbound text is **not trimmed** | A captured reply is `"YES "` |
| `routed_via: ""` normalises to `null` | The only deliberate transformation in that DTO |
| Inbound MMS media is inline base64 under `mo.media[]` | `content_urls` is the *outbound* shape and is absent |
| `InboundMedia::mimeType()` sniffs bytes, extension second | The filename comes from a stranger |
| `InboundMedia::bytes()` returns null on undecodable content | Same reasoning as `UnknownEvent` |
| An inbound MMS has no `last_message` | `messageRef()` is null; an MMS reply cannot be correlated |
| A missing or wrong-typed section survives | A truncated or hostile POST body must not fatal |

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Tests\Fixtures\Fixtures;
use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\UnknownEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookEvent::class)]
final class WebhookPayloadTest extends TestCase
{
    /** @return array<string, array{0: string, 1: class-string}> */
    public static function capturedDeliveries(): array
    {
        return [
            'sms sent' => ['sms-status-sent', StatusEvent::class],
            'sms delivered' => ['sms-status-delivered', StatusEvent::class],
            'mms delivered' => ['mms-status-delivered', StatusEvent::class],
            'sms inbound' => ['sms-inbound-with-last-message', InboundEvent::class],
            'mms inbound' => ['mms-inbound-with-media', InboundEvent::class],
            'link hit' => ['link-hit-sms', LinkHitEvent::class],
            'link hit repeat' => ['link-hit-sms-repeat', LinkHitEvent::class],
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('capturedDeliveries')]
    public function test_each_captured_delivery_resolves_to_its_payload_class(
        string $fixture,
        string $expected,
    ): void {
        $this->assertInstanceOf($expected, WebhookEvent::fromArray(Fixtures::webhook($fixture)));
    }

    public function test_an_unrecognised_event_type_is_returned_not_thrown(): void
    {
        // A receiver does not choose what it is sent. Throwing turns an
        // unknown type into a 500, which reads as a dead endpoint and earns a
        // retry into the same 500.
        $payload = Fixtures::webhook('sms-status-sent');
        $payload['event_type'] = 'TELEPATHY_STATUS';

        $event = WebhookEvent::fromArray($payload);

        $this->assertInstanceOf(UnknownEvent::class, $event);
        $this->assertSame('TELEPATHY_STATUS', $event->raw['event_type']);
    }

    public function test_inbound_mms_media_arrives_inline_rather_than_as_a_url(): void
    {
        $inbound = WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'));

        $this->assertSame([], $inbound->contentUrls);
        $this->assertCount(1, $inbound->media);
        $this->assertSame('image/jpeg', $inbound->media[0]->mimeType());
        $this->assertStringStartsWith("\xFF\xD8\xFF", (string) $inbound->media[0]->bytes());
    }

    public function test_it_does_not_trim_inbound_message_text(): void
    {
        // Real inbound text is untidy. The captured reply is "YES " with the
        // trailing space, and trimming here would hide it from the consumer
        // who has to decide what "equal" means.
        $this->assertSame(
            'YES ',
            WebhookEvent::fromArray(Fixtures::webhook('sms-inbound-with-last-message'))->message,
        );
    }

    /** @return array<string, array{0: mixed}> */
    public static function hostileSections(): array
    {
        return ['missing' => [null], 'a string' => ['nope'], 'a list' => [[1, 2, 3]]];
    }

    #[DataProvider('hostileSections')]
    public function test_it_survives_a_section_that_is_missing_or_the_wrong_type(mixed $section): void
    {
        $event = WebhookEvent::fromArray(['event_type' => 'SMS_STATUS', 'status' => $section]);

        $this->assertInstanceOf(StatusEvent::class, $event);
        $this->assertSame('', $event->id);
        $this->assertNull($event->messageRef());
    }
}
```

`WebhookGuardsTest.php` covers `StatusPrecedence` and `SignedMessageRef`:

```php
public function test_a_late_sent_does_not_regress_a_recorded_delivered(): void
{
    // Deliveries are at-least-once AND unordered — observed live, a SENT
    // redelivered 57 seconds AFTER its DELIVERED, byte-identical.
    $this->assertFalse(
        StatusPrecedence::supersedes(MessageStatus::Sent, MessageStatus::Delivered),
    );
}

public function test_a_read_receipt_legitimately_follows_delivery(): void
{
    // This is why it is a rank and not a terminal-status check:
    // MessageStatus::isTerminal() is true for both DELIVERED and READ, so a
    // "never overwrite a terminal status" rule silently drops RCS read
    // receipts.
    $this->assertTrue(
        StatusPrecedence::supersedes(MessageStatus::Read, MessageStatus::Delivered),
    );
}

public function test_a_signed_ref_is_parsed_from_the_last_colon(): void
{
    // Real refs are composite — a captured one is "order-9931:cust-4471" —
    // so explode(':', $ref)[0] truncates the entity while still looking like
    // it worked.
    $signed = SignedMessageRef::sign('order-9931:cust-4471', 'secret');

    $this->assertSame('order-9931:cust-4471', SignedMessageRef::verify($signed, 'secret'));
}
```

Check the real signatures of `StatusPrecedence` and `SignedMessageRef` before writing — the names above are from the docblocks, not from a compile.

- [ ] **Step 2: Run and watch them fail**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter 'Webhook'`
Expected: FAIL. If any test passes before the file is complete, it is asserting nothing — fix it now.

- [ ] **Step 3: No implementation needed — these cover shipped behaviour**

If a test fails against shipped code, **stop and decide** whether the test or the code is wrong before changing either. Every behaviour in the table above is deliberate and documented; a disagreement means one of the two documents is stale.

- [ ] **Step 4: Run the suite on 8.2**

```bash
cd packages/kudosity-client
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit --no-coverage
```

- [ ] **Step 5: Mutation-test at least four of them**

Suggested mutations, each of which must turn the suite red:

| Mutation | File |
|---|---|
| `mo.media` → `mo.medias` | `Webhooks/InboundEvent.php` |
| `UnknownEvent` return → `throw` | `Webhooks/WebhookEvent.php` |
| Add `trim()` to the inbound message | `Webhooks/InboundEvent.php` |
| `supersedes()` returns `true` unconditionally | `Webhooks/StatusPrecedence.php` |

**Patch with `php -r` and `str_replace`, not `perl -0pi -e`.** A perl pattern containing a PHP `$variable` interpolates to nothing even inside `\Q…\E`, so the mutation silently no-ops and reports clean. Always assert the file actually changed.

- [ ] **Step 6: Commit**

```bash
git add packages/kudosity-client/tests
git commit -m "test: cover the webhook payload surface in the client suite"
```

---

## Task 3: Every V2 request's path, method and body

**Files:**
- Create: `packages/kudosity-client/tests/RequestShapeTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing.

There are **22** request classes under `packages/kudosity-client/src/Requests/V2/`. A wrong path or a renamed body key is invisible until it reaches the API, and the SDK's own guards (message_ref length, MMS subject length, the RCS agent-ID check) fire *before* the request leaves the process, so they are cheap and precise to test.

- [ ] **Step 1: Write a table-driven failing test**

```php
/** @return array<string, array{0: KudosityV2Request, 1: string, 2: string}> */
public static function endpoints(): array
{
    return [
        'send sms' => [new SendSmsV2Request('m', '61400000000', '61481074185'), 'POST', '/v2/sms'],
        'get sms' => [new GetSmsV2Request('abc'), 'GET', '/v2/sms/abc'],
        'list sms' => [new ListSmsV2Request, 'GET', '/v2/sms'],
        'send mms' => [new SendMmsRequest('61400000000', '61481074185', ['https://e.com/a.jpg']), 'POST', '/v2/mms'],
        // ... one row per class. All 22.
    ];
}

#[DataProvider('endpoints')]
public function test_each_request_addresses_its_documented_endpoint(
    Request $request,
    string $method,
    string $path,
): void {
    $this->assertSame($method, $request->getMethod()->value);
    $this->assertSame($path, $request->resolveEndpoint());
}
```

Then the guards, one test per rule with a **single-violation** input:

```php
public function test_it_rejects_an_over_long_message_ref_before_sending(): void
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessageMatches('/message_ref/');

    new SendSmsV2Request('m', '61400000000', '61481074185', str_repeat('a', 501));
}

public function test_it_rejects_an_mms_subject_over_twenty_characters(): void
{
    // 20 is the documented maximum and the SDK enforces it locally, so a
    // too-long subject costs nothing to discover.
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessageMatches('/subject length \(21\)/');

    new SendMmsRequest('61400000000', '61481074185', ['https://e.com/a.jpg'], str_repeat('a', 21));
}

public function test_rcs_rejects_a_phone_number_where_an_agent_id_belongs(): void
{
    // An RCS sender is a registered agent ID, never a number. Caught locally
    // so it costs no API call, and the message must name "agent ID" or the
    // operator retries with another number.
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessageMatches('/agent/i');

    new SendRcsRequest('m', '61400000000', '61481074185');
}
```

Confirm each constructor's real parameter order before writing — several take `$recipient, $sender` and others take `$to, $from`.

- [ ] **Step 2: Run and watch it fail**

Run: `cd packages/kudosity-client && vendor/bin/phpunit --filter RequestShapeTest`

- [ ] **Step 3: Add the missing rows until all 22 classes appear**

Guard against silently covering 19 of 22:

```php
public function test_every_v2_request_class_is_in_the_endpoint_table(): void
{
    // Without this, adding a request class and forgetting its row leaves the
    // suite green and the new endpoint untested.
    $onDisk = array_map(
        static fn (string $f): string => basename($f, '.php'),
        (array) glob(__DIR__.'/../src/Requests/V2/*.php'),
    );

    $covered = array_map(
        static fn (array $row): string => (new \ReflectionClass($row[0]))->getShortName(),
        self::endpoints(),
    );

    $this->assertSame([], array_diff($onDisk, $covered), 'V2 request classes with no endpoint row.');
}
```

- [ ] **Step 4: Run on 8.2, then run both suites**

- [ ] **Step 5: Mutation-test the completeness guard itself**

Delete one row from `endpoints()` and confirm `test_every_v2_request_class_is_in_the_endpoint_table` fails naming the class. This is the test most likely to be a test that cannot fail.

- [ ] **Step 6: Commit**

```bash
git commit -am "test: pin every V2 request's endpoint, method and local guards"
```

---

## Task 4: DTOs — both envelopes, the casts, the absent optionals

**Files:**
- Create: `packages/kudosity-client/tests/DtoTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

The envelope split is the trap: **SMS and MMS return the object flat; WhatsApp, RCS, RCS capabilities and sender registrations wrap it in `data`.** Code written against one and reused for the other reads null. Both resolve through `Concerns\UnwrapsData::payload()`, so both paths need a test or the seam is only half covered.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_flat_envelope_and_a_data_wrapped_one_both_resolve(): void
{
    $flat = SmsMessageData::fromArray(['id' => 'a', 'recipient' => '614', 'sender' => '614',
        'message' => 'm', 'status' => 'queued', 'sms_count' => '1',
        'created_at' => '2022-03-28T06:12:52.450674000Z']);

    $this->assertSame('a', $flat->id);

    // WhatsApp wraps. Asserting the unwrap here rather than only through the
    // resource means a change to UnwrapsData cannot pass by covering one side.
    $wrapped = WhatsAppMessageData::fromArray(['id' => 'b', 'recipient' => '614',
        'sender' => '614', 'status' => 'queued',
        'created_at' => '2022-03-28T06:12:52.450674000Z']);

    $this->assertSame('b', $wrapped->id);
}

public function test_sms_count_arrives_as_a_string_and_is_cast(): void
{
    // Verified live: the API really does send "1", not 1.
    $data = SmsMessageData::fromArray([/* ... 'sms_count' => '2' ... */]);

    $this->assertSame(2, $data->smsCount);
}

public function test_routed_via_empty_string_normalises_to_null(): void
{
    // The only deliberate transformation in this DTO, and it shipped once with
    // no assertion at all despite the fixture setting up the exact case.
    $this->assertNull(SmsMessageData::fromArray([/* 'routed_via' => '' */])->routedVia);
}

public function test_a_nine_fractional_digit_timestamp_parses(): void
{
    // Kudosity sends nine digits, which defeats
    // DateTimeImmutable::createFromFormat(RFC3339_EXTENDED, ...).
    $data = SmsMessageData::fromArray([/* 'created_at' => '2022-03-28T06:12:52.450674000Z' */]);

    $this->assertSame('2022-03-28', $data->createdAt?->format('Y-m-d'));
}

public function test_absent_optional_fields_do_not_fatal(): void
{
    // The minimum the API is documented to return. Anything the SDK adds on
    // top must tolerate its absence.
    $data = SmsMessageData::fromArray(['id' => 'a', 'recipient' => '614', 'sender' => '614',
        'message' => 'm', 'status' => 'queued']);

    $this->assertNull($data->routedVia);
    $this->assertNull($data->messageRef);
}
```

Repeat per DTO across all ten in `Data/V2/` plus `Data/V2/Content/`.

- [ ] **Step 2: Run and watch them fail**
- [ ] **Step 3: Fill in until every `Data/V2` class has at least the envelope, one cast and one absent-optional case**
- [ ] **Step 4: Run on 8.2 and run both suites**
- [ ] **Step 5: Mutation-test the casts** — remove `(int)` from `sms_count` and the `'' → null` normalisation; both must turn the suite red. (The `(int)` mutation is already known to be caught by `V2TransportTest`; confirm this task's test catches it independently, or it adds nothing.)
- [ ] **Step 6: Commit**

```bash
git commit -am "test: cover both V2 envelope shapes and the DTO casts"
```

---

## Task 5: Paginators and enum tolerance

**Files:**
- Create: `packages/kudosity-client/tests/PaginatorTest.php`
- Create: `packages/kudosity-client/tests/EnumToleranceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

Three paginators, three schemes. 1.9.0 shipped a bug where `V1PagedPaginator` hardcoded the `responses` envelope key and **every** endpoint with a different key iterated to zero items — silently, because zero results is a legal answer. Termination conditions and a short final page are the two cases that hide that class of bug.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_paged_list_stops_at_the_last_page(): void
{
    // Termination is the whole risk: an off-by-one either drops the last page
    // or loops forever against a live API.
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient([
        MockResponse::make(['smses' => [['id' => '1'], ['id' => '2']],
            'meta' => ['pagination' => ['page' => 1, 'limit' => 2, 'total' => 3]]], 200),
        MockResponse::make(['smses' => [['id' => '3']],
            'meta' => ['pagination' => ['page' => 2, 'limit' => 2, 'total' => 3]]], 200),
    ]));

    $seen = [];
    foreach ($connector->paginate(new ListSmsV2Request)->items() as $row) {
        $seen[] = $row;
    }

    $this->assertCount(3, $seen, 'A short final page must still be yielded.');
}

public function test_the_paged_paginator_reads_a_total_from_either_documented_key(): void
{
    // GET /v2/sms reports meta.pagination.total; the senders endpoint reports
    // meta.pagination.total_count and defaults to 25 per page rather than 100.
    // Dividing a total by the wrong limit walks off the end of the results.
}

public function test_an_empty_page_terminates_rather_than_looping(): void
{
    // GET /v2/webhook returns {} when empty, omitting the collection key
    // entirely.
}
```

For enums, one parameterised test over every tolerant enum:

```php
/** @return array<string, array{0: class-string}> */
public static function tolerantEnums(): array
{
    return [
        MessageStatus::class => [MessageStatus::class],
        WebhookEventType::class => [WebhookEventType::class],
        OptOutSource::class => [OptOutSource::class],
        RcsCapabilityCode::class => [RcsCapabilityCode::class],
        SenderStatus::class => [SenderStatus::class],
        SenderRegistrationType::class => [SenderRegistrationType::class],
        SenderVerificationMethod::class => [SenderVerificationMethod::class],
    ];
}

/** @param class-string $enum */
#[DataProvider('tolerantEnums')]
public function test_every_tolerant_enum_resolves_an_unknown_value_rather_than_throwing(string $enum): void
{
    // A client reading its own message history must not break because
    // Kudosity added a value after this release.
    $this->assertSame($enum::Unknown, $enum::fromApi('SOMETHING_KUDOSITY_ADDED_LATER'));
}
```

Plus the specific one that is load-bearing rather than defensive:

```php
public function test_sender_status_verified_does_not_mean_ready_to_use(): void
{
    // VERIFIED means *provisioning*. Only READY_TO_USE can send, and treating
    // VERIFIED as usable produces sends that fail at the API.
    $this->assertFalse(SenderStatus::Verified->isReadyToUse());
    $this->assertTrue(SenderStatus::ReadyToUse->isReadyToUse());
}
```

- [ ] **Step 2: Run and watch them fail**
- [ ] **Step 3: Fill in until all three paginators and all seven enums are covered**
- [ ] **Step 4: Run on 8.2 and run both suites**
- [ ] **Step 5: Mutation-test** — hardcode one paginator's items key to `responses` (the real 1.9.0 bug) and confirm the suite goes red; make one enum's `fromApi()` throw on an unknown value and confirm the same.
- [ ] **Step 6: Commit**

```bash
git commit -am "test: cover all three paginators and every tolerant enum"
```

---

## Task 6: Value objects, guards and the offline helpers

**Files:**
- Create: `packages/kudosity-client/tests/ValueObjectTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

Covers `Contracts\WhatsAppContent` and its three variants, `Data\V2\SmsFallback`, `Support\PhoneNumber`, the country-code table, and `Callbacks\CallbackUrlBuilder`/`CallbackUrlParser`.

**`CallbackUrlParser` belongs here and is the most security-relevant class in the package.** Its "events-only mode" — returning without verifying when neither `h` nor `c` is present — is correct for the V1 GET routes and was wrong for the V2 receiver, which is why `WebhookController::events()` requires both before consulting it. Test the parser's actual contract, and **do not loosen it** to match the controller.

- [ ] **Step 1: Write the failing tests**

```php
public function test_whatsapp_content_types_cannot_desynchronise_from_their_payload(): void
{
    // The value objects exist so `content_type` and `content` are produced
    // together — a string content_type plus a hand-built array is how they
    // drift.
    $template = new TemplateContent('order_update', ['ACME']);

    $this->assertSame('template', $template->contentType());
    $this->assertSame(['name' => 'order_update', 'parameters' => ['ACME']], $template->toArray());
}

public function test_a_signed_url_round_trips_through_the_parser(): void
{
    $builder = new CallbackUrlBuilder('https://app.test/webhooks/kudosity', 'signing-key');

    $url = $builder->build(CallbackType::EVENTS, 'App\\Handlers\\Events', ['order_id' => 991]);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $parsed = (new CallbackUrlParser('signing-key'))->parse($query);

    $this->assertSame('App\\Handlers\\Events', $parsed['handler']);
    $this->assertSame(991, $parsed['context']['order_id']);
}

public function test_a_tampered_signature_is_refused(): void
{
    $builder = new CallbackUrlBuilder('https://app.test/webhooks/kudosity', 'signing-key');
    parse_str((string) parse_url($builder->build(CallbackType::EVENTS, 'H'), PHP_URL_QUERY), $query);
    $query['s'] = str_repeat('0', strlen((string) $query['s']));

    $this->expectException(InvalidSignatureException::class);

    (new CallbackUrlParser('signing-key'))->parse($query);
}

public function test_the_parser_still_allows_an_unsigned_events_only_url(): void
{
    // Documented, deliberate, and relied on by the three V1 GET routes where
    // there is no handler to protect. The V2 receiver compensates by requiring
    // `s` and `h` itself — do NOT "fix" this to match the receiver.
    $this->assertSame(null, (new CallbackUrlParser('signing-key'))->parse([])['handler']);
}

public function test_it_never_guesses_a_country_for_a_local_number(): void
{
    // Guessing wrong sends a real message to the wrong person, which is worse
    // than failing.
    $this->expectException(\InvalidArgumentException::class);

    PhoneNumber::toE164('0400000000', null);
}
```

Confirm the real method names on `WhatsAppContent`, `PhoneNumber` and `CallbackUrlParser` before writing — the ones above come from docblocks.

- [ ] **Step 2: Run and watch them fail**
- [ ] **Step 3: Fill in the remaining value objects**
- [ ] **Step 4: Run on 8.2 and run both suites**
- [ ] **Step 5: Mutation-test the signature check** — replace `hash_equals` with `===`. **It will survive**, because the difference is timing and not result; that is expected and already recorded as an invariant in the class docblock. Do not add a test that pretends to cover it. Mutate the comparison to `true` instead, which must go red.
- [ ] **Step 6: Commit**

```bash
git commit -am "test: cover the value objects, the callback URL contract and the offline helpers"
```

---

## Task 7: De-duplicate the two suites and run the removed-symbol audit

**Files:**
- Delete: whichever root test files Tasks 2–6 fully superseded
- Modify: `tests/Pest.php` or `tests/TestCase.php` if a deleted file held a shared helper
- Modify: `.github/workflows/run-tests.yml` — comment only, if the split changed

**Interfaces:**
- Consumes: every test written in Tasks 2–6.
- Produces: a root suite that is Laravel-only plus `CodemodTest`.

**Apply the ownership rule from the Reference section, not the symbol survey.** `KudosityMessageTest.php` and `KudosityMessageRoutingTest.php` import no Laravel symbol and must still stay at root, because `KudosityMessage` lives in the Laravel package.

Deleting a root test is only safe when the client suite covers **the same behaviour**, not merely the same class. Where the two disagree, the client version wins only if it was written from the behaviour; a mechanical port is not evidence.

- [ ] **Step 1: Produce the inventory before deleting anything**

```bash
for f in tests/Unit/*.php; do
  cls=$(grep -oE "ExpertSystems\\\\Kudosity\\\\[A-Za-z\\\\]+" "$f" | sort -u | head -3 | tr '\n' ' ')
  echo "$(basename "$f")  ->  $cls"
done
```

For each file decide: **move** (class under `packages/kudosity-client/src`), **stay** (Laravel package or `bin/`), or **already covered** (Tasks 2–6 wrote an equivalent). Write the table into the commit message — it is the record of why each file went where.

- [ ] **Step 2: Delete only the "already covered" files, one commit per file**

One per commit so a wrong call is a single `git revert` rather than an archaeology exercise.

- [ ] **Step 3: After each deletion, run both suites**

Run: `vendor/bin/pest --compact` and `cd packages/kudosity-client && vendor/bin/phpunit`
Expected: root count drops by exactly the deleted file's tests; client count unchanged.

- [ ] **Step 4: Confirm no coverage was lost, by measurement rather than by reasoning**

```bash
composer test-coverage
cd packages/kudosity-client && vendor/bin/phpunit --coverage-text
```

Compare the client `src/` line coverage before and after. **If it fell, a deletion was wrong** — restore that file and write the missing client test.

- [ ] **Step 5: Run the removed-symbol audit**

```bash
git diff -U0 main -- packages | grep -E '^-\s*(public|protected|const|final public)'
```

Expected: empty. This phase changes no production code. **A non-empty result means a task exceeded its scope** — investigate before continuing, and if a removal is genuine, add it to `rename-map.json` and `UPGRADING.md`. Every phase so far that skipped this step shipped a consumer-contract gap.

**This audit answers one question only: was a symbol removed?** The pattern is anchored to `^-`, so it is blind by construction to an *added* symbol, a changed signature, and any change inside a method body. Task 7 reported "no production code changed" on the strength of an empty result while Task 3's `1be3716` had in fact added a `ValidationException` guard to `CheckRcsCapabilitiesRequest`. Run the companion check too, and treat only the pair as a scope gate:

```bash
git diff --name-only main -- packages | grep /src/    # did any production file change at all?
```

- [ ] **Step 6: Run the codemod against itself**

```bash
php bin/kudosity-codemod packages   # expect: 0 files would change
php bin/kudosity-codemod tests      # expect: 1 file — tests/Unit/CodemodTest.php, by design
```

Anything else means a `rename-map.json` entry is matching the SDK's own source.

- [ ] **Step 7: Commit**

```bash
git commit -m "test: retire the root tests the client suite now owns"
```

---

## Task 7b: Port the 24 root test files the client package owns

**Added 2026-08-07, after Task 7 executed.** Task 7's premise did not survive contact with the suites, and this task is the user's answer to that.

### Why this task exists

Task 7 assumed Tasks 2–6 had duplicated the root tests, so retiring the copies would leave the root suite covering the Laravel package and `bin/kudosity-codemod` and nothing else. They had not. For every class the two suites share, the client suite asserts a strict **subset** — verified by a reviewer against ten root/client pairs, assertion by assertion. Only `tests/ExampleTest.php` was genuinely superseded, and it was deleted in `6034d86`.

The measurement that makes the gap concrete: the client suite alone covers **24.75%** of its own `src/` (16 of 151 classes ever execute), while the union of both suites covers **65.90%**. Two thirds of the client package's coverage exists only in the root Pest suite — which requires PHP 8.3+ and installs Laravel, and therefore **never runs on the 8.2 floor this phase exists to prove**. Deleting on class identity would have destroyed real behaviour: 7 of 8 V1 pagination envelope keys, 27 of 30 `PhoneNumber` tests, all of `Support\Url`, the `SignedMessageRef` forgery cases, ~30 root-only `V2WebhookEventTest` assertions.

**Decision (user, 2026-08-07): do the full port.** All 24 files whose class under test lives in `packages/kudosity-client/src` move to the client suite, in batches. The Definition of Done stands as written.

### Global rules for every batch

- **Port by rewriting each assertion from the behaviour, never by transliterating the Pest call.** A Pest `expect($x)->toBe($y)` mistranslated into `assertTrue(...)` against a truthy value passes forever. This is the phase's named defect class and a *ported* test is the likeliest place for it, because a test that already existed is assumed to work.
- **No test's assertions may weaken.** Same inputs, same specificity, same count of distinct behaviours asserted. If a Pest idiom has no clean PHPUnit equivalent, ask rather than approximate. A Pest chained `->and(...)` is several assertions and stays several assertions.
- PHPUnit 11 attributes: `#[DataProvider]` for a Pest `dataset()`, `#[CoversClass]` as the neighbouring client tests use it. Never Pest, never PHPUnit 12.
- **One commit per file**, adding the client test and deleting the root file together, so a wrong call is one `git revert`.
- Every root file in this task is confirmed free of container and framework dependencies — no `config()`, no `app()`, no `Illuminate`, no Testbench feature. The only shared code any of them touches is `tests/Fixtures/StubV2SendRequest.php` (`KudosityClientTest`, `V2ConnectorTest`, `V2ErrorTest`) and the `webhookFixture()` helper (`V2SignedMessageRefTest`, `V2StatusPrecedenceTest`, `V2WebhookEventTest`), whose client equivalent is `Fixtures::webhook()`. `StubV2SendRequest` moves into the client suite's `Fixtures/` on first use.
- **The union over `packages/kudosity-client/src` must not fall from 65.90% (1714/2601 statements).** It should hold almost exactly: the same behaviour is being asserted from a different suite. **Client-alone coverage must rise** with every batch — that is the number this task exists to move, and reporting it per batch is how we know the port is doing its job rather than shuffling files.
- Each batch runs: the client suite, the client suite on `php:8.2-cli` via Docker, the root suite, PHPStan, Pint. Coverage per the recipe in the ledger (`kudosity-cov:8.3`/`:8.4`, `-d memory_limit=1G`, then `union-coverage.php`).
- **Root count must drop by exactly the ported file's test count and the client count must rise by at least it** (a Pest chained expectation often becomes more than one PHPUnit test). Reconcile any difference explicitly; an unexplained gap means a test was dropped.

### Batches

| # | Files | Root tests | Notes |
|---|---|---:|---|
| 1 | `PhoneNumberTest`, `CountryCodesTest`, `UrlValidationTest` | 95 | Pure support classes, no dependencies. `Support\Url` has no client-side reference at all. |
| 2 | `DtoTest` → **`V1DtoTest`**, `ExceptionTest`, `RateLimitExceptionTest`, `RetryConfigurationTest`, `BulkSmsResourceTest`, `SendSmsRequestTest` | 100 | V1 surface. **Rename on the way over** — the client suite already has a `DtoTest.php` for `Data\V2\*`. |
| 3 | `KudosityClientTest`, `V2ConnectorTest`, `V2ErrorTest`, `V2FoundationsTest` | 75 | Move `StubV2SendRequest` into the client `Fixtures/` here. `KudosityClientTest` pins each resource to the **V2 host** by reading the sent URI — an `instanceof` check passes for a resource wired to the wrong connector, so that assertion must survive intact. |
| 4 | `PaginationTest`, `V2PaginationTest`, `V2DateRangeFilterTest` | 51 | `PaginationTest`'s eight envelope keys each go through the real resource method, including the `members` regression that shipped in 1.9.0. |
| 5 | `V2WebhookEventTest`, `V2StatusPrecedenceTest`, `V2SignedMessageRefTest` | 135 | Read `tests/Fixtures/V2Webhooks/README.md` first. `webhookFixture()` → `Fixtures::webhook()`. The forgery cases are the security-relevant ones. |
| 6 | `V2SmsTest`, `V2MmsTest`, `V2WhatsAppTest` | 81 | Resource-level behaviour; `RequestShapeTest` already pins endpoints and guards, so port what it does not cover. |
| 7 | `V2RcsTest`, `V2WebhooksResourceTest`, `V2SendersResourceTest` | 138 | `V2SendersResourceTest` holds the inlined sender-fixture loader — it moves to the shared `Fixtures` class, per Task 1's precedent. |

After batch 7: remove whatever is left unused at root (`tests/Fixtures/StubV2SendRequest.php`, the `WebhookPayloads` re-export, `webhookFixture()`) only if nothing references it, and confirm the root suite is Laravel + `CodemodTest` + `ArchTest` and nothing else.

### Definition of done for 7b

- All 24 files ported, one commit each, no assertion weakened.
- Root suite contains only: the 11 STAY files (Laravel package), `CodemodTest`, `ArchTest`.
- Union over client `src/` still ≥ 65.90%; **client-alone coverage substantially higher than 24.75%** and reported.
- The whole client suite green on 8.2, 8.3 and 8.4.

---

## Task 8: Documentation and the 2.0.0 changelog

**Files:**
- Modify: `CHANGELOG.md` — `## Unreleased` becomes `## 2.0.0 - <date>`
- Modify: `CLAUDE.md` — the Common Commands section
- Modify: `README.md` and `packages/kudosity-client/README.md` — how to run each suite
- Modify: `UPGRADING.md` — only if Task 7's audit found a removal

**Interfaces:**
- Consumes: the final test counts from Task 7.
- Produces: the release notes Task 9 tags.

- [ ] **Step 1: Add the client suite to `CLAUDE.md`'s commands**

````markdown
```bash
# The Laravel integration suite (Pest, PHP 8.3+)
composer test

# The client package's own suite, standalone on the declared floor
cd packages/kudosity-client && composer install && vendor/bin/phpunit

# The same on PHP 8.2, which no local toolchain provides
cd packages/kudosity-client && docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit
```
````

Add a sentence to the Testing section: **PHPUnit 11 rather than 12, because 12 requires PHP >= 8.3 and the packages declare `^8.2`.**

- [ ] **Step 2: Rename the changelog section and date it**

Keep every existing `Unreleased` bullet. Add, under `### Added`:

```markdown
- The client package ships its own PHPUnit 11 suite, installed and run standalone on PHP 8.2, 8.3 and 8.4. Previously nothing executed the declared `^8.2` floor — Pest 4 requires 8.3 — and nothing proved the package installs without Laravel. PHPStan now analyses the whole declared range, so 8.3-only syntax fails review rather than a consumer's runtime.
```

- [ ] **Step 3: Verify every documented command actually runs**

Execute each command block in the READMEs and `CLAUDE.md` verbatim, from a clean checkout, and fix any that fail. A README command that does not run is worse than an absent one.

- [ ] **Step 4: Run the branding sweep and its negative check**

```bash
EXCL="--exclude-dir=vendor --exclude-dir=.git --exclude-dir=docs --exclude=CHANGELOG.md \
      --exclude=UPGRADING.md --exclude=rename-map.json --exclude=kudosity-codemod \
      --exclude=CodemodTest.php --exclude=HANDOFF.md --exclude-dir=.ai-skills --exclude-dir=.agents"
grep -rniE 'transmitsms' . $EXCL | grep -vE 'api\.transmitsms\.com|TRANSMITSMS_API|x-transmitsms-signature'
grep -rn 'api\.kudosity\.com' . $EXCL     # must be empty
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: document both suites and cut the 2.0.0 changelog entry"
```

---

## Task 9: Release

**Files:** none in the repo. This is dashboard work plus one tag.

- [ ] **Step 1: Merge to `main` and confirm every check is green**

All four workflows, and specifically all three `Client standalone` jobs including **P8.2**.

- [ ] **Step 2: Do the dashboard work, in this order**

1. Rename the GitHub monorepo to `kudosity-php-sdk`.
2. Confirm `expertsystemsau/kudosity-php-client` and `kudosity-laravel-client` are populated — they were at `059650b`, and `Split Monorepo` has been green since `21f1ef9`.
3. Register both on Packagist.
4. Mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned, pointing at the replacements.

Registering on Packagist **before** the split repos have content publishes an empty package, so keep the order.

- [ ] **Step 3: Rotate the API key and secret**

They were pasted into a chat transcript. Also update `.env`'s `KUDOSITY_FROM` to `61437130145` — the value there is the number Kudosity retired, and `POST /v2/sms` answers `Sender not found` for it.

- [ ] **Step 4: Tag**

```bash
git tag v2.0.0        # the `v` is mandatory
git push origin v2.0.0
```

**Tag `1.7.0` was cut without the prefix and never released.** Verify the release appears on both Packagist entries before calling it done.

- [ ] **Step 5: Update `HANDOFF.md` to say 2.0.0 is released, and close the phase**

---

## Definition of done

- The client package has a PHPUnit 11 suite covering its own surface: all ten webhook event types against the captured fixtures, all 22 V2 request classes' paths and bodies, every `Data/V2` DTO across both envelope shapes, all three paginators including termination and a short final page, every tolerant enum's `Unknown` fallback, and the callback URL contract.
- That suite installs and runs **standalone on PHP 8.2**, proven both in CI and on `php:8.2-cli`, and CI fails if a framework appears in the client package's dependency tree.
- The captured fixtures have exactly one copy, owned by the client package, reachable from both suites.
- The root Pest suite covers the Laravel package and `bin/kudosity-codemod`, and nothing else. No behaviour is covered in both suites.
- Client `src/` line coverage did not fall across Task 7's deletions, measured rather than argued.
- Every task's new tests were mutation-tested, with the transcript in the report — and mutations were applied with `php -r`/`str_replace`, verifying the file changed.
- The removed-symbol audit ran and came back empty, because this phase changes no production code.
- `composer analyse` clean at `phpVersion` 80200–80499; Pint clean; three manifests valid; branding sweep and its negative check clean.
- `CHANGELOG.md` carries a dated `2.0.0`; every documented command runs verbatim.
- `v2.0.0` tagged with the `v`, both packages live on Packagist, both old packages abandoned, credentials rotated.

## Self-review notes

- **Spec coverage.** Section 6's three parts map to Tasks 1–7 (the suite and the 8.2 job — the job itself already landed in `ffd2188`), Task 8 (docs) and Task 9 (release). The spec's coverage list is reproduced task by task: requests → 3, DTOs → 4, error mapping → already in `V2TransportTest`, paginators → 5, WhatsApp/RCS value objects → 6, webhook payloads → 2, enums → 5.
- **Known gap, deliberate.** The spec asks the root Pest suite to keep "arch tests carried over". They are not called out in a task because they already pass and are not affected by the split — if Task 7 deletes a file they depend on, that surfaces immediately as a root-suite failure.
- **Type consistency.** `Fixtures::webhook()` and `Fixtures::path()` are defined in Task 1 and used with those exact names in Tasks 2–6. The root helper `webhookFixture()` keeps its signature so no root test is edited in Task 1.
- **Unverified signatures.** Tasks 2, 5 and 6 name methods taken from docblocks rather than from a compile — `StatusPrecedence::supersedes()`, `SignedMessageRef::sign()`/`verify()`, `SenderStatus::isReadyToUse()`, `PhoneNumber::toE164()`, `WhatsAppContent::contentType()`. Each of those steps says to confirm against the source first. This is flagged rather than fixed because confirming them all now would mean reading six files into a plan that the implementer will read anyway.
