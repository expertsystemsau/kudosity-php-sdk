<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\AccessDeniedException;
use ExpertSystems\Kudosity\Exceptions\AuthenticationException;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\ServerException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\GetSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;
use ExpertSystems\Kudosity\Resources\SmsV2Resource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The V2 transport: how a request is addressed and authenticated, and how a
 * failure becomes a typed exception.
 *
 * These run on PHP 8.2 where the root suite cannot, and against this package's
 * own vendor/ where Laravel is absent.
 */
#[CoversClass(KudosityV2Connector::class)]
#[CoversClass(KudosityException::class)]
final class V2TransportTest extends TestCase
{
    public function test_v2_authenticates_with_the_api_key_header_and_never_the_secret(): void
    {
        // The single most consequential difference between the two APIs. V2
        // takes the key in a header and has no use for the secret at all;
        // leaking the secret onto a V2 request would send a credential to an
        // endpoint that never needs it.
        $connector = new KudosityV2Connector('key-abc');

        $headers = $connector->headers()->all();

        $this->assertSame('key-abc', $headers['x-api-key']);
        $this->assertArrayNotHasKey('Authorization', $headers);

        $serialised = json_encode($headers, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', strtolower((string) $serialised));
    }

    public function test_the_v2_connector_has_no_secret_parameter_at_all(): void
    {
        // Stronger than checking the headers: the class cannot be handed a
        // secret even by mistake, so no future edit can start forwarding one.
        $parameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(KudosityV2Connector::class, '__construct'))->getParameters(),
        );

