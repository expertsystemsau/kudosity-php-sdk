<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Data\V2\SmsListData;
use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\GetSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\ListSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;
use ExpertSystems\Kudosity\Resources\SmsV2Resource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/V2SmsTest.php.
 *
 * Resource-level behaviour for single-recipient SMS: the flat response
 * envelope, string->int casts on both the message (`sms_count`) and the list
 * (`total_records`/`total_segments`), the QUEUED/UNKNOWN status-filter
 * rejections and the page-based paginator. `RequestShapeTest` already pins
 * this class's endpoint, method and body shape; this file is the resource
 * behaviour `RequestShapeTest` deliberately does not assert.
 *
 * Three tests already existed elsewhere and are not duplicated here:
 *
 * - The root suite's "rejects a message_ref longer than the documented 500
 *   characters" is not re-ported. `RequestShapeTest::
 *   test_it_rejects_an_over_long_message_ref_before_sending` already covers
 *   this exact guard on this exact class (plus the complementary
 *   accept-at-500 boundary case, which the root file never had).
 * - `DtoTest.php`'s `test_sms_count_arrives_as_a_string_and_is_cast`,
 *   `test_routed_via_empty_string_normalises_to_null`,
 *   `test_a_populated_routed_via_survives` and
 *   `test_a_list_response_casts_its_string_totals` come out of that file:
 *   each asserted the identical fact this file now asserts, and the versions
 *   here are the same strength or stronger (the list-cast test here hydrates
 *   two messages, not one, so a hydration bug affecting only the second item
 *   would previously have passed). `DtoTest`'s `#[CoversClass]` list for
 *   `SmsMessageData`/`SmsListData` is unchanged: both DTOs still need a row
 *   in its empty-payload completeness sweep.
 * - `RequestShapeTest::test_optional_body_keys_are_omitted_rather_than_sent_null`
 *   comes out of that file: it asserted the identical two-key omission this
 *   file asserts, and the version here additionally drives
 *   `SmsV2Resource::send()`, which the request-only version did not.
 *
 * `DtoTest`'s `test_a_nine_fractional_digit_timestamp_parses` stays — it
 * additionally asserts the exact formatted value, which this file's ported
 * version (instanceof + not-null only, faithful to the root test) does not.
 * Removing it to avoid the overlap would weaken coverage, so both stand.
 */
