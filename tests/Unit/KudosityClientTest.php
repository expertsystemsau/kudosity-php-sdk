<?php

declare(strict_types=1);

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
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// StubV2SendRequest is a shared fixture, loaded once by tests/Pest.php —
// see tests/Fixtures/StubV2SendRequest.php.

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

it('exposes sms() against the V2 connector, not V1, and caches the instance', function () {
    // The load-bearing assertion is the host, not the type: a resource wired
    // to $this->v1Connector by mistake still passes a toBeInstanceOf() check.
    $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(['id' => 'x'], 200)]);
    $client = new KudosityClient('key', 'secret');
    $client->v2()->withMockClient($mock);

    expect($client->sms())->toBeInstanceOf(SmsV2Resource::class)
        ->and($client->sms())->toBe($client->sms());

    $client->sms()->send('hi', '61400000000', '61400000001');

    expect((string) $mock->getLastPendingRequest()->getUri())
        ->toStartWith('https://api.transmitmessage.com');
});

it('exposes mms() against the V2 connector, not V1, and caches the instance', function () {
    $mock = new MockClient([SendMmsRequest::class => MockResponse::make(['id' => 'x'], 200)]);
    $client = new KudosityClient('key', 'secret');
    $client->v2()->withMockClient($mock);

    expect($client->mms())->toBeInstanceOf(MmsResource::class)
        ->and($client->mms())->toBe($client->mms());

    $client->mms()->send('61400000000', '61400000001', ['https://example.com/product.jpg']);

    expect((string) $mock->getLastPendingRequest()->getUri())
        ->toStartWith('https://api.transmitmessage.com');
});

it('exposes whatsapp() against the V2 connector, not V1, and caches the instance', function () {
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(['id' => 'x'], 200)]);
    $client = new KudosityClient('key', 'secret');
    $client->v2()->withMockClient($mock);

    expect($client->whatsapp())->toBeInstanceOf(WhatsAppResource::class)
        ->and($client->whatsapp())->toBe($client->whatsapp());

    $client->whatsapp()->text('hi', '61411122211');

    expect((string) $mock->getLastPendingRequest()->getUri())
        ->toStartWith('https://api.transmitmessage.com');
});

it('exposes rcs() against the V2 connector, not V1, and caches the instance', function () {
    $mock = new MockClient([SendRcsRequest::class => MockResponse::make(['id' => 'x'], 200)]);
    $client = new KudosityClient('key', 'secret');
    $client->v2()->withMockClient($mock);

    expect($client->rcs())->toBeInstanceOf(RcsResource::class)
        ->and($client->rcs())->toBe($client->rcs());

    $client->rcs()->send('hi', '61411122211', 'DemoSender');

    expect((string) $mock->getLastPendingRequest()->getUri())
        ->toStartWith('https://api.transmitmessage.com');
});

it('exposes webhooks() against the V2 connector, not V1, and caches the instance', function () {
    $mock = new MockClient([ListWebhooksRequest::class => MockResponse::make([], 200)]);
    $client = new KudosityClient('key', 'secret');
    $client->v2()->withMockClient($mock);

    expect($client->webhooks())->toBeInstanceOf(WebhooksResource::class)
        ->and($client->webhooks())->toBe($client->webhooks());

    $client->webhooks()->all();

    expect((string) $mock->getLastPendingRequest()->getUri())
        ->toStartWith('https://api.transmitmessage.com');
});

it('exposes senders() against the V2 connector, not V1, and caches the instance', function () {
    $mock = new MockClient([
        ListSenderRegistrationsRequest::class => MockResponse::make(
            ['data' => ['registrations' => []], 'meta' => ['pagination' => ['limit' => 25, 'total_count' => 0]]],
            200,
        ),
    ]);
    $client = new KudosityClient('key', 'secret');
    $client->v2()->withMockClient($mock);

    expect($client->senders())->toBeInstanceOf(SendersResource::class)
        ->and($client->senders())->toBe($client->senders());

    $client->senders()->allRegistrations();

    expect((string) $mock->getLastPendingRequest()->getUri())
        ->toStartWith('https://api.transmitmessage.com');
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

it('derives a V2 connector with its own defaults, not the V1 connector\'s host or timeout', function () {
    // Distinctive V1 base URL and timeout so the derived V2 connector can't
    // accidentally pass this test by coincidence. fromConnectors() must not
    // "helpfully" copy $v1's base URL or timeout onto the derived V2
    // connector — they are different hosts, and this is the exact
    // regression a future contributor might introduce while "fixing" it.
    $v1 = new KudosityV1Connector('k', 's', 'https://v1.test', 99);

    $client = KudosityClient::fromConnector($v1);

    expect($client->v2()->getApiKey())->toBe('k')
        ->and($client->v2()->resolveBaseUrl())->toBe(KudosityV2Connector::BASE_URL)
        ->and($client->v2()->getTimeout())->toBe(30);
});

it('throws when fromConnectors() is given neither connector', function () {
    KudosityClient::fromConnectors();
})->throws(KudosityException::class, 'Provide at least one connector.');
