<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Data\V2\SenderRegistrationData;
use ExpertSystems\Kudosity\Enums\SenderRegistrationType;
use ExpertSystems\Kudosity\Enums\SenderStatus;
use ExpertSystems\Kudosity\Enums\SenderVerificationMethod;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\ConfirmSenderVerificationRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteSenderPhoneNumberRequest;
use ExpertSystems\Kudosity\Requests\V2\ListSenderRegistrationsRequest;
use ExpertSystems\Kudosity\Requests\V2\RegisterSenderRequest;
use ExpertSystems\Kudosity\Requests\V2\RequestSenderVerificationRequest;
use ExpertSystems\Kudosity\Resources\SendersResource;
use ExpertSystems\Kudosity\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\PaginationPlugin\Paginator;

/**
 * Ported from the root Pest suite's tests/Unit/V2SendersResourceTest.php.
 *
 * Resource-level registration and verification behaviour for SendersResource,
 * plus SenderRegistrationData, SenderStatus, SenderRegistrationType and
 * SenderVerificationMethod. **Read
 * `tests/Fixtures/V2Senders/README.md` before touching any assertion here**:
 * the registration item shape has never been observed live, so several
 * fields are read defensively by design and this file must not "tighten" one.
 *
 * The root file inlined its own `senderFixture()` loader reading through to
 * this package's `tests/Fixtures/V2Senders/` — the one root test file Task 1
 * could not update in place. That loader is dropped here in favour of the
 * client suite's own `Fixtures::sender()`, so there is exactly one fixture
 * loader rather than two.
 *
 * Two pre-existing tests fold into this file:
 *
 * - `RequestShapeTest`'s `test_a_phone_number_in_a_path_segment_is_url_encoded`
 *   duplicated this file's "url-encodes a plus-prefixed number" test exactly
 *   — same class, same input, same expected output — so it came out there.
 * - `EnumToleranceTest`'s `test_sender_status_verified_does_not_mean_ready_to_use`
 *   asserted the same two facts (`Verified->isReadyToUse()` false,
 *   `ReadyToUse->isReadyToUse()` true, implicitly, by being the only case
 *   admitted) that this file's own "treats READY_TO_USE as sendable and every
 *   other state as not, as a full allow-list" test asserts as a strict subset
 *   of a full case sweep — so the dominated original came out there too.
 *
 * Everything else is narrow, matching what the brief expects: `RequestShapeTest`
 * pins only the endpoint table; `DtoTest`'s single combined `SenderRegistrationData`
 * test uses different fixture values and asserts a different scenario (a
 * top-level `status` with no `details` key at all, versus this file's
 * top-level-wins-over-details *precedence* case) from anything here.
 */