        $this->assertSame(['apiKey', 'baseUrl', 'timeout'], $parameters);
    }

    public function test_the_two_apis_keep_their_own_hostnames(): void
    {
        // Neither is a kudosity.com domain and neither was renamed. A brand
        // sweep has corrupted the V1 one once already, silently, because the
        // dots in `api.transmitsms.com` are word boundaries.
        $this->assertSame('https://api.transmitsms.com', (new KudosityV1Connector('k', 's'))->resolveBaseUrl());
        $this->assertSame('https://api.transmitmessage.com', (new KudosityV2Connector('k'))->resolveBaseUrl());
    }

    public function test_it_maps_an_rfc_9457_problem_document_onto_a_typed_exception(): void
    {
        // The problem document is nested under `error`, not at the top level —
        // the shape captured from the live API. A test written against the bare
        // RFC 9457 layout passes its status assertion and silently finds zero
        // issues, which is how this one was written the first time.
        $resource = $this->smsResource([
            MockResponse::make([
                'error' => [
                    'type' => 'https://developers.kudosity.com/reference/errors#validation',
                    'title' => 'Validation failed',
                    'status' => 422,
                    'issues' => [
                        ['name' => 'sender', 'message' => 'Sender not found'],
                        ['name' => 'recipient', 'message' => 'must be E.164'],
                    ],
                ],
            ], 422),
        ]);

        try {
            $resource->send(message: 'hi', to: '61491570006', from: 'nope');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            // Per-field issues are the whole point of RFC 9457 here: without
            // them the caller gets "validation failed" and no way to know what.
            $this->assertCount(2, $e->getIssues());
            $this->assertSame('sender', $e->getIssues()[0]->name);
            $this->assertStringContainsString('Sender not found', $e->getMessage());
        }
    }

    /** @return array<string, array{0: int, 1: class-string}> */
    public static function statusMappings(): array
    {
        return [
            '400 is a validation failure' => [400, ValidationException::class],
            '401 is bad credentials' => [401, AuthenticationException::class],
            '403 is a resource you do not own' => [403, AccessDeniedException::class],
            '404 is a missing resource' => [404, NotFoundException::class],
            '422 is a validation failure' => [422, ValidationException::class],
            '429 is rate limiting' => [429, RateLimitException::class],
            '500 is theirs, not yours' => [500, ServerException::class],
            '503 is theirs, not yours' => [503, ServerException::class],
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('statusMappings')]
    public function test_it_maps_each_status_onto_its_exception(int $status, string $expected): void
    {
        // 5xx separated from 4xx because the caller's response differs: a 4xx
        // means fix the request, a 5xx means retry later.
        $resource = $this->smsResource([MockResponse::make(['title' => 'nope'], $status)]);

        $this->expectException($expected);

        $resource->get('2d2c8fb6-e514-4f5f-9706-0672b0259218');
    }

    public function test_it_survives_an_error_body_that_is_not_json(): void
    {
        // A gateway or proxy in front of the API returns HTML, not a problem
        // document. Parsing must not fatal on the way to reporting the failure.
        $resource = $this->smsResource([
            MockResponse::make('<html><body>502 Bad Gateway</body></html>', 502),
        ]);

        $this->expectException(ServerException::class);

        $resource->get('2d2c8fb6-e514-4f5f-9706-0672b0259218');
    }

    public function test_it_reads_the_plain_error_body_the_webhook_endpoints_use(): void
    {
        // A third error shape: not RFC 9457, just {"error": "..."}. Observed on
        // the webhook endpoints.
        $resource = $this->smsResource([MockResponse::make(['error' => 'webhook not found'], 404)]);

        try {
            $resource->get('missing');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('webhook not found', $e->getMessage());
        }
    }

    public function test_a_send_response_is_flat_rather_than_data_wrapped(): void
    {
        // SMS and MMS return the object at the top level; WhatsApp, RCS and the
        // sender endpoints wrap it in `data`. Code written against one shape and
        // reused for the other reads null.
        $resource = $this->smsResource([
            MockResponse::make([
                'id' => '2d2c8fb6-e514-4f5f-9706-0672b0259218',
                'recipient' => '61491570018',
                'sender' => '61491570017',
                'message' => 'Report to the ready room!',
                'status' => 'delivered',
                'sms_count' => '1',
                'routed_via' => '',
                'created_at' => '2022-03-28T06:12:52.450674000Z',
            ], 200),
        ]);

        $sent = $resource->send(message: 'Report to the ready room!', to: '61491570018', from: '61491570017');

        $this->assertSame('2d2c8fb6-e514-4f5f-9706-0672b0259218', $sent->id());
        // Verified live: sms_count really does arrive as a JSON string.
        $this->assertSame(1, $sent->smsCount);
        // Also verified live: routed_via arrives as "" and normalises to null.
        $this->assertNull($sent->routedVia);
        // Two segments to one person is still one recipient.
        $this->assertSame(1, $sent->recipientCount());
    }

    public function test_message_status_is_case_insensitive_because_the_api_is_not_consistent(): void
    {
        // Load-bearing, not defensive. GET /v2/sms/{id} returns DELIVERED while
        // GET /v2/sms returns delivered for the same message, and webhooks send
        // upper case while the send endpoints send lower.
        $this->assertSame(MessageStatus::Delivered, MessageStatus::fromApi('DELIVERED'));
        $this->assertSame(MessageStatus::Delivered, MessageStatus::fromApi('delivered'));
        $this->assertSame(MessageStatus::Delivered, MessageStatus::fromApi('Delivered'));
    }

    public function test_an_unrecognised_status_resolves_to_unknown_rather_than_throwing(): void
    {
        // A client reading its own message history must not break because
        // Kudosity added a status after this release.
        $this->assertSame(MessageStatus::Unknown, MessageStatus::fromApi('TELEPORTED'));
    }

    public function test_it_sends_the_documented_body_shape(): void
    {
        $mock = new MockClient([
            MockResponse::make([
                'id' => 'x', 'recipient' => '61491570018', 'sender' => '61491570017',
                'message' => 'hi', 'status' => 'queued', 'sms_count' => '1',
                'created_at' => '2022-03-28T06:12:52.450674000Z',
            ], 200),
        ]);

        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $connector->send(new SendSmsV2Request(
            message: 'hi',
            recipient: '61491570018',
            sender: '61491570017',
            messageRef: 'order-1',
            trackLinks: true,
        ));

        $request = $mock->getLastPendingRequest();
        $this->assertNotNull($request);

        /** @var array<string, mixed> $body */
        $body = $request->body()?->all();

        $this->assertSame('hi', $body['message']);
        $this->assertSame('61491570018', $body['recipient']);
        $this->assertSame('61491570017', $body['sender']);
        $this->assertSame('order-1', $body['message_ref']);
        $this->assertTrue($body['track_links']);
        $this->assertSame('/v2/sms', (new SendSmsV2Request('m', 'r', 's'))->resolveEndpoint());
    }

    public function test_a_read_request_carries_no_body(): void
    {
        // GET readers extend KudosityV2Request and write requests extend
        // KudosityV2BodyRequest, so a reader cannot inherit a JSON body.
        $this->assertFalse(method_exists(new GetSmsV2Request('x'), 'body'));
    }

    /** @param array<int, MockResponse> $responses */
    private function smsResource(array $responses): SmsV2Resource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return new SmsV2Resource($connector);
    }
}
