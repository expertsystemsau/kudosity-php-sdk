<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\GetBalanceRequest;
use ExpertSystems\Kudosity\Requests\V2\ListSenderRegistrationsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\SendMmsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\SendWhatsAppRequest;
use ExpertSystems\Kudosity\Resources\BulkSmsResource;
use ExpertSystems\Kudosity\Resources\MmsResource;
use ExpertSystems\Kudosity\Resources\RcsResource;
use ExpertSystems\Kudosity\Resources\SendersResource;
use ExpertSystems\Kudosity\Resources\SmsV2Resource;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use ExpertSystems\Kudosity\Resources\WhatsAppResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use StubV2SendRequest;

/**
 * Ported from the root Pest suite's tests/Unit/KudosityClientTest.php.
 *
 * The point of every "exposes X()" test below is the host each resource's
 * outgoing request lands on, not the resource's class. An `instanceof` check
 * alone passes for a resource wired to the wrong connector — its type is
 * still correct even when it will only ever talk to the wrong host — so each
 * test sends through a mock and reads the URI back off the pending request.
 */
#[CoversClass(KudosityClient::class)]
#[CoversClass(BulkSmsResource::class)]
#[CoversClass(SmsV2Resource::class)]
#[CoversClass(MmsResource::class)]
#[CoversClass(WhatsAppResource::class)]
#[CoversClass(RcsResource::class)]
#[CoversClass(WebhooksResource::class)]
#[CoversClass(SendersResource::class)]
final class KudosityClientTest extends TestCase
{
    public function test_exposes_both_connectors_each_on_its_own_host(): void
    {
        $client = new KudosityClient('key', 'secret');

        $this->assertInstanceOf(KudosityV1Connector::class, $client->v1());
        $this->assertInstanceOf(KudosityV2Connector::class, $client->v2());
        $this->assertSame('https://api.transmitsms.com', $client->v1()->resolveBaseUrl());
        $this->assertSame('https://api.transmitmessage.com', $client->v2()->resolveBaseUrl());
    }

    public function test_keeps_connector_as_the_v1_connector(): void
    {
        $client = new KudosityClient('key', 'secret');

        $this->assertSame($client->v1(), $client->connector());
    }

