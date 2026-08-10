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
}
