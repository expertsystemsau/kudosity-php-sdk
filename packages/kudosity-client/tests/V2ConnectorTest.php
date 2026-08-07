<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Concerns\UnwrapsData;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use StubV2SendRequest;

/**
 * Ported from the root Pest suite's tests/Unit/V2ConnectorTest.php.
 *
 * StubV2SendRequest is the shared global-namespace fixture (see
 * tests/Fixtures/StubV2SendRequest.php); StubV2GetRequest and DataUnwrapper
 * below are local to this file only, exactly as they were in the root spec.
 */
#[CoversClass(KudosityV2Connector::class)]
#[CoversTrait(UnwrapsData::class)]
final class V2ConnectorTest extends TestCase
{
    public function test_resolves_the_v2_base_url(): void
    {
        $this->assertSame('https://api.transmitmessage.com', KudosityV2Connector::BASE_URL);
        $this->assertSame('https://api.transmitmessage.com', (new KudosityV2Connector('key'))->resolveBaseUrl());
    }

    public function test_authenticates_with_the_x_api_key_header_and_never_sends_a_secret(): void
    {
        $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'x'], 200)]);

        $connector = new KudosityV2Connector('my-key');
        $connector->withMockClient($mock);
        $connector->send(new StubV2SendRequest('hi'));

        $headers = $mock->getLastPendingRequest()->headers()->all();

        $this->assertArrayHasKey('x-api-key', $headers);
        $this->assertSame('my-key', $headers['x-api-key']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    public function test_exposes_no_credential_surface_beyond_the_api_key(): void
    {
        // The header/no-secret test above only proves nothing is sent on the
        // wire today. The structural guarantee is that KudosityV2Connector has
        // no secret parameter or property at all, so a future refactor (e.g.
        // KudosityClient routing credentials to two connectors) can't quietly
        // reintroduce one — under any name — without this failing.
        //
        // These are allow-lists, not deny-lists: a denied name like
        // 'apiSecret' would sail straight past '$apiToken', '$credentials', or
        // a setter added later. Asserting the *exact* expected set catches
        // all of those. The property half is a real difference from
        // V2TransportTest's own reflection guard, which covers constructor
        // parameters only.
        $reflection = new ReflectionClass(KudosityV2Connector::class);

        $constructorParams = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            $reflection->getConstructor()->getParameters()
        );

        // Only properties KudosityV2Connector itself declares — Saloon's base
        // Connector class contributes plenty more (headers, auth, retry
        // config, ...) that aren't this class's concern to assert about.
        $ownProperties = array_map(
            fn (ReflectionProperty $p): string => $p->getName(),
            array_filter(
                $reflection->getProperties(),
                fn (ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === KudosityV2Connector::class
            )
        );

        $this->assertSame(['apiKey', 'baseUrl', 'timeout'], $constructorParams);
        $this->assertSame(['apiKey', 'baseUrl', 'timeout'], array_values($ownProperties));
    }

    public function test_sends_a_json_body_not_a_form_body(): void
    {
        $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'x'], 200)]);

        $connector = new KudosityV2Connector('my-key');
        $connector->withMockClient($mock);
        $connector->send(new StubV2SendRequest('hello world'));

        $pending = $mock->getLastPendingRequest();

        $this->assertSame('application/json', $pending->headers()->get('Content-Type'));
        $this->assertSame('{"message":"hello world"}', (string) $pending->body());
    }

    public function test_sends_a_v2_get_with_no_body_and_no_content_type(): void
    {
        $mock = new MockClient([StubV2GetRequest::class => MockResponse::make(['id' => 'x'], 200)]);

        $connector = new KudosityV2Connector('my-key');
        $connector->withMockClient($mock);
        $connector->send(new StubV2GetRequest);

        $pending = $mock->getLastPendingRequest();

        $this->assertNull($pending->headers()->get('Content-Type'));
        $this->assertSame('', (string) $pending->body());
    }

    public function test_does_not_append_json_to_v2_endpoints(): void
    {
        $mock = new MockClient([StubV2GetRequest::class => MockResponse::make(['id' => 'x'], 200)]);

        $connector = new KudosityV2Connector('my-key');
        $connector->withMockClient($mock);
        $connector->send(new StubV2GetRequest);

        $this->assertSame(
            'https://api.transmitmessage.com/v2/sms/abc-123',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_accepts_a_custom_base_url_and_timeout(): void
    {
        $connector = new KudosityV2Connector('key', 'https://example.test', 60);

        $this->assertSame('https://example.test', $connector->resolveBaseUrl());
        $this->assertSame(60, $connector->getTimeout());

        $connector->setBaseUrl('https://other.test')->setTimeout(5);

        $this->assertSame('https://other.test', $connector->getBaseUrl());
        $this->assertSame(5, $connector->getTimeout());
    }

    public function test_unwraps_a_data_wrapped_envelope(): void
    {
        $json = ['data' => ['id' => 'abc', 'sender' => 'DemoSender'], 'request' => [], 'meta' => []];

        $this->assertSame(['id' => 'abc', 'sender' => 'DemoSender'], DataUnwrapper::ofArray($json));
    }

    public function test_passes_a_flat_envelope_through_untouched(): void
    {
        $json = ['id' => 'abc', 'recipient' => '61491570006', 'sms_count' => '1'];

        $this->assertSame($json, DataUnwrapper::ofArray($json));
    }

    public function test_treats_a_null_or_non_array_data_key_as_flat(): void
    {
        $this->assertSame(['data' => null, 'id' => 'abc'], DataUnwrapper::ofArray(['data' => null, 'id' => 'abc']));
        $this->assertSame(['data' => 'scalar'], DataUnwrapper::ofArray(['data' => 'scalar']));
    }

    public function test_unwraps_straight_from_a_response(): void
    {
        $mock = new MockClient([
            StubV2GetRequest::class => MockResponse::make(['data' => ['id' => 'abc']], 200),
        ]);

        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $this->assertSame(['id' => 'abc'], DataUnwrapper::of($connector->send(new StubV2GetRequest)));
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
