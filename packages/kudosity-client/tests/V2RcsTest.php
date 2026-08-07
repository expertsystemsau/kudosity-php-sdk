<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\GuardsMessageRef;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Data\V2\RcsCapabilityData;
use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\RcsCapabilityCode;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\CheckRcsCapabilitiesRequest;
use ExpertSystems\Kudosity\Requests\V2\GetRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendRcsRequest;
use ExpertSystems\Kudosity\Resources\RcsResource;
use ExpertSystems\Kudosity\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/V2RcsTest.php.
 *
 * Resource-level send/read/list/capabilities behaviour for RcsResource, plus
 * RcsMessageData, RcsCapabilityData and RcsCapabilityCode. `RequestShapeTest`
 * pins this endpoint family's paths and methods, and its own agent-ID guard
 * on both `SendRcsRequest` and `CheckRcsCapabilitiesRequest` — different
 * inputs and a plain `/agent/i` message match rather than this file's exact
 * substrings and its own `PhoneNumber::isValid()` premise checks, so nothing
 * here is folded out of it. `DtoTest`'s `test_rcs_carries_its_content_type_and_fallback`
 * checks `$rcs->sender` together with a fallback in one combined fixture,
 * which this file's granular per-behaviour tests below never do in
 * combination, so that stays too — the real overlap between the three files
 * is narrow, as expected.
 *
 * `EnumToleranceTest`'s shared `fromApi()`-tolerance sweep already resolves
 * one unrecognised `RcsCapabilityCode` string to `Unknown`; this file's own
 * "resolves an unrecognised code to Unknown" below duplicates that single
 * fact but stays, because pulling one row out of that sweep's uniform
 * per-enum table would be a bigger change to a shared fixture for a smaller
 * gain than the fold is worth here.
 */