#[CoversClass(SmsV2Resource::class)]
#[CoversClass(SendSmsV2Request::class)]
#[CoversClass(GetSmsV2Request::class)]
#[CoversClass(ListSmsV2Request::class)]
#[CoversClass(SmsMessageData::class)]
#[CoversClass(SmsListData::class)]
final class V2SmsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function smsSendBody(array $overrides = []): array
    {
        // Verbatim from .agents/skills/kudosity-sms/SKILL.md — note the FLAT envelope.
        return array_merge([
            'id' => '2d2c8fb6-e514-4f5f-9706-0672b0259218',
            'recipient' => '61491570018',
            'recipient_country' => 'AU',
            'sender' => '61491570017',
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

    /** @param array<class-string, MockResponse> $responses */
    private static function smsResource(array $responses): SmsV2Resource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return new SmsV2Resource($connector);
    }

    public function test_sends_a_single_recipient_sms_and_returns_a_typed_dto(): void
    {
        $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(self::smsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $sms = (new SmsV2Resource($connector))->send('Report to the ready room!', '61491570018', '61491570017');

        $this->assertInstanceOf(SmsMessageData::class, $sms);
        $this->assertSame('2d2c8fb6-e514-4f5f-9706-0672b0259218', $sms->id);
        $this->assertSame(MessageStatus::Delivered, $sms->status);
        $this->assertSame('AU', $sms->recipientCountry);

        $this->assertSame([
            'message' => 'Report to the ready room!',
            'sender' => '61491570017',
            'recipient' => '61491570018',
        ], $mock->getLastPendingRequest()->body()->all());
    }

    public function test_casts_the_string_sms_count_to_an_int(): void
    {
        // The API returns "1", not 1. Arithmetic on the raw value silently
        // concatenates — "1" + 1 gives "11". Folded from DtoTest.php's
        // test_sms_count_arrives_as_a_string_and_is_cast, which asserted the
        // identical fact directly against SmsMessageData::fromArray(); this
        // version additionally drives SmsV2Resource::send().
        $sms = self::smsResource([SendSmsV2Request::class => MockResponse::make(self::smsSendBody(['sms_count' => '3']), 200)])
            ->send('Hi', '61491570018', '61491570017');

        $this->assertSame(3, $sms->smsCount);
        $this->assertIsInt($sms->smsCount);
    }

    public function test_omits_optional_fields_from_the_body_rather_than_sending_nulls(): void
    {
        // Folded from RequestShapeTest.php's
        // test_optional_body_keys_are_omitted_rather_than_sent_null, which
        // asserted the same two keys directly against the request; this
        // version additionally drives SmsV2Resource::send().
        $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(self::smsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new SmsV2Resource($connector))->send('Hi', '61491570018', '61491570017');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertArrayNotHasKey('message_ref', $body);
        $this->assertArrayNotHasKey('track_links', $body);
    }

    public function test_sends_message_ref_and_track_links_when_given(): void
    {
        $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(self::smsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new SmsV2Resource($connector))->send('Hi', '61491570018', '61491570017', messageRef: 'order-1', trackLinks: true);

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('order-1', $body['message_ref']);
        $this->assertTrue($body['track_links']);
    }

    // A "rejects a message_ref longer than the documented 500 characters"
    // test is deliberately not ported here — see the class docblock.

    public function test_reads_one_sms_by_id(): void
    {
        $mock = new MockClient([GetSmsV2Request::class => MockResponse::make(self::smsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $sms = (new SmsV2Resource($connector))->get('2d2c8fb6-e514-4f5f-9706-0672b0259218');

        $this->assertSame('2d2c8fb6-e514-4f5f-9706-0672b0259218', $sms->id);
        $this->assertSame(
            'https://api.transmitmessage.com/v2/sms/2d2c8fb6-e514-4f5f-9706-0672b0259218',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_sends_no_body_on_the_get_reader(): void
    {
        // Phase 2 split the request bases precisely so readers do not ship a
        // JSON body; a V2 GET carrying one is stripped or rejected by some
        // gateways.
        $mock = new MockClient([GetSmsV2Request::class => MockResponse::make(self::smsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new SmsV2Resource($connector))->get('abc');

        $pending = $mock->getLastPendingRequest();

        $this->assertNull($pending->headers()->get('Content-Type'));
        $this->assertSame('', (string) $pending->body());
    }

    public function test_turns_a_404_into_a_not_found_exception(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('SMS not found');

        self::smsResource([GetSmsV2Request::class => MockResponse::make(['error' => 'SMS not found'], 404)])->get('nope');
    }

    public function test_pages_the_list_endpoint_and_hydrates_each_item(): void
    {
        // The paginator reads items straight off the response JSON (Phase 2's
        // design — createDtoFromResponse() is never invoked through list()),
        // so this covers paging and item hydration only. The
        // total_records/total_segments string->int cast is proven separately,
        // directly against SmsListData::fromArray(), below.
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient([
            ListSmsV2Request::class => MockResponse::make([
                'smses' => [self::smsSendBody(), self::smsSendBody(['id' => 'second'])],
                'total_records' => '2',
                'total_segments' => '2',
            ], 200),
        ]));

        $items = iterator_to_array((new SmsV2Resource($connector))->list()->items());

        $this->assertCount(2, $items);
        $this->assertSame('2d2c8fb6-e514-4f5f-9706-0672b0259218', $items[0]['id']);
    }

    public function test_casts_sms_list_data_total_records_and_total_segments_strings_to_int_and_hydrates_messages(): void
    {
        // GET /v2/sms answers "total_records": "2", not 2 — same trap as
        // sms_count. Proven here directly against the DTO, decoupled from the
        // paginator, since list() never reaches createDtoFromResponse().
        // Folded from DtoTest.php's test_a_list_response_casts_its_string_totals,
        // which asserted the same cast with a single hydrated message; this
        // version hydrates two, so a bug affecting only the second item would
        // not have failed the older test.
        $list = SmsListData::fromArray([
            'smses' => [self::smsSendBody(), self::smsSendBody(['id' => 'second'])],
            'total_records' => '2',
            'total_segments' => '3',
        ]);

        $this->assertSame(2, $list->totalRecords);
        $this->assertIsInt($list->totalRecords);
        $this->assertSame(3, $list->totalSegments);
        $this->assertIsInt($list->totalSegments);
        $this->assertCount(2, $list->messages);
        $this->assertInstanceOf(SmsMessageData::class, $list->messages[0]);
        $this->assertInstanceOf(SmsMessageData::class, $list->messages[1]);
    }

    public function test_passes_list_filters_through_as_query_parameters(): void
    {
        $mock = new MockClient([
            ListSmsV2Request::class => MockResponse::make(['smses' => [self::smsSendBody()], 'total_records' => '1'], 200),
        ]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        iterator_to_array((new SmsV2Resource($connector))->list(
            status: MessageStatus::Delivered,
            recipient: '61491570018',
            sender: '61491570017',
            messageRef: 'order-1',
            direction: 'OUT',
        )->items());

        $query = $mock->getLastPendingRequest()->query();

        $this->assertSame('DELIVERED', $query->get('status'));
        $this->assertSame('61491570018', $query->get('recipient'));
        $this->assertSame('61491570017', $query->get('sender'));
        $this->assertSame('order-1', $query->get('message_ref'));
        $this->assertSame('OUT', $query->get('direction'));
    }

    public function test_sends_no_filter_query_parameters_when_none_are_given(): void
    {
        $mock = new MockClient([
            ListSmsV2Request::class => MockResponse::make(['smses' => [self::smsSendBody()], 'total_records' => '1'], 200),
        ]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        iterator_to_array((new SmsV2Resource($connector))->list()->items());

        $query = $mock->getLastPendingRequest()->query();

        $this->assertNull($query->get('status'));
        $this->assertNull($query->get('recipient'));
        $this->assertNull($query->get('sender'));
        $this->assertNull($query->get('message_ref'));
        $this->assertNull($query->get('direction'));
    }

    public function test_rejects_queued_as_a_status_filter_because_get_v2_sms_does_not_document_it(): void
    {
        // MessageStatus is deliberately the union of three vocabularies (SMS,
        // WhatsApp/RCS, and the SDK's own UNKNOWN sentinel). QUEUED belongs to
        // WhatsApp/RCS only — a caller who reads it off a WhatsApp message and
        // feeds it back into sms()->list() would otherwise have it silently
        // ignored by the API rather than rejected.
        try {
            new ListSmsV2Request(status: MessageStatus::Queued);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('FIELD_INVALID', $e->getErrorCode());
            $this->assertStringContainsString('status must be one of', $e->getMessage());
        }
    }

    public function test_rejects_unknown_as_a_status_filter_because_it_is_a_sentinel_not_an_api_value(): void
    {
        try {
            new ListSmsV2Request(status: MessageStatus::Unknown);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('FIELD_INVALID', $e->getErrorCode());
            $this->assertStringContainsString('status must be one of', $e->getMessage());
        }
    }

    public function test_accepts_a_documented_status_value(): void
    {
        $this->assertInstanceOf(ListSmsV2Request::class, new ListSmsV2Request(status: MessageStatus::Delivered));
    }

    public function test_declares_itself_paged_so_the_connector_picks_the_right_paginator(): void
    {
        $this->assertInstanceOf(PaginatesV2Pages::class, new ListSmsV2Request);
        $this->assertSame('smses', (new ListSmsV2Request)->paginationItemsKey());
    }

    public function test_parses_the_nine_fractional_digit_created_at_timestamp_that_rfc3339_extended_rejects(): void
    {
        // DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, ...)
        // expects exactly six fractional digits; the API sends nine
        // ("...450674000Z"). The permissive `new DateTimeImmutable($value)`
        // parse this DTO uses tolerates it instead of silently dropping the field.
        $sms = SmsMessageData::fromArray(self::smsSendBody(['created_at' => '2022-03-28T06:12:52.450674000Z']));

        $this->assertInstanceOf(DateTimeImmutable::class, $sms->createdAt);
        $this->assertNotNull($sms->createdAt);
    }

    public function test_returns_null_for_a_malformed_created_at_rather_than_throwing(): void
    {
        $sms = SmsMessageData::fromArray(self::smsSendBody(['created_at' => 'not-a-date']));

        $this->assertNull($sms->createdAt);
    }

    public function test_normalises_an_empty_routed_via_to_null_because_the_api_sends_empty_string_for_no_shared_number(): void
    {
        // Folded from DtoTest.php's test_routed_via_empty_string_normalises_to_null.
        $sms = SmsMessageData::fromArray(self::smsSendBody(['routed_via' => '']));

        $this->assertNull($sms->routedVia);
    }

    public function test_keeps_a_real_routed_via_value(): void
    {
        // Folded from DtoTest.php's test_a_populated_routed_via_survives.
        $sms = SmsMessageData::fromArray(self::smsSendBody(['routed_via' => '61491570017']));

        $this->assertSame('61491570017', $sms->routedVia);
    }
}