#[CoversClass(SendersResource::class)]
#[CoversClass(RegisterSenderRequest::class)]
#[CoversClass(ListSenderRegistrationsRequest::class)]
#[CoversClass(RequestSenderVerificationRequest::class)]
#[CoversClass(ConfirmSenderVerificationRequest::class)]
#[CoversClass(DeleteSenderPhoneNumberRequest::class)]
#[CoversClass(SenderRegistrationData::class)]
#[CoversClass(SenderStatus::class)]
#[CoversClass(SenderRegistrationType::class)]
#[CoversClass(SenderVerificationMethod::class)]
final class V2SendersResourceTest extends TestCase
{
    /**
     * A registration row.
     *
     * **The item shape is not verified** — the account holds none, so this is
     * built from the three paths the skill documents plus the field names the
     * API's own validation errors named. Every field the DTO reads is
     * optional for that reason.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function senderRegistrationRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'reg-7f3a',
            'sender' => '61491570006',
            'country' => 'AU',
            'type' => 'PERSONAL_MOBILE_NUMBER',
            'details' => ['personal_mobile_number' => ['status' => 'READY_TO_USE']],
            'status_reason' => null,
            'child_account_id' => null,
            'created_at' => '2026-08-05T15:48:45.072031Z',
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function senderListBody(array $rows, ?int $totalCount = null, int $limit = 25, int $page = 1): array
    {
        return [
            'data' => ['registrations' => $rows],
            'meta' => ['pagination' => [
                'limit' => $limit,
                'page' => $page,
                'total_count' => $totalCount ?? count($rows),
                'type' => 'page',
            ]],
        ];
    }

    /** @param array<class-string, MockResponse> $responses */
    private static function sendersResource(array $responses): SendersResource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return new SendersResource($connector);
    }

    // ---------------------------------------------------------------------------
    // The envelope, against the real captured response
    // ---------------------------------------------------------------------------

    public function test_returns_no_registrations_for_the_empty_response_the_live_account_actually_sends(): void
    {
        // The captured fixture, not a hand-written empty case: data.registrations is
        // present but empty, with total_count 0.
        $this->assertSame([], self::sendersResource([
            ListSenderRegistrationsRequest::class => MockResponse::make(Fixtures::sender('registrations-empty'), 200),
        ])->allRegistrations());
    }

    public function test_reads_items_from_data_registrations_not_a_top_level_key(): void
    {
        // Nested AND data-wrapped, unlike every other V2 list. A decoy top-level
        // registrations key means a wrong read resolves the decoy rather than null.
        $body = self::senderListBody([self::senderRegistrationRow()]);
        $body['registrations'] = [self::senderRegistrationRow(['id' => 'decoy'])];

        $registrations = self::sendersResource([
            ListSenderRegistrationsRequest::class => MockResponse::make($body, 200),
        ])->allRegistrations();

        $this->assertCount(1, $registrations);
        $this->assertSame('reg-7f3a', $registrations[0]->id);
    }

    public function test_declares_page_pagination_with_items_at_data_registrations(): void
    {
        $this->assertInstanceOf(PaginatesV2Pages::class, new ListSenderRegistrationsRequest);
        $this->assertSame('data.registrations', (new ListSenderRegistrationsRequest)->paginationItemsKey());
        $this->assertSame('/v2/senders/registrations', (new ListSenderRegistrationsRequest)->resolveEndpoint());
    }

    public function test_walks_a_second_page_using_the_total_the_api_reports_under_meta_pagination(): void
    {
        // The finding this covers: this endpoint reports total_count under
        // meta.pagination, where SMS reports total_records at the top level. A
        // paginator reading only total_records stops after page one.
        $mock = new MockClient([
            ListSenderRegistrationsRequest::class => MockResponse::make(
                self::senderListBody([self::senderRegistrationRow(['id' => 'page1'])], totalCount: 2, limit: 1, page: 1),
                200,
            ),
        ]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        // Two rows across two pages of one. The mock replays the same body, so the
        // assertion is on how many pages were requested, not their contents.
        $registrations = (new SendersResource($connector))->allRegistrations();

        $this->assertGreaterThan(1, count($registrations));
    }

    public function test_stops_after_one_page_when_the_reported_total_fits_in_it(): void
    {
        $registrations = self::sendersResource([
            ListSenderRegistrationsRequest::class => MockResponse::make(
                self::senderListBody([self::senderRegistrationRow()], totalCount: 1, limit: 25),
                200,
            ),
        ])->allRegistrations();

        $this->assertCount(1, $registrations);
    }

    public function test_returns_a_paginator_from_registrations_matching_the_other_v2_list_reads(): void
    {
        $paginator = self::sendersResource([
            ListSenderRegistrationsRequest::class => MockResponse::make(Fixtures::sender('registrations-empty'), 200),
        ])->registrations();

        $this->assertInstanceOf(Paginator::class, $paginator);
    }

    // ---------------------------------------------------------------------------
    // SenderStatus — the distinction that costs a day
    // ---------------------------------------------------------------------------

    public function test_treats_ready_to_use_as_sendable_and_every_other_state_as_not_as_a_full_allow_list(): void
    {
        // A full membership check rather than spot-checks, so a new registry state
        // defaults closed. VERIFIED is the one that catches people: it means
        // provisioning, and sending on it fails in a way that looks like anything but
        // a sender problem.
        $sendable = array_values(array_filter(
            SenderStatus::cases(),
            static fn (SenderStatus $s): bool => $s->isReadyToUse(),
        ));

        $this->assertSame([SenderStatus::ReadyToUse], $sendable);
        $this->assertFalse(SenderStatus::Verified->isReadyToUse());
    }

    public function test_never_reports_an_unrecognised_state_as_sendable(): void
    {
        // Defaulting an unknown state to sendable is how a half-provisioned sender
        // reaches production.
        $this->assertSame(SenderStatus::Unknown, SenderStatus::fromApi('SOME_NEW_REGISTRY_STATE'));
        $this->assertFalse(SenderStatus::Unknown->isReadyToUse());
        $this->assertTrue(SenderStatus::Unknown->isInProgress());
    }

    /** @return array<string, array{0: string, 1: SenderStatus}> */
    public static function documentedLifecycleStates(): array
    {
        return [
            'NEW' => ['NEW', SenderStatus::New],
            'SUBMITTED_TO_REGISTRY' => ['SUBMITTED_TO_REGISTRY', SenderStatus::SubmittedToRegistry],
            'PENDING_CUSTOMER' => ['PENDING_CUSTOMER', SenderStatus::PendingCustomer],
            'PENDING_APPROVAL' => ['PENDING_APPROVAL', SenderStatus::PendingApproval],
            'VERIFIED' => ['VERIFIED', SenderStatus::Verified],
            'READY_TO_USE' => ['READY_TO_USE', SenderStatus::ReadyToUse],
        ];
    }

    #[DataProvider('documentedLifecycleStates')]
    public function test_resolves_every_documented_lifecycle_state_case_insensitively(string $api, SenderStatus $expected): void
    {
        $this->assertSame($expected, SenderStatus::fromApi($api));
        $this->assertSame($expected, SenderStatus::fromApi(strtolower($api)));
    }

    public function test_flags_pending_customer_as_waiting_on_the_account_holder_and_nothing_else(): void
    {
        $waiting = array_values(array_filter(
            SenderStatus::cases(),
            static fn (SenderStatus $s): bool => $s->needsYourAction(),
        ));

        $this->assertSame([SenderStatus::PendingCustomer], $waiting);
    }

    public function test_resolves_a_null_or_empty_status_to_unknown(): void
    {
        $this->assertSame(SenderStatus::Unknown, SenderStatus::fromApi(null));
        $this->assertSame(SenderStatus::Unknown, SenderStatus::fromApi(''));
    }

    // ---------------------------------------------------------------------------
    // SenderRegistrationData
    // ---------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function detailsKeys(): array
    {
        return [
            'documented key' => ['alphanumeric'],
            'the only permitted type' => ['personal_mobile_number'],
            'something not yet seen' => ['some_future_type'],
        ];
    }

    #[DataProvider('detailsKeys')]
    public function test_reads_the_status_from_whichever_details_key_the_registration_type_uses(string $detailsKey): void
    {
        // The skill documents details.alphanumeric.status, but the API only accepts
        // type PERSONAL_MOBILE_NUMBER — so hardcoding either key would be wrong for
        // the other. The first status found under any details key wins.
        $row = self::senderRegistrationRow(['details' => [$detailsKey => ['status' => 'VERIFIED']]]);

        $this->assertSame(SenderStatus::Verified, SenderRegistrationData::fromArray($row)->status);
    }

    public function test_prefers_a_top_level_status_when_a_flat_response_provides_one(): void
    {
        $row = self::senderRegistrationRow([
            'status' => 'READY_TO_USE',
            'details' => ['personal_mobile_number' => ['status' => 'NEW']],
        ]);

        $this->assertSame(SenderStatus::ReadyToUse, SenderRegistrationData::fromArray($row)->status);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function statuslessRows(): array
    {
        return [
            'no details at all' => [['id' => 'r1']],
            'details not an array' => [['id' => 'r1', 'details' => 'nope']],
            'details without a status' => [['id' => 'r1', 'details' => ['alphanumeric' => ['other' => 1]]]],
            'details entry not an array' => [['id' => 'r1', 'details' => ['alphanumeric' => 'nope']]],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('statuslessRows')]
    public function test_resolves_to_unknown_rather_than_throwing_when_no_status_is_anywhere(array $row): void
    {
        $this->assertSame(SenderStatus::Unknown, SenderRegistrationData::fromArray($row)->status);
    }

    public function test_exposes_the_documented_status_reason_and_child_account_id(): void
    {
        $row = self::senderRegistrationRow([
            'details' => ['personal_mobile_number' => ['status' => 'PENDING_CUSTOMER']],
            'status_reason' => 'Waiting for proof of ownership',
            'child_account_id' => 'child-88',
        ]);

        $reg = SenderRegistrationData::fromArray($row);

        $this->assertTrue($reg->needsYourAction());
        $this->assertSame('Waiting for proof of ownership', $reg->statusReason);
        $this->assertSame('child-88', $reg->childAccountId);
    }

    public function test_keeps_the_row_verbatim_in_raw_because_the_item_shape_is_unverified(): void
    {
        // raw is the honest answer until a real registration exists to model against.
        $row = self::senderRegistrationRow(['a_field_nobody_has_seen' => ['deep' => true]]);

        $this->assertSame(['deep' => true], SenderRegistrationData::fromArray($row)->raw['a_field_nobody_has_seen']);
    }

    public function test_survives_a_row_with_nothing_but_an_id(): void
    {
        $reg = SenderRegistrationData::fromArray(['id' => 'r1']);

        $this->assertSame('r1', $reg->id);
        $this->assertNull($reg->sender);
        $this->assertNull($reg->country);
        $this->assertSame(SenderRegistrationType::Unknown, $reg->type);
        $this->assertSame(SenderStatus::Unknown, $reg->status);
        $this->assertFalse($reg->isReadyToUse());
        $this->assertNull($reg->createdAt);
    }

    public function test_filters_to_the_registrations_that_can_actually_send(): void
    {
        $registrations = self::sendersResource([
            ListSenderRegistrationsRequest::class => MockResponse::make(self::senderListBody([
                self::senderRegistrationRow(['id' => 'ready', 'details' => ['personal_mobile_number' => ['status' => 'READY_TO_USE']]]),
                self::senderRegistrationRow(['id' => 'verified', 'details' => ['personal_mobile_number' => ['status' => 'VERIFIED']]]),
                self::senderRegistrationRow(['id' => 'pending', 'details' => ['personal_mobile_number' => ['status' => 'PENDING_APPROVAL']]]),
            ], limit: 25), 200),
        ])->readyToUse();

        $this->assertCount(1, $registrations);
        $this->assertSame('ready', $registrations[0]->id);
    }

    // ---------------------------------------------------------------------------
    // register() — schema read out of the API's validation errors
    // ---------------------------------------------------------------------------

    public function test_sends_exactly_the_three_fields_the_api_says_it_requires(): void
    {
        $mock = new MockClient([RegisterSenderRequest::class => MockResponse::make(['data' => self::senderRegistrationRow()], 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new SendersResource($connector))->register('61491570006', 'AU');

        $this->assertSame([
            'sender' => '61491570006',
            'country' => 'AU',
            'type' => 'PERSONAL_MOBILE_NUMBER',
        ], $mock->getLastPendingRequest()?->body()?->all());
    }

    public function test_defaults_the_type_to_the_only_value_the_api_accepts(): void
    {
        $this->assertSame('PERSONAL_MOBILE_NUMBER', SenderRegistrationType::PersonalMobileNumber->value);
        $this->assertSame(SenderRegistrationType::PersonalMobileNumber, SenderRegistrationType::fromApi('PERSONAL_MOBILE_NUMBER'));
    }

    public function test_refuses_to_send_unknown_as_a_type_since_it_is_a_read_side_landing_pad(): void
    {
        // fromApi() lands undocumented values on Unknown so reads do not break. That
        // makes it fine to receive and never right to send.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('type must be one of: PERSONAL_MOBILE_NUMBER');

        new RegisterSenderRequest('61491570006', 'AU', SenderRegistrationType::Unknown);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function emptyRegistrationFields(): array
    {
        return [
            'no sender' => ['', 'AU', 'sender is required'],
            'no country' => ['61491570006', '', 'country is required'],
        ];
    }

    #[DataProvider('emptyRegistrationFields')]
    public function test_rejects_an_empty_sender_or_country_locally_naming_the_field(string $sender, string $country, string $fragment): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($fragment);

        new RegisterSenderRequest($sender, $country);
    }

    public function test_reads_the_registration_out_of_the_data_envelope(): void
    {
        $reg = self::sendersResource([
            RegisterSenderRequest::class => MockResponse::make(['data' => self::senderRegistrationRow(['id' => 'from-data'])], 201),
        ])->register('61491570006', 'AU');

        $this->assertSame('from-data', $reg->id);
    }

    // ---------------------------------------------------------------------------
    // Verification flow
    // ---------------------------------------------------------------------------

    public function test_requests_verification_with_the_method_and_originating_sender_the_api_requires(): void
    {
        $mock = new MockClient([RequestSenderVerificationRequest::class => MockResponse::make(['data' => ['sent' => true]], 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new SendersResource($connector))->requestVerification('reg-7f3a', '61491570017');

        $request = $mock->getLastPendingRequest();

        $this->assertSame([
            'method' => 'SMS',
            'originating_sender' => '61491570017',
        ], $request?->body()?->all());
        $this->assertStringEndsWith('/v2/senders/registrations/reg-7f3a/verifications', (string) $request?->getUrl());
    }

    public function test_refuses_an_unknown_verification_method(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('method must be one of: SMS');

        new RequestSenderVerificationRequest('reg-1', '61491570017', SenderVerificationMethod::Unknown);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function emptyVerificationFields(): array
    {
        return [
            'no id' => ['', '61491570017'],
            'no originating sender' => ['reg-1', ''],
        ];
    }

    #[DataProvider('emptyVerificationFields')]
    public function test_rejects_an_empty_registration_id_or_originating_sender(string $id, string $sender): void
    {
        $this->expectException(ValidationException::class);

        new RequestSenderVerificationRequest($id, $sender);
    }

    public function test_confirms_with_the_code_as_a_string_so_leading_zeros_survive(): void
    {
        // 012345 as an int is 12345, or an octal literal. Codes have leading zeros.
        $mock = new MockClient([ConfirmSenderVerificationRequest::class => MockResponse::make(['data' => ['verified' => true]], 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new SendersResource($connector))->confirmVerification('reg-7f3a', '012345');

        $request = $mock->getLastPendingRequest();

        $this->assertSame(['code' => '012345'], $request?->body()?->all());
        $this->assertStringEndsWith('/v2/senders/registrations/reg-7f3a/verifications/confirmation', (string) $request?->getUrl());
    }

    public function test_rejects_an_empty_code(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('code is required');

        new ConfirmSenderVerificationRequest('reg-1', '');
    }

    // ---------------------------------------------------------------------------
    // delete by phone number
    // ---------------------------------------------------------------------------

    public function test_deletes_by_phone_number_rather_than_registration_id(): void
    {
        $mock = new MockClient([DeleteSenderPhoneNumberRequest::class => MockResponse::make([], 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $this->assertTrue((new SendersResource($connector))->deleteByPhoneNumber('61491570006'));
        $this->assertStringEndsWith('/v2/senders/phone-numbers/61491570006', (string) $mock->getLastPendingRequest()?->getUrl());
    }

    public function test_url_encodes_a_plus_prefixed_number_so_it_is_not_read_as_a_space(): void
    {
        $this->assertSame(
            '/v2/senders/phone-numbers/%2B61491570006',
            (new DeleteSenderPhoneNumberRequest('+61491570006'))->resolveEndpoint(),
        );
    }

    public function test_rejects_an_empty_phone_number(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('phone number is required');

        new DeleteSenderPhoneNumberRequest('');
    }

    public function test_maps_an_unregistered_number_to_not_found_exception(): void
    {
        // Verbatim from the live 404, which is RFC 9457 here rather than the plain
        // string body the webhook endpoints use.
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('sender not found');

        self::sendersResource([
            DeleteSenderPhoneNumberRequest::class => MockResponse::make([
                'error' => [
                    'detail' => 'sender not found',
                    'status' => 404,
                    'title' => 'Not Found',
                    'type' => 'https://developers.kudosity.com/reference/errors#not-found',
                ],
            ], 404),
        ])->deleteByPhoneNumber('61491570006');
    }

    public function test_sends_no_body_on_the_delete(): void
    {
        $mock = new MockClient([DeleteSenderPhoneNumberRequest::class => MockResponse::make([], 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new SendersResource($connector))->deleteByPhoneNumber('61491570006');

        $this->assertNull($mock->getLastPendingRequest()?->body());
    }
}