#[CoversClass(RcsResource::class)]
#[CoversClass(SendRcsRequest::class)]
#[CoversClass(GetRcsRequest::class)]
#[CoversClass(ListRcsRequest::class)]
#[CoversClass(CheckRcsCapabilitiesRequest::class)]
#[CoversClass(RcsMessageData::class)]
#[CoversClass(RcsCapabilityData::class)]
#[CoversClass(RcsCapabilityCode::class)]
#[CoversTrait(GuardsMessageRef::class)]
final class V2RcsTest extends TestCase
{
    /**
     * The message object verbatim from .agents/skills/kudosity-rcs/SKILL.md's
     * send response example, extended with the fields GET/list also carry.
     *
     * @return array<string, mixed>
     */
    private static function rcsMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => '6fdae71c-dad7-4c36-9734-a69693ecf3b4',
            'message_ref' => 'order-7782',
            'sender' => 'DemoSender',
            'recipient' => '61491570014',
            'content_type' => 'text',
            'created_at' => '2026-07-29T00:00:00Z',
        ], $overrides);
    }

    /**
     * The send response — WRAPPED in a `data` envelope, like WhatsApp.
     *
     * @return array<string, mixed>
     */
    private static function rcsSendBody(array $overrides = []): array
    {
        return ['data' => self::rcsMessage($overrides)];
    }

    /**
     * The list response — items at `data.messages`, cursor state at `meta.pagination`.
     *
     * @return array<string, mixed>
     */
    private static function rcsListBody(): array
    {
        return [
            'data' => [
                'messages' => [self::rcsMessage(), self::rcsMessage(['id' => 'second'])],
            ],
            'meta' => [
                'pagination' => [
                    'has_next' => false,
                    'next_cursor' => '',
                ],
            ],
        ];
    }

    /**
     * The two-result capabilities response verbatim from the skill.
     *
     * @return array<string, mixed>
     */
    private static function rcsCapabilitiesBody(): array
    {
        return [
            'data' => [
                'results' => [
                    ['phone_number' => '61491570014', 'code' => 'ENABLED'],
                    ['phone_number' => '61491570156', 'code' => 'UNREACHABLE'],
                ],
            ],
        ];
    }

    /** @param array<class-string, MockResponse> $responses */
    private static function rcsResource(array $responses): RcsResource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return new RcsResource($connector);
    }

    // ---------------------------------------------------------------------------
    // Content nesting
    // ---------------------------------------------------------------------------

    public function test_nests_the_message_under_content_text_message_with_a_text_content_type(): void
    {
        $mock = new MockClient([SendRcsRequest::class => MockResponse::make(self::rcsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new RcsResource($connector))->send('Your order has shipped.', '61491570014', 'DemoSender');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('text', $body['content_type']);
        $this->assertSame(['text' => ['message' => 'Your order has shipped.']], $body['content']);
        $this->assertArrayNotHasKey('message', $body);
    }

    public function test_sends_the_sender_as_the_agent_id_and_the_recipient_in_international_form(): void
    {
        $mock = new MockClient([SendRcsRequest::class => MockResponse::make(self::rcsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new RcsResource($connector))->send('Hi', '+61 491 570 014', 'DemoSender');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('DemoSender', $body['sender']);
        $this->assertSame('61491570014', $body['recipient']);
    }

    // ---------------------------------------------------------------------------
    // The agent-ID trap
    // ---------------------------------------------------------------------------

    public function test_rejects_a_sender_that_looks_like_a_phone_number_naming_the_agent_id_rule(): void
    {
        // A plausible E.164 value that PhoneNumber::isValid() actually accepts:
        // 11 digits once cleaned, no leading zero. If this stopped satisfying
        // isValid() for an unrelated reason, this test would prove nothing.
        $this->assertTrue(PhoneNumber::isValid('+61491570010'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agent ID');

        new SendRcsRequest('Hi', '61491570014', '+61491570010');
    }

    public function test_accepts_an_alphanumeric_agent_id(): void
    {
        $this->assertInstanceOf(SendRcsRequest::class, new SendRcsRequest('Hi', '61491570014', 'DemoSender'));
    }

    public function test_accepts_a_numeric_agent_id_that_is_not_a_valid_phone_number(): void
    {
        // The docs permit numeric agent IDs. This one is too short to satisfy
        // PhoneNumber::isValid() (which requires 7-15 digits), so the rule is
        // "looks like a valid phone number", not "contains only digits".
        $this->assertFalse(PhoneNumber::isValid('12345'));

        $this->assertInstanceOf(SendRcsRequest::class, new SendRcsRequest('Hi', '61491570014', '12345'));
    }

    // ---------------------------------------------------------------------------
    // Message length — up to 3072 characters, full UTF-8
    // ---------------------------------------------------------------------------

    public function test_accepts_a_message_exactly_at_the_3072_character_maximum(): void
    {
        $this->assertInstanceOf(
            SendRcsRequest::class,
            new SendRcsRequest(str_repeat('a', 3072), '61491570014', 'DemoSender'),
        );
    }

    public function test_rejects_a_message_one_character_over_the_3072_maximum(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('3072');

        new SendRcsRequest(str_repeat('a', 3073), '61491570014', 'DemoSender');
    }

    public function test_counts_multi_byte_characters_rather_than_bytes_against_the_3072_limit(): void
    {
        // 'é' is two bytes in UTF-8. strlen() would count 6144 and wrongly reject
        // this; mb_strlen() counts 3072 characters and must accept it.
        $message = str_repeat('é', 3072);

        $this->assertSame(3072, mb_strlen($message));
        $this->assertSame(6144, strlen($message));

        $this->assertInstanceOf(SendRcsRequest::class, new SendRcsRequest($message, '61491570014', 'DemoSender'));
    }

    // ---------------------------------------------------------------------------
    // message_ref — documented max 500 characters
    // ---------------------------------------------------------------------------

    public function test_rejects_a_message_ref_longer_than_the_documented_500_characters(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('500');

        new SendRcsRequest('Hi', '61491570014', 'DemoSender', messageRef: str_repeat('a', 501));
    }

    public function test_accepts_a_message_ref_at_exactly_500_characters(): void
    {
        $this->assertInstanceOf(
            SendRcsRequest::class,
            new SendRcsRequest('Hi', '61491570014', 'DemoSender', messageRef: str_repeat('a', 500)),
        );
    }

    // ---------------------------------------------------------------------------
    // sms_fallback
    // ---------------------------------------------------------------------------

    public function test_serialises_sms_fallback_through_sms_fallback_to_array(): void
    {
        $mock = new MockClient([SendRcsRequest::class => MockResponse::make(self::rcsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new RcsResource($connector))->send(
            'Your order has shipped.',
            '61491570014',
            'DemoSender',
            fallback: new SmsFallback('Your order has shipped.', '61491570017'),
        );

        $this->assertSame(
            ['message' => 'Your order has shipped.', 'sender' => '61491570017'],
            $mock->getLastPendingRequest()->body()->all()['sms_fallback'],
        );
    }

    public function test_omits_sms_fallback_and_message_ref_when_not_given(): void
    {
        $mock = new MockClient([SendRcsRequest::class => MockResponse::make(self::rcsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new RcsResource($connector))->send('Hi', '61491570014', 'DemoSender');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertArrayNotHasKey('sms_fallback', $body);
        $this->assertArrayNotHasKey('message_ref', $body);
    }

    // ---------------------------------------------------------------------------
    // The send request, end to end
    // ---------------------------------------------------------------------------

    public function test_sends_an_rcs_message_and_returns_a_typed_dto(): void
    {
        $mock = new MockClient([SendRcsRequest::class => MockResponse::make(self::rcsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $rcs = (new RcsResource($connector))->send(
            'Your order has shipped.',
            '61491570014',
            'DemoSender',
            messageRef: 'order-7782',
        );

        $this->assertInstanceOf(RcsMessageData::class, $rcs);
        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $rcs->id);
        $this->assertSame('DemoSender', $rcs->sender);
        $this->assertSame('61491570014', $rcs->recipient);
        $this->assertSame('text', $rcs->contentType);
        $this->assertSame('order-7782', $rcs->messageRef);

        $this->assertSame([
            'sender' => 'DemoSender',
            'recipient' => '61491570014',
            'content_type' => 'text',
            'content' => ['text' => ['message' => 'Your order has shipped.']],
            'message_ref' => 'order-7782',
        ], $mock->getLastPendingRequest()->body()->all());
    }

    // ---------------------------------------------------------------------------
    // Reading back
    // ---------------------------------------------------------------------------

    public function test_reads_the_id_out_of_the_data_envelope_rather_than_a_top_level_id(): void
    {
        // A decoy top-level id sits alongside the envelope, so a DTO reading
        // $json['id'] would resolve the wrong message rather than merely null.
        $rcs = self::rcsResource([
            SendRcsRequest::class => MockResponse::make([
                'id' => 'top-level-decoy',
                'data' => self::rcsMessage(),
            ], 200),
        ])->send('Hi', '61491570014', 'DemoSender');

        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $rcs->id);
    }

    public function test_reads_one_rcs_message_by_id(): void
    {
        $mock = new MockClient([GetRcsRequest::class => MockResponse::make(self::rcsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $rcs = (new RcsResource($connector))->get('6fdae71c-dad7-4c36-9734-a69693ecf3b4');

        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $rcs->id);
        $this->assertSame(
            'https://api.transmitmessage.com/v2/rcs/messages/6fdae71c-dad7-4c36-9734-a69693ecf3b4',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_sends_no_body_on_the_get_reader(): void
    {
        $mock = new MockClient([GetRcsRequest::class => MockResponse::make(self::rcsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new RcsResource($connector))->get('abc');

        $pending = $mock->getLastPendingRequest();

        $this->assertNull($pending->headers()->get('Content-Type'));
        $this->assertSame('', (string) $pending->body());
    }

    public function test_turns_a_404_into_a_not_found_exception(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('RCS message not found');

        self::rcsResource([GetRcsRequest::class => MockResponse::make(['error' => 'RCS message not found'], 404)])
            ->get('nope');
    }

    // ---------------------------------------------------------------------------
    // The list endpoint
    // ---------------------------------------------------------------------------

    public function test_declares_itself_cursor_paginated_with_items_at_data_messages_walked_forwards(): void
    {
        $this->assertInstanceOf(PaginatesV2Cursor::class, new ListRcsRequest);
        $this->assertSame('data.messages', (new ListRcsRequest)->paginationItemsKey());
        $this->assertSame('next', (new ListRcsRequest)->paginationDirection());
    }

    public function test_pages_the_list_endpoint_reading_items_out_of_the_data_messages_path(): void
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient([
            ListRcsRequest::class => MockResponse::make(self::rcsListBody(), 200),
        ]));

        $items = iterator_to_array((new RcsResource($connector))->list()->items());

        $this->assertCount(2, $items);
        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $items[0]['id']);
        $this->assertSame('second', $items[1]['id']);
    }

    public function test_passes_list_filters_through_as_query_parameters(): void
    {
        $mock = new MockClient([ListRcsRequest::class => MockResponse::make(self::rcsListBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        iterator_to_array((new RcsResource($connector))->list(
            dateRange: 'custom_date',
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        )->items());

        $query = $mock->getLastPendingRequest()->query();

        $this->assertSame('custom_date', $query->get('date_range'));
        $this->assertSame('2026-07-01', $query->get('start_date'));
        $this->assertSame('2026-07-31', $query->get('end_date'));
        $this->assertSame('next', $query->get('direction'));
    }

    public function test_sends_no_filter_query_parameters_when_none_are_given(): void
    {
        $mock = new MockClient([ListRcsRequest::class => MockResponse::make(self::rcsListBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        iterator_to_array((new RcsResource($connector))->list()->items());

        $query = $mock->getLastPendingRequest()->query();

        $this->assertNull($query->get('date_range'));
        $this->assertNull($query->get('start_date'));
        $this->assertNull($query->get('end_date'));
    }

    // The date_range allow-list and the custom_date pairing rule live in
    // Concerns\FiltersByDateRange and are asserted against this request,
    // alongside ListWhatsAppRequest, in V2DateRangeFilterTest.php.

    // ---------------------------------------------------------------------------
    // RcsMessageData
    // ---------------------------------------------------------------------------

    public function test_keeps_content_raw_because_the_response_echoes_whichever_variant_was_sent(): void
    {
        $rcs = RcsMessageData::fromArray(self::rcsMessage([
            'content' => ['text' => ['message' => 'Your order has shipped.']],
        ]));

        $this->assertSame(['text' => ['message' => 'Your order has shipped.']], $rcs->content);
    }

    public function test_leaves_status_null_when_the_send_response_omits_it(): void
    {
        $this->assertNull(RcsMessageData::fromArray(self::rcsMessage())->status);
    }

    public function test_resolves_a_present_status_through_message_status_from_api(): void
    {
        $this->assertSame(
            MessageStatus::Delivered,
            RcsMessageData::fromArray(self::rcsMessage(['status' => 'delivered']))->status,
        );
    }

    public function test_parses_sms_fallback_off_a_response_into_an_sms_fallback(): void
    {
        $rcs = RcsMessageData::fromArray(self::rcsMessage([
            'sms_fallback' => ['sender' => '61491570017', 'message' => 'Order has shipped.'],
        ]));

        $this->assertInstanceOf(SmsFallback::class, $rcs->smsFallback);
        $this->assertSame('Order has shipped.', $rcs->smsFallback?->message);
        $this->assertSame('61491570017', $rcs->smsFallback?->sender);
    }

    public function test_leaves_sms_fallback_null_when_the_response_omits_it(): void
    {
        $this->assertNull(RcsMessageData::fromArray(self::rcsMessage())->smsFallback);
    }

    public function test_leaves_sms_fallback_null_when_a_response_carries_a_fallback_with_no_message_rather_than_throwing(): void
    {
        // Uses SmsFallback::fromResponse() rather than a bespoke guard: that
        // factory already returns null for an absent/empty/non-string message.
        $rcs = RcsMessageData::fromArray(self::rcsMessage(['sms_fallback' => ['sender' => '61491570017']]));

        $this->assertNull($rcs->smsFallback);
    }

    public function test_parses_the_nine_fractional_digit_created_at_timestamp_that_rfc3339_extended_rejects(): void
    {
        $rcs = RcsMessageData::fromArray(self::rcsMessage(['created_at' => '2022-03-29T04:42:01.631708761Z']));

        $this->assertInstanceOf(DateTimeImmutable::class, $rcs->createdAt);
        $this->assertNotNull($rcs->createdAt);
    }

    public function test_returns_null_for_a_malformed_created_at_rather_than_throwing(): void
    {
        $this->assertNull(RcsMessageData::fromArray(self::rcsMessage(['created_at' => 'not-a-date']))->createdAt);
    }

    // ---------------------------------------------------------------------------
    // RcsCapabilityCode
    // ---------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: RcsCapabilityCode}> */
    public static function documentedCapabilityCodes(): array
    {
        return [
            'ENABLED' => ['ENABLED', RcsCapabilityCode::Enabled],
            'UNREACHABLE' => ['UNREACHABLE', RcsCapabilityCode::Unreachable],
            'REJECTED_NETWORK' => ['REJECTED_NETWORK', RcsCapabilityCode::RejectedNetwork],
            'REJECTED_ROUTE_NOT_AVAILABLE' => ['REJECTED_ROUTE_NOT_AVAILABLE', RcsCapabilityCode::RejectedRouteNotAvailable],
            'REQUEST_FAILED' => ['REQUEST_FAILED', RcsCapabilityCode::RequestFailed],
            'PROCESSING_ERROR' => ['PROCESSING_ERROR', RcsCapabilityCode::ProcessingError],
            'INVALID_DESTINATION_ADDRESS' => ['INVALID_DESTINATION_ADDRESS', RcsCapabilityCode::InvalidDestinationAddress],
        ];
    }

    #[DataProvider('documentedCapabilityCodes')]
    public function test_resolves_every_documented_capability_code_case_insensitively(string $value, RcsCapabilityCode $expected): void
    {
        $this->assertSame($expected, RcsCapabilityCode::fromApi($value));
        $this->assertSame($expected, RcsCapabilityCode::fromApi(strtolower($value)));
    }

    public function test_resolves_an_unrecognised_code_to_unknown_rather_than_throwing(): void
    {
        $this->assertSame(RcsCapabilityCode::Unknown, RcsCapabilityCode::fromApi('SOME_FUTURE_CODE'));
    }

    public function test_resolves_a_null_or_empty_code_to_unknown(): void
    {
        $this->assertSame(RcsCapabilityCode::Unknown, RcsCapabilityCode::fromApi(null));
        $this->assertSame(RcsCapabilityCode::Unknown, RcsCapabilityCode::fromApi(''));
    }

    public function test_treats_enabled_and_unknown_as_reachable_and_every_other_code_as_not_as_a_full_allow_list(): void
    {
        // An implementer's instinct is that Unknown means "assume unreachable" —
        // the skill says the opposite: treat it as reachable, send anyway, and
        // let sms_fallback carry what does not land. Asserted as a full
        // membership check rather than spot-checking two cases, so a new case
        // defaults closed.
        $reachable = array_values(array_filter(
            RcsCapabilityCode::cases(),
            static fn (RcsCapabilityCode $c): bool => $c->isReachable(),
        ));

        $this->assertEqualsCanonicalizing([RcsCapabilityCode::Enabled, RcsCapabilityCode::Unknown], $reachable);
    }

    // ---------------------------------------------------------------------------
    // RcsCapabilityData
    // ---------------------------------------------------------------------------

    public function test_builds_an_rcs_capability_data_from_a_capability_result(): void
    {
        $data = RcsCapabilityData::fromArray(['phone_number' => '61491570014', 'code' => 'ENABLED']);

        $this->assertSame('61491570014', $data->phoneNumber);
        $this->assertSame(RcsCapabilityCode::Enabled, $data->code);
    }

    // ---------------------------------------------------------------------------
    // Capability checks
    // ---------------------------------------------------------------------------

    public function test_sends_sender_and_phone_numbers_to_the_capabilities_endpoint(): void
    {
        $mock = new MockClient([CheckRcsCapabilitiesRequest::class => MockResponse::make(self::rcsCapabilitiesBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new RcsResource($connector))->capabilities(['61491570014', '61491570156'], 'DemoSender');

        $this->assertSame([
            'sender' => 'DemoSender',
            'phone_numbers' => ['61491570014', '61491570156'],
        ], $mock->getLastPendingRequest()->body()->all());
    }

    public function test_returns_one_typed_result_per_number_in_request_order(): void
    {
        $mock = new MockClient([CheckRcsCapabilitiesRequest::class => MockResponse::make(self::rcsCapabilitiesBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $results = (new RcsResource($connector))->capabilities(['61491570014', '61491570156'], 'DemoSender');

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertInstanceOf(RcsCapabilityData::class, $results[0]);
        $this->assertSame('61491570014', $results[0]->phoneNumber);
        $this->assertSame(RcsCapabilityCode::Enabled, $results[0]->code);
        $this->assertSame('61491570156', $results[1]->phoneNumber);
        $this->assertSame(RcsCapabilityCode::Unreachable, $results[1]->code);
    }

    public function test_rejects_more_than_100_phone_numbers_per_request(): void
    {
        $numbers = array_map(static fn (int $i): string => (string) (61491570006 + $i), range(1, 101));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('100');

        new CheckRcsCapabilitiesRequest($numbers, 'DemoSender');
    }

    public function test_accepts_exactly_100_phone_numbers(): void
    {
        $numbers = array_map(static fn (int $i): string => (string) (61491570006 + $i), range(1, 100));

        $this->assertInstanceOf(CheckRcsCapabilitiesRequest::class, new CheckRcsCapabilitiesRequest($numbers, 'DemoSender'));
    }

    public function test_rejects_an_empty_phone_numbers_array(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('empty');

        new CheckRcsCapabilitiesRequest([], 'DemoSender');
    }
}
