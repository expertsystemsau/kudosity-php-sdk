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
