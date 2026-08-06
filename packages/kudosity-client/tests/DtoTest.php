<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Data\V2\RcsCapabilityData;
use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Data\V2\SenderRegistrationData;
use ExpertSystems\Kudosity\Data\V2\SmsListData;
use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\RcsCapabilityCode;
use ExpertSystems\Kudosity\Enums\SenderStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The DTOs, across both response envelopes.
 *
 * The envelope split is the trap: SMS and MMS return the object flat, while
 * WhatsApp, RCS, RCS capabilities and sender registrations wrap it in `data`.
 * Code written against one and reused for the other reads null. Both resolve
 * through `Concerns\UnwrapsData::payload()`, so covering only one side leaves
 * the seam half tested.
 */
#[CoversClass(SmsMessageData::class)]
#[CoversClass(MmsMessageData::class)]
#[CoversClass(WhatsAppMessageData::class)]
#[CoversClass(RcsMessageData::class)]
#[CoversClass(RcsCapabilityData::class)]
#[CoversClass(SmsListData::class)]
#[CoversClass(WebhookData::class)]
#[CoversClass(WebhookFilter::class)]
#[CoversClass(SenderRegistrationData::class)]
final class DtoTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function smsBody(array $overrides = []): array
    {
        // Verbatim from .agents/skills/kudosity-sms/SKILL.md — the FLAT shape.
        return array_merge([
            'id' => '2d2c8fb6-e514-4f5f-9706-0672b0259218',
            'recipient' => '61478038915',
            'recipient_country' => 'AU',
            'sender' => '61481074185',
            'sender_country' => 'AU',
            'message_ref' => 'ncc1701d',
            'message' => 'Report to the ready room!',
            'status' => 'delivered',
            'sms_count' => '1',
            'is_gsm' => true,
            'routed_via' => '',
            'track_links' => true,
            'direction' => 'OUT',
            'created_at' => '2022-03-28T06:12:52.450674000Z',
            'updated_at' => '2022-03-28T06:12:52.450674000Z',
        ], $overrides);
    }

    public function test_sms_count_arrives_as_a_string_and_is_cast(): void
    {
        // Verified live: the API really does send "1", not 1. A consumer
        // adding these up gets string concatenation without the cast.
        $this->assertSame(2, SmsMessageData::fromArray(self::smsBody(['sms_count' => '2']))->smsCount);
    }

    public function test_routed_via_empty_string_normalises_to_null(): void
    {
        // The only deliberate transformation in this DTO, and it shipped once
        // with no assertion at all despite a fixture setting up the exact case.
        $this->assertNull(SmsMessageData::fromArray(self::smsBody())->routedVia);
    }

    public function test_a_populated_routed_via_survives(): void
    {
        // The other side of the normalisation, so it cannot be satisfied by
        // nulling everything.
        $this->assertSame('61481074185', SmsMessageData::fromArray(self::smsBody(['routed_via' => '61481074185']))->routedVia);
    }

    public function test_a_nine_fractional_digit_timestamp_parses(): void
    {
        // Kudosity sends nine digits, which defeats
        // DateTimeImmutable::createFromFormat(RFC3339_EXTENDED, ...).
        $data = SmsMessageData::fromArray(self::smsBody());

        $this->assertNotNull($data->createdAt);
        $this->assertSame('2022-03-28 06:12:52', $data->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_absent_optional_fields_do_not_fatal(): void
    {
        // The minimum the API is documented to return. Anything the SDK layers
        // on top has to tolerate its absence.
        $data = SmsMessageData::fromArray([
            'id' => 'a', 'recipient' => '614', 'sender' => '614',
            'message' => 'm', 'status' => 'queued',
        ]);

        $this->assertNull($data->routedVia);
        $this->assertNull($data->messageRef);
        $this->assertNull($data->createdAt);
        $this->assertSame(MessageStatus::Queued, $data->status);
    }

    public function test_the_status_casing_asymmetry_is_absorbed(): void
    {
        // GET /v2/sms/{id} returns DELIVERED; GET /v2/sms returns delivered for
        // the same message. Both must land on one case.
        $this->assertSame(MessageStatus::Delivered, SmsMessageData::fromArray(self::smsBody(['status' => 'DELIVERED']))->status);
        $this->assertSame(MessageStatus::Delivered, SmsMessageData::fromArray(self::smsBody(['status' => 'delivered']))->status);
    }

    public function test_mms_reports_one_country_where_sms_reports_two(): void
    {
        // A real difference between the two endpoints, easy to paper over by
        // reusing the SMS DTO's shape.
        $mms = MmsMessageData::fromArray([
            'id' => 'a', 'recipient' => '614', 'sender' => '614', 'country' => 'AU',
            'subject' => 's', 'message' => 'm', 'status' => 'PENDING',
            'content_urls' => ['https://e.com/a.jpg'],
            'created_at' => '2022-03-28T06:12:52.450674000Z',
        ]);

        $this->assertSame('AU', $mms->country);
        // PENDING on an MMS is submission status, not failure.
        $this->assertSame(MessageStatus::Pending, $mms->status);
        $this->assertSame(['https://e.com/a.jpg'], $mms->contentUrls);
    }

    public function test_a_data_wrapped_payload_resolves_the_same_as_a_flat_one(): void
    {
        // WhatsApp and RCS wrap; SMS and MMS do not. Asserting the unwrap here
        // rather than only through a resource means a change to UnwrapsData
        // cannot pass by covering one side.
        $whatsapp = WhatsAppMessageData::fromArray([
            'id' => 'b', 'recipient' => '614', 'sender' => '614',
            'content_type' => 'text', 'content' => ['message' => 'hi'],
            'status' => 'queued', 'created_at' => '2022-03-28T06:12:52.450674000Z',
        ]);

        $this->assertSame('b', $whatsapp->id);
        $this->assertSame(MessageStatus::Queued, $whatsapp->status);
    }

    public function test_rcs_carries_its_content_type_and_fallback(): void
    {
        $rcs = RcsMessageData::fromArray([
            'id' => 'c', 'recipient' => '614', 'sender' => 'DemoAgent',
            'content_type' => 'text', 'content' => ['message' => 'hi'],
            'status' => 'queued',
            'sms_fallback' => ['message' => 'fallback', 'sender' => '61481074185'],
            'created_at' => '2022-03-28T06:12:52.450674000Z',
        ]);

        $this->assertSame('DemoAgent', $rcs->sender);
        $this->assertNotNull($rcs->smsFallback);
        $this->assertSame('fallback', $rcs->smsFallback->message);
    }

    public function test_a_list_response_casts_its_string_totals(): void
    {
        // Named for what it asserts: a version of this test once existed that
        // never checked the casts, so deleting them left it green.
        $list = SmsListData::fromArray([
            'smses' => [self::smsBody()],
            'total_records' => '17',
            'total_segments' => '23',
        ]);

        $this->assertSame(17, $list->totalRecords);
        $this->assertSame(23, $list->totalSegments);
        $this->assertCount(1, $list->messages);
        $this->assertInstanceOf(SmsMessageData::class, $list->messages[0]);
    }

    public function test_a_webhook_resource_carries_the_four_undocumented_fields(): void
    {
        $hook = WebhookData::fromArray([
            'id' => 'h', 'name' => 'n', 'url' => 'https://e.com/h',
            'filter' => ['event_type' => ['SMS_STATUS']],
            'rate_limit' => 0, 'is_sandbox' => false,
            'created_at' => '2026-08-05T15:23:11.730743151Z',
            'updated_at' => '2026-08-05T15:23:11.730743151Z',
        ]);

        $this->assertSame(0, $hook->rateLimit);   // 0 means system default
        $this->assertFalse($hook->isSandbox);
        $this->assertNotNull($hook->createdAt);
        $this->assertSame(['SMS_STATUS'], $hook->filter->eventType);
    }

    public function test_an_empty_webhook_filter_is_not_an_error(): void
    {
        // GET /v2/webhook returns {} when empty, omitting the key entirely.
        $this->assertSame([], WebhookFilter::fromArray([])->eventType);
    }

    public function test_a_capability_code_resolves_and_tolerates_novelty(): void
    {
        $known = RcsCapabilityData::fromArray(['phone_number' => '614', 'code' => 'ENABLED']);
        $novel = RcsCapabilityData::fromArray(['phone_number' => '614', 'code' => 'INVENTED_LATER']);

        $this->assertSame(RcsCapabilityCode::Enabled, $known->code);
        $this->assertSame(RcsCapabilityCode::Unknown, $novel->code);
    }

    public function test_a_sender_registration_keeps_its_raw_payload(): void
    {
        // The item shape is unverified by construction — no account has ever
        // completed a registration — so `raw` is retained deliberately and a
        // consumer can reach a field the DTO does not model yet.
        $registration = SenderRegistrationData::fromArray([
            'id' => 'reg-1', 'sender' => '61400000000', 'country' => 'AU',
            'type' => 'PERSONAL_MOBILE_NUMBER', 'status' => 'VERIFIED',
            'something_undocumented' => 'kept',
        ]);

        $this->assertSame('kept', $registration->raw['something_undocumented']);
        // VERIFIED means *provisioning*. Only READY_TO_USE can send.
        $this->assertSame(SenderStatus::Verified, $registration->status);
        $this->assertFalse($registration->status->isReadyToUse());
    }

    /** @return array<string, array{0: class-string}> */
    public static function dtoClasses(): array
    {
        return [
            SmsMessageData::class => [SmsMessageData::class],
            MmsMessageData::class => [MmsMessageData::class],
            WhatsAppMessageData::class => [WhatsAppMessageData::class],
            RcsMessageData::class => [RcsMessageData::class],
            RcsCapabilityData::class => [RcsCapabilityData::class],
            SmsListData::class => [SmsListData::class],
            WebhookData::class => [WebhookData::class],
            WebhookFilter::class => [WebhookFilter::class],
            SenderRegistrationData::class => [SenderRegistrationData::class],
        ];
    }

    /** @param class-string $dto */
    #[DataProvider('dtoClasses')]
    public function test_every_dto_survives_an_empty_payload(string $dto): void
    {
        // A truncated response is not hypothetical — it is what a proxy returns
        // mid-incident. None of these may fatal on the way to reporting it.
        $this->assertInstanceOf($dto, $dto::fromArray([]));
    }

    public function test_every_v2_dto_is_in_the_empty_payload_table(): void
    {
        $onDisk = array_map(
            static fn (string $f): string => 'ExpertSystems\Kudosity\Data\V2\\'.basename($f, '.php'),
            (array) glob(__DIR__.'/../src/Data/V2/*.php'),
        );

        // SmsFallback is a request-side value object rather than a response
        // DTO; it is covered in ValueObjectTest.
        $onDisk = array_values(array_diff($onDisk, ['ExpertSystems\Kudosity\Data\V2\SmsFallback']));

        $missing = array_values(array_diff($onDisk, array_keys(self::dtoClasses())));

        $this->assertSame([], $missing, 'V2 DTOs with no empty-payload row.');
    }
}
