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

it('has no constructor parameter that could carry an API secret', function () {
    // The header/no-secret test above only proves nothing is sent on the
    // wire today. The structural guarantee is that KudosityV2Connector has
    // no secret parameter at all, so a future refactor (e.g. KudosityClient
    // routing credentials to two connectors) can't quietly reintroduce one
    // out of V1 habit without this failing.
    $params = array_map(
        fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionClass(KudosityV2Connector::class))->getConstructor()->getParameters()
    );

    expect($params)->not->toContain('apiSecret')
        ->and($params)->not->toContain('secret');
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
