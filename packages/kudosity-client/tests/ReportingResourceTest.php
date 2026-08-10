<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Requests\GetSmsDeliveryStatusRequest;
use ExpertSystems\Kudosity\Requests\GetSmsRequest;
use ExpertSystems\Kudosity\Resources\ReportingResource;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * ReportingResource had zero test coverage before this file — confirmed by
 * `ls tests/` and a grep across the suite. Live consumer validation
 * (2026-08-07/08, .superpowers/sdd/2026-08-07-sdk-v2-live-validation/)
 * found getDeliveryStatus() always fails two independent ways at once:
 *
 * 1. GetSmsDeliveryStatusRequest sends the recipient under 'mobile'; the
 *    live API requires 'msisdn' and answers {"error":{"code":"FIELD_EMPTY",
 *    "description":"Field msisdn is required"}} with HTTP 400.
 * 2. Every non-paginated ReportingResource method calls Saloon's
 *    Response::dtoOrFail() directly instead of the sendAndDto() pattern
 *    (throw()+dto()) the rest of the SDK's V2 resources and BulkSmsResource
 *    use. dtoOrFail() wraps the real KudosityException in a generic
 *    \LogicException when the response has failed — confirmed live, and
 *    reproduced here without a live call by mocking the exact error body
 *    above. A caller following the documented `@throws KudosityException`
 *    contract does not catch \LogicException.
 *
 * Both defects are independent: fixing only the field name still leaves a
 * LogicException surfacing on any *other* V1 error (rate limit, auth, a
 * genuinely invalid message id); fixing only the routing still fails the
 * live call because the API never receives the field it requires.
 */
#[CoversClass(ReportingResource::class)]
#[CoversClass(GetSmsDeliveryStatusRequest::class)]
final class ReportingResourceTest extends TestCase
{
    /**
     * @param  array<class-string, MockResponse>  $responses
     */
    private static function resource(array $responses): array
    {
        $connector = new KudosityV1Connector('key', 'secret');
        $mock = new MockClient($responses);
        $connector->withMockClient($mock);

        return [new ReportingResource($connector), $mock];
    }

    public function test_get_delivery_status_sends_msisdn_not_mobile(): void
    {
        [$reporting, $mock] = self::resource([
            GetSmsDeliveryStatusRequest::class => MockResponse::make([
                'stats' => [
                    'message_id' => 1718653636,
                    'sender_id' => '61491570017',
                    'mobile' => '61491570099',
                    'send_at' => '2026-08-07 15:36:01',
                    'datetime' => '2026-08-07 15:36:00',
                    'status' => 'delivered',
                    'message' => 'Order 9931 shipped.',
                ],
                'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
            ], 200),
        ]);

        $reporting->getDeliveryStatus(1718653636, '61491570099');

        $body = $mock->getLastPendingRequest()->body()->all();
        $this->assertArrayHasKey('msisdn', $body);
        $this->assertSame('61491570099', $body['msisdn']);
        $this->assertArrayNotHasKey('mobile', $body);
    }

    public function test_get_delivery_status_throws_kudosity_exception_not_logic_exception(): void
    {
        // The live-observed body, reproduced exactly: HTTP 400, V1's
        // error.code envelope, no 'stats' key at all.
        [$reporting] = self::resource([
            GetSmsDeliveryStatusRequest::class => MockResponse::make([
                'error' => ['code' => 'FIELD_EMPTY', 'description' => 'Field msisdn is required'],
            ], 400),
        ]);

        try {
            $reporting->getDeliveryStatus(1718653636, '61491570099');
            $this->fail('Expected a KudosityException to be thrown.');
        } catch (LogicException $e) {
            $this->fail('getDeliveryStatus() threw Saloon\'s LogicException instead of a KudosityException: '.$e->getMessage());
        } catch (KudosityException $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertSame('Field msisdn is required', $e->getMessage());
            $this->assertSame('FIELD_EMPTY', $e->getErrorCode());
        }
    }

    /**
     * getMessage() reproduces the same routing defect via a different V1
     * error (NOT_IMPLEMENTED, HTTP 404 — the shape get-sms.json would answer
     * with for a message id the account doesn't own) — proving the fix is
     * general to ReportingResource's dtoOrFail() usage, not specific to the
     * one call site defect #7 was first confirmed on.
     */
    public function test_get_message_throws_kudosity_exception_not_logic_exception(): void
    {
        [$reporting] = self::resource([
            GetSmsRequest::class => MockResponse::make([
                'error' => ['code' => 'NOT_IMPLEMENTED', 'description' => 'The method you are requesting is unsupported.'],
            ], 404),
        ]);

        try {
            $reporting->getMessage(1);
            $this->fail('Expected a KudosityException to be thrown.');
        } catch (LogicException $e) {
            $this->fail('getMessage() threw Saloon\'s LogicException instead of a KudosityException: '.$e->getMessage());
        } catch (KudosityException $e) {
            $this->assertSame('The method you are requesting is unsupported.', $e->getMessage());
            $this->assertSame('NOT_IMPLEMENTED', $e->getErrorCode());
        }
    }
}
