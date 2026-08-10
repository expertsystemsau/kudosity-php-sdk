<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\V2\EnsureResult;
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
}