    public function test_never_sends_the_secret_over_v2(): void
    {
        $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'x'], 200)]);

        $client = new KudosityClient('key', 'super-secret');
        $client->v2()->withMockClient($mock);
        $client->v2()->send(new StubV2SendRequest('hi'));

        $headers = $mock->getLastPendingRequest()->headers()->all();

        $this->assertSame('key', $client->v2()->getApiKey());
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertStringNotContainsString('super-secret', implode('|', array_map('strval', $headers)));
    }

    public function test_constructs_without_a_secret_for_v2_only_use(): void
    {
        $client = new KudosityClient('key');

        $this->assertSame('key', $client->v2()->getApiKey());
    }

    public function test_fails_a_v1_call_with_a_clear_message_when_no_secret_was_given(): void
    {
        $connector = new KudosityV1Connector('key', '');
        $connector->withMockClient(new MockClient([GetBalanceRequest::class => MockResponse::make([], 200)]));

        $this->expectException(KudosityException::class);
        $this->expectExceptionMessage('V1 API requires both');

        $connector->send(new GetBalanceRequest);
    }

    public function test_accepts_per_api_base_url_overrides(): void
    {
        $client = new KudosityClient('key', 'secret', 'https://v1.test', 'https://v2.test');

        $this->assertSame('https://v1.test', $client->v1()->resolveBaseUrl());
        $this->assertSame('https://v2.test', $client->v2()->resolveBaseUrl());
    }

    public function test_exposes_bulk_and_caches_the_instance(): void
    {
        $client = new KudosityClient('key', 'secret');

        $this->assertInstanceOf(BulkSmsResource::class, $client->bulk());
        $this->assertSame($client->bulk(), $client->bulk());
    }

    public function test_exposes_sms_against_the_v2_connector_not_v1_and_caches_the_instance(): void
    {
        // The load-bearing assertion is the host, not the type: a resource
        // wired to the V1 connector by mistake still passes an
        // assertInstanceOf() check.
        $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(['id' => 'x'], 200)]);
        $client = new KudosityClient('key', 'secret');
        $client->v2()->withMockClient($mock);

        $this->assertInstanceOf(SmsV2Resource::class, $client->sms());
        $this->assertSame($client->sms(), $client->sms());

        $client->sms()->send('hi', '61400000000', '61400000001');

        $this->assertStringStartsWith(
            'https://api.transmitmessage.com',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_exposes_mms_against_the_v2_connector_not_v1_and_caches_the_instance(): void
    {
        $mock = new MockClient([SendMmsRequest::class => MockResponse::make(['id' => 'x'], 200)]);
        $client = new KudosityClient('key', 'secret');
        $client->v2()->withMockClient($mock);

        $this->assertInstanceOf(MmsResource::class, $client->mms());
        $this->assertSame($client->mms(), $client->mms());

        $client->mms()->send('61400000000', '61400000001', ['https://example.com/product.jpg']);

        $this->assertStringStartsWith(
            'https://api.transmitmessage.com',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_exposes_whatsapp_against_the_v2_connector_not_v1_and_caches_the_instance(): void
    {
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(['id' => 'x'], 200)]);
        $client = new KudosityClient('key', 'secret');
        $client->v2()->withMockClient($mock);

        $this->assertInstanceOf(WhatsAppResource::class, $client->whatsapp());
        $this->assertSame($client->whatsapp(), $client->whatsapp());

        $client->whatsapp()->text('hi', '61411122211');

        $this->assertStringStartsWith(
            'https://api.transmitmessage.com',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_exposes_rcs_against_the_v2_connector_not_v1_and_caches_the_instance(): void
    {
        $mock = new MockClient([SendRcsRequest::class => MockResponse::make(['id' => 'x'], 200)]);
        $client = new KudosityClient('key', 'secret');
        $client->v2()->withMockClient($mock);

        $this->assertInstanceOf(RcsResource::class, $client->rcs());
        $this->assertSame($client->rcs(), $client->rcs());

        $client->rcs()->send('hi', '61411122211', 'DemoSender');

        $this->assertStringStartsWith(
            'https://api.transmitmessage.com',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_exposes_webhooks_against_the_v2_connector_not_v1_and_caches_the_instance(): void
    {
        $mock = new MockClient([ListWebhooksRequest::class => MockResponse::make([], 200)]);
        $client = new KudosityClient('key', 'secret');
        $client->v2()->withMockClient($mock);

        $this->assertInstanceOf(WebhooksResource::class, $client->webhooks());
        $this->assertSame($client->webhooks(), $client->webhooks());

        $client->webhooks()->all();

        $this->assertStringStartsWith(
            'https://api.transmitmessage.com',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_exposes_senders_against_the_v2_connector_not_v1_and_caches_the_instance(): void
    {
        $mock = new MockClient([
            ListSenderRegistrationsRequest::class => MockResponse::make(
                ['data' => ['registrations' => []], 'meta' => ['pagination' => ['limit' => 25, 'total_count' => 0]]],
                200,
            ),
        ]);
        $client = new KudosityClient('key', 'secret');
        $client->v2()->withMockClient($mock);

        $this->assertInstanceOf(SendersResource::class, $client->senders());
        $this->assertSame($client->senders(), $client->senders());

        $client->senders()->allRegistrations();

        $this->assertStringStartsWith(
            'https://api.transmitmessage.com',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_builds_from_a_pair_of_pre_configured_connectors(): void
    {
        $v1 = new KudosityV1Connector('k', 's', 'https://v1.test');
        $v2 = new KudosityV2Connector('k', 'https://v2.test');

        $client = KudosityClient::fromConnectors($v1, $v2);

        $this->assertSame($v1, $client->v1());
        $this->assertSame($v2, $client->v2());
    }

    public function test_builds_from_a_v1_connector_alone_and_still_provides_a_v2_connector(): void
    {
        $v1 = new KudosityV1Connector('k', 's');

        $client = KudosityClient::fromConnector($v1);

        $this->assertSame($v1, $client->v1());
        $this->assertInstanceOf(KudosityV2Connector::class, $client->v2());
        $this->assertSame('k', $client->v2()->getApiKey());
    }

    public function test_derives_a_v2_connector_with_its_own_defaults_not_the_v1_connectors_host_or_timeout(): void
    {
        // Distinctive V1 base URL and timeout so the derived V2 connector
        // can't accidentally pass this test by coincidence. fromConnectors()
        // must not "helpfully" copy $v1's base URL or timeout onto the
        // derived V2 connector — they are different hosts, and this is the
        // exact regression a future contributor might introduce while
        // "fixing" it.
        $v1 = new KudosityV1Connector('k', 's', 'https://v1.test', 99);

        $client = KudosityClient::fromConnector($v1);

        $this->assertSame('k', $client->v2()->getApiKey());
        $this->assertSame(KudosityV2Connector::BASE_URL, $client->v2()->resolveBaseUrl());
        $this->assertSame(30, $client->v2()->getTimeout());
    }

    public function test_throws_when_from_connectors_is_given_neither_connector(): void
    {
        $this->expectException(KudosityException::class);
        $this->expectExceptionMessage('Provide at least one connector.');

        KudosityClient::fromConnectors();
    }
}
