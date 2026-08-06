<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\V2\CheckRcsCapabilitiesRequest;
use ExpertSystems\Kudosity\Requests\V2\ConfirmSenderVerificationRequest;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteSenderPhoneNumberRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\GetMmsRequest;
use ExpertSystems\Kudosity\Requests\V2\GetRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\GetSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\GetWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\GetWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\ListRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListSenderRegistrationsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\RegisterSenderRequest;
use ExpertSystems\Kudosity\Requests\V2\RequestSenderVerificationRequest;
use ExpertSystems\Kudosity\Requests\V2\SendMmsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\SendWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Saloon\Http\Request;

/**
 * Where every V2 request is addressed, and what it refuses to send.
 *
 * A wrong path or a renamed body key is invisible until it reaches the API. The
 * local guards are cheaper still: they fire before the request leaves the
 * process, so an over-long `message_ref` or a phone number in an RCS agent slot
 * costs nothing to discover.
 */
final class RequestShapeTest extends TestCase
{
    private const VALID_ID = '2d2c8fb6-e514-4f5f-9706-0672b0259218';

    /** @return array<string, array{0: Request, 1: string, 2: string}> */
    public static function endpoints(): array
    {
        return [
            // SMS
            'SendSmsV2Request' => [new SendSmsV2Request('m', '61400000000', '61481074185'), 'POST', '/v2/sms'],
            'GetSmsV2Request' => [new GetSmsV2Request(self::VALID_ID), 'GET', '/v2/sms/'.self::VALID_ID],
            'ListSmsV2Request' => [new ListSmsV2Request, 'GET', '/v2/sms'],

            // MMS — note there is no list endpoint.
            'SendMmsRequest' => [new SendMmsRequest('61400000000', '61481074185', ['https://e.com/a.jpg']), 'POST', '/v2/mms'],
            'GetMmsRequest' => [new GetMmsRequest(self::VALID_ID), 'GET', '/v2/mms/'.self::VALID_ID],

            // WhatsApp — messages live under a nested path, unlike SMS and MMS.
            'SendWhatsAppRequest' => [new SendWhatsAppRequest(new TextContent('hi'), '61400000000'), 'POST', '/v2/whatsapp/messages'],
            'GetWhatsAppRequest' => [new GetWhatsAppRequest(self::VALID_ID), 'GET', '/v2/whatsapp/messages/'.self::VALID_ID],
            'ListWhatsAppRequest' => [new ListWhatsAppRequest, 'GET', '/v2/whatsapp/messages'],

            // RCS — capabilities sits beside messages, not under it.
            'SendRcsRequest' => [new SendRcsRequest('m', '61400000000', 'DemoAgent'), 'POST', '/v2/rcs/messages'],
            'GetRcsRequest' => [new GetRcsRequest(self::VALID_ID), 'GET', '/v2/rcs/messages/'.self::VALID_ID],
            'ListRcsRequest' => [new ListRcsRequest, 'GET', '/v2/rcs/messages'],
            'CheckRcsCapabilitiesRequest' => [new CheckRcsCapabilitiesRequest(['61400000000'], 'DemoAgent'), 'POST', '/v2/rcs/capabilities'],

            // Webhooks — singular `webhook`, and the same path for create and list.
            'CreateWebhookRequest' => [new CreateWebhookRequest('rig', 'https://e.com/h'), 'POST', '/v2/webhook'],
            'ListWebhooksRequest' => [new ListWebhooksRequest, 'GET', '/v2/webhook'],
            'GetWebhookRequest' => [new GetWebhookRequest(self::VALID_ID), 'GET', '/v2/webhook/'.self::VALID_ID],
            'UpdateWebhookRequest' => [new UpdateWebhookRequest(self::VALID_ID, 'rig', 'https://e.com/h'), 'PUT', '/v2/webhook/'.self::VALID_ID],
            'DeleteWebhookRequest' => [new DeleteWebhookRequest(self::VALID_ID), 'DELETE', '/v2/webhook/'.self::VALID_ID],

            // Senders
            'RegisterSenderRequest' => [new RegisterSenderRequest('61400000000', 'AU'), 'POST', '/v2/senders/registrations'],
            'ListSenderRegistrationsRequest' => [new ListSenderRegistrationsRequest, 'GET', '/v2/senders/registrations'],
            'RequestSenderVerificationRequest' => [new RequestSenderVerificationRequest('reg-1', '61481074185'), 'POST', '/v2/senders/registrations/reg-1/verifications'],
            'ConfirmSenderVerificationRequest' => [new ConfirmSenderVerificationRequest('reg-1', '123456'), 'POST', '/v2/senders/registrations/reg-1/verifications/confirmation'],
            'DeleteSenderPhoneNumberRequest' => [new DeleteSenderPhoneNumberRequest('61400000000'), 'DELETE', '/v2/senders/phone-numbers/61400000000'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_each_request_addresses_its_documented_endpoint(
        Request $request,
        string $method,
        string $path,
    ): void {
        $this->assertSame($method, $request->getMethod()->value);
        $this->assertSame($path, $request->resolveEndpoint());
    }

    public function test_every_v2_request_class_is_in_the_endpoint_table(): void
    {
        // Without this, adding a request class and forgetting its row leaves
        // the suite green and the new endpoint entirely untested.
        $onDisk = array_map(
            static fn (string $f): string => basename($f, '.php'),
            (array) glob(__DIR__.'/../src/Requests/V2/*.php'),
        );

        $covered = array_keys(self::endpoints());

        sort($onDisk);
        $missing = array_values(array_diff($onDisk, $covered));

        $this->assertSame([], $missing, 'V2 request classes with no endpoint row.');
    }

    public function test_the_table_keys_match_the_classes_they_instantiate(): void
    {
        // The completeness check above compares array KEYS against filenames,
        // so a mistyped key would make it pass while covering the wrong class.
        foreach (self::endpoints() as $key => [$request]) {
            $this->assertSame($key, (new ReflectionClass($request))->getShortName());
        }
    }

    public function test_a_phone_number_in_a_path_segment_is_url_encoded(): void
    {
        // A `+`-prefixed E.164 number in a raw path segment is read as a space.
        $this->assertSame(
            '/v2/senders/phone-numbers/%2B61400000000',
            (new DeleteSenderPhoneNumberRequest('+61400000000'))->resolveEndpoint(),
        );
    }

    public function test_it_rejects_an_over_long_message_ref_before_sending(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/message_ref/');

        new SendSmsV2Request('m', '61400000000', '61481074185', str_repeat('a', 501));
    }

    public function test_a_message_ref_at_the_maximum_is_accepted(): void
    {
        // The boundary from the other side, so the guard cannot be satisfied by
        // rejecting everything.
        $request = new SendSmsV2Request('m', '61400000000', '61481074185', str_repeat('a', 500));

        $this->assertSame('/v2/sms', $request->resolveEndpoint());
    }

    public function test_it_rejects_an_mms_subject_over_twenty_characters(): void
    {
        // 20 is the documented maximum and the SDK enforces it locally.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/subject length \(21\)/');

        new SendMmsRequest('61400000000', '61481074185', ['https://e.com/a.jpg'], str_repeat('a', 21));
    }

    public function test_rcs_rejects_a_phone_number_where_an_agent_id_belongs(): void
    {
        // An RCS sender is a registered agent ID, never a number. Caught before
        // the request leaves the process, and the message must name the agent
        // ID or the operator just retries with another number.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/agent/i');

        new SendRcsRequest('m', '61400000000', '61481074185');
    }

    public function test_rcs_capabilities_rejects_a_phone_number_sender_too(): void
    {
        // The same guard on the other RCS endpoint — a rule enforced on one
        // path and not the other is worse than no rule.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/agent/i');

        new CheckRcsCapabilitiesRequest(['61400000000'], '61481074185');
    }

    public function test_a_webhook_url_must_be_https_unless_explicitly_opted_out(): void
    {
        // Deliveries carry message text and phone numbers and are unsigned, so
        // a plaintext endpoint is readable and forgeable. The API accepts
        // http://; this SDK is deliberately stricter.
        $this->expectException(ValidationException::class);

        new CreateWebhookRequest('rig', 'http://e.com/hook');
    }

    public function test_the_insecure_url_opt_in_is_explicit_and_works(): void
    {
        // If the opt-in did not work, the guard above would be untestable in
        // the one environment that legitimately needs it.
        $request = new CreateWebhookRequest('rig', 'http://e.com/hook', null, null, true);

        $this->assertSame('/v2/webhook', $request->resolveEndpoint());
    }

    public function test_the_send_body_carries_the_documented_keys(): void
    {
        $body = (new SendSmsV2Request('hi', '61478038915', '61481074185', 'order-1', true))->body()?->all();

        $this->assertSame([
            'message' => 'hi',
            'sender' => '61481074185',
            'recipient' => '61478038915',
            'message_ref' => 'order-1',
            'track_links' => true,
        ], $body);
    }

    public function test_optional_body_keys_are_omitted_rather_than_sent_null(): void
    {
        // A null the API did not ask for is not the same as an absent key —
        // some V2 endpoints reject an explicit null where they accept nothing.
        $body = (new SendSmsV2Request('hi', '61478038915', '61481074185'))->body()?->all();

        $this->assertArrayNotHasKey('message_ref', (array) $body);
        $this->assertArrayNotHasKey('track_links', (array) $body);
    }

    public function test_a_webhook_update_sends_the_whole_shape_because_put_is_a_replace(): void
    {
        // PUT /v2/webhook/{id} is a replace, not a patch. Sending a partial
        // body silently drops the filter.
        $body = (new UpdateWebhookRequest(
            self::VALID_ID,
            'renamed',
            'https://e.com/hook',
            new WebhookFilter(['SMS_STATUS']),
        ))->body()?->all();

        $this->assertArrayHasKey('name', (array) $body);
        $this->assertArrayHasKey('url', (array) $body);
        $this->assertArrayHasKey('filter', (array) $body);
    }
}
