<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Concerns\FormatsPhoneNumbers;
use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Requests\CancelSmsRequest;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use ExpertSystems\Kudosity\Resources\BulkSmsResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/BulkSmsResourceTest.php.
 *
 * CancelSmsRequest has no other direct test anywhere in either suite, and
 * KudosityV1Connector's setDefaultFrom()/getDefaultFrom()/
 * setDefaultCountryCode()/getDefaultCountryCode()/hasRequestFailed() are
 * exercised only through this file, so both are named in the covers list
 * rather than assumed to be covered elsewhere. Likewise
 * FormatsPhoneNumbers — PhoneNumberTest.php covers Support\PhoneNumber
 * directly, but not this trait's own thin wrapper methods. SendSmsRequest's
 * defaultBody() is only ever invoked through a real send, which happens here
 * and nowhere else in SendSmsRequestTest.php's builder-only tests, so it is
 * named too.
 */
#[CoversClass(BulkSmsResource::class)]
#[CoversClass(CancelSmsRequest::class)]
#[CoversClass(KudosityV1Connector::class)]
#[CoversClass(SendSmsRequest::class)]
#[CoversTrait(FormatsPhoneNumbers::class)]
final class BulkSmsResourceTest extends TestCase
{
    private static function sendSmsSuccess(): MockResponse
    {
        return MockResponse::make([
            'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
            'message_id' => 7788,
            'send_at' => '2026-08-05 10:00:00',
            'recipients' => 2,
            'cost' => 0.16,
            'sms' => 1,
        ], 200);
    }

    /**
     * @param  array<class-string, MockResponse>  $responses
     */
    private static function bulkResource(array $responses): BulkSmsResource
    {
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient(new MockClient($responses));

        return new BulkSmsResource($connector);
    }

    public function test_sends_to_multiple_comma_separated_recipients(): void
    {
        $mock = new MockClient([SendSmsRequest::class => self::sendSmsSuccess()]);
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient($mock);

        $result = (new BulkSmsResource($connector))->send('Sale starts tomorrow', '61491570006,61491570007');

        $this->assertInstanceOf(SmsData::class, $result);
        $this->assertSame(7788, $result->messageId);
        $this->assertSame(2, $result->recipients);
        $this->assertSame('61491570006,61491570007', $mock->getLastPendingRequest()->body()->all()['to']);
    }

    public function test_sends_to_a_contact_list(): void
    {
        $mock = new MockClient([SendSmsRequest::class => self::sendSmsSuccess()]);
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient($mock);

        (new BulkSmsResource($connector))->sendToList('Sale', 4213644);

        $this->assertSame(4213644, $mock->getLastPendingRequest()->body()->all()['list_id']);
    }

    public function test_schedules_a_send(): void
    {
        $mock = new MockClient([SendSmsRequest::class => self::sendSmsSuccess()]);
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient($mock);

        (new BulkSmsResource($connector))->schedule('Reminder', '61491570006', '2026-09-01 09:00:00');

        $this->assertSame('2026-09-01 09:00:00', $mock->getLastPendingRequest()->body()->all()['send_at']);
    }

    public function test_applies_the_connector_default_sender_and_country_code(): void
    {
        $mock = new MockClient([SendSmsRequest::class => self::sendSmsSuccess()]);
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->setDefaultFrom('MyBrand')->setDefaultCountryCode('AU');
        $connector->withMockClient($mock);

        (new BulkSmsResource($connector))->send('Hi', '0491570006');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('MyBrand', $body['from']);
        $this->assertSame('AU', $body['countrycode']);
    }

    public function test_lets_an_explicit_sender_override_the_connector_default(): void
    {
        $mock = new MockClient([SendSmsRequest::class => self::sendSmsSuccess()]);
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->setDefaultFrom('MyBrand');
        $connector->withMockClient($mock);

        (new BulkSmsResource($connector))->send('Hi', '61491570006', from: 'Override');

        $this->assertSame('Override', $mock->getLastPendingRequest()->body()->all()['from']);
    }

    public function test_passes_the_request_to_the_configure_closure_after_defaults_are_applied(): void
    {
        $mock = new MockClient([SendSmsRequest::class => self::sendSmsSuccess()]);
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->setDefaultFrom('MyBrand');
        $connector->withMockClient($mock);

        (new BulkSmsResource($connector))->send(
            'Hi',
            '61491570006',
            configure: fn (SendSmsRequest $r) => $r->from('Override')->validity(60)
        );

        $body = $mock->getLastPendingRequest()->body()->all();

        // Only passes if configure() genuinely runs after applyDefaults() — if it
        // ran first, the connector default would clobber the closure's override.
        $this->assertSame('Override', $body['from']);
        $this->assertSame(60, $body['validity']);
    }

    public function test_reports_whether_a_cancel_succeeded(): void
    {
        $resource = self::bulkResource([
            CancelSmsRequest::class => MockResponse::make(['error' => ['code' => 'SUCCESS']], 200),
        ]);

        $this->assertTrue($resource->cancel(7788));
    }

    public function test_exposes_the_offline_phone_helpers(): void
    {
        $resource = self::bulkResource([]);

        $this->assertTrue($resource->isValidNumber('61491570006'));
        $this->assertTrue($resource->isValidSenderId('MyBrand'));
        $this->assertSame('61491570006', $resource->formatNumberLocal('0491570006', 'AU'));
    }
}
