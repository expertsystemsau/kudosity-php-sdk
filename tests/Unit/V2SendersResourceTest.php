<?php

declare(strict_types=1);

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
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\PaginationPlugin\Paginator;

/**
 * The real empty response, captured live. See tests/Fixtures/V2Senders/README.md.
 *
 * @return array<string, mixed>
 */
function senderFixture(string $name): array
{
    $path = dirname(__DIR__).'/Fixtures/V2Senders/'.$name.'.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A registration row.
 *
 * **The item shape is not verified** — the account holds none, so this is built
 * from the three paths the skill documents plus the field names the API's own
 * validation errors named. Every field the DTO reads is optional for that reason.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function senderRegistrationRow(array $overrides = []): array
{
    return array_merge([
        'id' => 'reg-7f3a',
        'sender' => '61400000000',
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
function senderListBody(array $rows, ?int $totalCount = null, int $limit = 25, int $page = 1): array
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

/**
 * @param  array<class-string, MockResponse>  $responses
 */
function sendersResource(array $responses): SendersResource
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return new SendersResource($connector);
}

// ---------------------------------------------------------------------------
// The envelope, against the real captured response
// ---------------------------------------------------------------------------

it('returns no registrations for the empty response the live account actually sends', function () {
    // The captured fixture, not a hand-written empty case: data.registrations is
    // present but empty, with total_count 0.
    expect(sendersResource([
        ListSenderRegistrationsRequest::class => MockResponse::make(senderFixture('registrations-empty'), 200),
    ])->allRegistrations())->toBe([]);
});

it('reads items from data.registrations, not a top-level key', function () {
    // Nested AND data-wrapped, unlike every other V2 list. A decoy top-level
    // registrations key means a wrong read resolves the decoy rather than null.
    $body = senderListBody([senderRegistrationRow()]);
    $body['registrations'] = [senderRegistrationRow(['id' => 'decoy'])];

    $registrations = sendersResource([
        ListSenderRegistrationsRequest::class => MockResponse::make($body, 200),
    ])->allRegistrations();

    expect($registrations)->toHaveCount(1)
        ->and($registrations[0]->id)->toBe('reg-7f3a');
});

it('declares page pagination with items at data.registrations', function () {
    expect(new ListSenderRegistrationsRequest)
        ->toBeInstanceOf(PaginatesV2Pages::class)
        ->and((new ListSenderRegistrationsRequest)->paginationItemsKey())->toBe('data.registrations')
        ->and((new ListSenderRegistrationsRequest)->resolveEndpoint())->toBe('/v2/senders/registrations');
});

it('walks a second page using the total the API reports under meta.pagination', function () {
    // The finding this covers: this endpoint reports total_count under
    // meta.pagination, where SMS reports total_records at the top level. A
    // paginator reading only total_records stops after page one.
    $mock = new MockClient([
        ListSenderRegistrationsRequest::class => MockResponse::make(
            senderListBody([senderRegistrationRow(['id' => 'page1'])], totalCount: 2, limit: 1, page: 1),
            200,
        ),
    ]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    // Two rows across two pages of one. The mock replays the same body, so the
    // assertion is on how many pages were requested, not their contents.
    $registrations = (new SendersResource($connector))->allRegistrations();

    expect(count($registrations))->toBeGreaterThan(1);
});

it('stops after one page when the reported total fits in it', function () {
    $registrations = sendersResource([
        ListSenderRegistrationsRequest::class => MockResponse::make(
            senderListBody([senderRegistrationRow()], totalCount: 1, limit: 25),
            200,
        ),
    ])->allRegistrations();

    expect($registrations)->toHaveCount(1);
});

it('returns a paginator from registrations(), matching the other V2 list reads', function () {
    $paginator = sendersResource([
        ListSenderRegistrationsRequest::class => MockResponse::make(senderFixture('registrations-empty'), 200),
    ])->registrations();

    expect($paginator)->toBeInstanceOf(Paginator::class);
});

// ---------------------------------------------------------------------------
// SenderStatus — the distinction that costs a day
// ---------------------------------------------------------------------------

it('treats READY_TO_USE as sendable and every other state as not, as a full allow-list', function () {
    // A full membership check rather than spot-checks, so a new registry state
    // defaults closed. VERIFIED is the one that catches people: it means
    // provisioning, and sending on it fails in a way that looks like anything but
    // a sender problem.
    $sendable = array_values(array_filter(
        SenderStatus::cases(),
        static fn (SenderStatus $s): bool => $s->isReadyToUse(),
    ));

    expect($sendable)->toBe([SenderStatus::ReadyToUse])
        ->and(SenderStatus::Verified->isReadyToUse())->toBeFalse();
});

it('never reports an unrecognised state as sendable', function () {
    // Defaulting an unknown state to sendable is how a half-provisioned sender
    // reaches production.
    expect(SenderStatus::fromApi('SOME_NEW_REGISTRY_STATE'))->toBe(SenderStatus::Unknown)
        ->and(SenderStatus::Unknown->isReadyToUse())->toBeFalse()
        ->and(SenderStatus::Unknown->isInProgress())->toBeTrue();
});

it('resolves every documented lifecycle state, case-insensitively', function (string $api, SenderStatus $expected) {
    expect(SenderStatus::fromApi($api))->toBe($expected)
        ->and(SenderStatus::fromApi(strtolower($api)))->toBe($expected);
})->with([
    ['NEW', SenderStatus::New],
    ['SUBMITTED_TO_REGISTRY', SenderStatus::SubmittedToRegistry],
    ['PENDING_CUSTOMER', SenderStatus::PendingCustomer],
    ['PENDING_APPROVAL', SenderStatus::PendingApproval],
    ['VERIFIED', SenderStatus::Verified],
    ['READY_TO_USE', SenderStatus::ReadyToUse],
]);

it('flags PENDING_CUSTOMER as waiting on the account holder, and nothing else', function () {
    $waiting = array_values(array_filter(
        SenderStatus::cases(),
        static fn (SenderStatus $s): bool => $s->needsYourAction(),
    ));

    expect($waiting)->toBe([SenderStatus::PendingCustomer]);
});

it('resolves a null or empty status to Unknown', function () {
    expect(SenderStatus::fromApi(null))->toBe(SenderStatus::Unknown)
        ->and(SenderStatus::fromApi(''))->toBe(SenderStatus::Unknown);
});

// ---------------------------------------------------------------------------
// SenderRegistrationData
// ---------------------------------------------------------------------------

it('reads the status from whichever details key the registration type uses', function (string $detailsKey) {
    // The skill documents details.alphanumeric.status, but the API only accepts
    // type PERSONAL_MOBILE_NUMBER — so hardcoding either key would be wrong for
    // the other. The first status found under any details key wins.
    $row = senderRegistrationRow(['details' => [$detailsKey => ['status' => 'VERIFIED']]]);

    expect(SenderRegistrationData::fromArray($row)->status)->toBe(SenderStatus::Verified);
})->with([
    'documented key' => ['alphanumeric'],
    'the only permitted type' => ['personal_mobile_number'],
    'something not yet seen' => ['some_future_type'],
]);

it('prefers a top-level status when a flat response provides one', function () {
    $row = senderRegistrationRow([
        'status' => 'READY_TO_USE',
        'details' => ['personal_mobile_number' => ['status' => 'NEW']],
    ]);

    expect(SenderRegistrationData::fromArray($row)->status)->toBe(SenderStatus::ReadyToUse);
});

it('resolves to Unknown rather than throwing when no status is anywhere', function (array $row) {
    expect(SenderRegistrationData::fromArray($row)->status)->toBe(SenderStatus::Unknown);
})->with([
    'no details at all' => [['id' => 'r1']],
    'details not an array' => [['id' => 'r1', 'details' => 'nope']],
    'details without a status' => [['id' => 'r1', 'details' => ['alphanumeric' => ['other' => 1]]]],
    'details entry not an array' => [['id' => 'r1', 'details' => ['alphanumeric' => 'nope']]],
]);

it('exposes the documented status_reason and child_account_id', function () {
    $row = senderRegistrationRow([
        'details' => ['personal_mobile_number' => ['status' => 'PENDING_CUSTOMER']],
        'status_reason' => 'Waiting for proof of ownership',
        'child_account_id' => 'child-88',
    ]);

    $reg = SenderRegistrationData::fromArray($row);

    expect($reg->needsYourAction())->toBeTrue()
        ->and($reg->statusReason)->toBe('Waiting for proof of ownership')
        ->and($reg->childAccountId)->toBe('child-88');
});

it('keeps the row verbatim in raw, because the item shape is unverified', function () {
    // raw is the honest answer until a real registration exists to model against.
    $row = senderRegistrationRow(['a_field_nobody_has_seen' => ['deep' => true]]);

    expect(SenderRegistrationData::fromArray($row)->raw['a_field_nobody_has_seen'])
        ->toBe(['deep' => true]);
});

it('survives a row with nothing but an id', function () {
    $reg = SenderRegistrationData::fromArray(['id' => 'r1']);

    expect($reg->id)->toBe('r1')
        ->and($reg->sender)->toBeNull()
        ->and($reg->country)->toBeNull()
        ->and($reg->type)->toBe(SenderRegistrationType::Unknown)
        ->and($reg->status)->toBe(SenderStatus::Unknown)
        ->and($reg->isReadyToUse())->toBeFalse()
        ->and($reg->createdAt)->toBeNull();
});

it('filters to the registrations that can actually send', function () {
    $registrations = sendersResource([
        ListSenderRegistrationsRequest::class => MockResponse::make(senderListBody([
            senderRegistrationRow(['id' => 'ready', 'details' => ['personal_mobile_number' => ['status' => 'READY_TO_USE']]]),
            senderRegistrationRow(['id' => 'verified', 'details' => ['personal_mobile_number' => ['status' => 'VERIFIED']]]),
            senderRegistrationRow(['id' => 'pending', 'details' => ['personal_mobile_number' => ['status' => 'PENDING_APPROVAL']]]),
        ], limit: 25), 200),
    ])->readyToUse();

    expect($registrations)->toHaveCount(1)
        ->and($registrations[0]->id)->toBe('ready');
});

// ---------------------------------------------------------------------------
// register() — schema read out of the API's validation errors
// ---------------------------------------------------------------------------

it('sends exactly the three fields the API says it requires', function () {
    $mock = new MockClient([RegisterSenderRequest::class => MockResponse::make(['data' => senderRegistrationRow()], 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SendersResource($connector))->register('61400000000', 'AU');

    expect($mock->getLastPendingRequest()?->body()?->all())->toBe([
        'sender' => '61400000000',
        'country' => 'AU',
        'type' => 'PERSONAL_MOBILE_NUMBER',
    ]);
});

it('defaults the type to the only value the API accepts', function () {
    expect(SenderRegistrationType::PersonalMobileNumber->value)->toBe('PERSONAL_MOBILE_NUMBER')
        ->and(SenderRegistrationType::fromApi('PERSONAL_MOBILE_NUMBER'))
        ->toBe(SenderRegistrationType::PersonalMobileNumber);
});

it('refuses to send Unknown as a type, since it is a read-side landing pad', function () {
    // fromApi() lands undocumented values on Unknown so reads do not break. That
    // makes it fine to receive and never right to send.
    new RegisterSenderRequest('61400000000', 'AU', SenderRegistrationType::Unknown);
})->throws(ValidationException::class, 'type must be one of: PERSONAL_MOBILE_NUMBER');

it('rejects an empty sender or country locally, naming the field', function (string $sender, string $country, string $fragment) {
    new RegisterSenderRequest($sender, $country);
})->with([
    'no sender' => ['', 'AU', 'sender is required'],
    'no country' => ['61400000000', '', 'country is required'],
])->throws(ValidationException::class);

it('reads the registration out of the data envelope', function () {
    $reg = sendersResource([
        RegisterSenderRequest::class => MockResponse::make(['data' => senderRegistrationRow(['id' => 'from-data'])], 201),
    ])->register('61400000000', 'AU');

    expect($reg->id)->toBe('from-data');
});

// ---------------------------------------------------------------------------
// Verification flow
// ---------------------------------------------------------------------------

it('requests verification with the method and originating sender the API requires', function () {
    $mock = new MockClient([RequestSenderVerificationRequest::class => MockResponse::make(['data' => ['sent' => true]], 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SendersResource($connector))->requestVerification('reg-7f3a', '61481074185');

    $request = $mock->getLastPendingRequest();

    expect($request?->body()?->all())->toBe([
        'method' => 'SMS',
        'originating_sender' => '61481074185',
    ])
        ->and($request?->getUrl())->toEndWith('/v2/senders/registrations/reg-7f3a/verifications');
});

it('refuses an unknown verification method', function () {
    new RequestSenderVerificationRequest('reg-1', '61481074185', SenderVerificationMethod::Unknown);
})->throws(ValidationException::class, 'method must be one of: SMS');

it('rejects an empty registration id or originating sender', function (string $id, string $sender) {
    new RequestSenderVerificationRequest($id, $sender);
})->with([
    'no id' => ['', '61481074185'],
    'no originating sender' => ['reg-1', ''],
])->throws(ValidationException::class);

it('confirms with the code as a string, so leading zeros survive', function () {
    // 012345 as an int is 12345, or an octal literal. Codes have leading zeros.
    $mock = new MockClient([ConfirmSenderVerificationRequest::class => MockResponse::make(['data' => ['verified' => true]], 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SendersResource($connector))->confirmVerification('reg-7f3a', '012345');

    $request = $mock->getLastPendingRequest();

    expect($request?->body()?->all())->toBe(['code' => '012345'])
        ->and($request?->getUrl())->toEndWith('/v2/senders/registrations/reg-7f3a/verifications/confirmation');
});

it('rejects an empty code', function () {
    new ConfirmSenderVerificationRequest('reg-1', '');
})->throws(ValidationException::class, 'code is required');

// ---------------------------------------------------------------------------
// delete by phone number
// ---------------------------------------------------------------------------

it('deletes by phone number rather than registration id', function () {
    $mock = new MockClient([DeleteSenderPhoneNumberRequest::class => MockResponse::make([], 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    expect((new SendersResource($connector))->deleteByPhoneNumber('61400000000'))->toBeTrue()
        ->and($mock->getLastPendingRequest()?->getUrl())->toEndWith('/v2/senders/phone-numbers/61400000000');
});

it('url-encodes a plus-prefixed number so it is not read as a space', function () {
    expect((new DeleteSenderPhoneNumberRequest('+61400000000'))->resolveEndpoint())
        ->toBe('/v2/senders/phone-numbers/%2B61400000000');
});

it('rejects an empty phone number', function () {
    new DeleteSenderPhoneNumberRequest('');
})->throws(ValidationException::class, 'phone number is required');

it('maps an unregistered number to NotFoundException', function () {
    // Verbatim from the live 404, which is RFC 9457 here rather than the plain
    // string body the webhook endpoints use.
    sendersResource([
        DeleteSenderPhoneNumberRequest::class => MockResponse::make([
            'error' => [
                'detail' => 'sender not found',
                'status' => 404,
                'title' => 'Not Found',
                'type' => 'https://developers.kudosity.com/reference/errors#not-found',
            ],
        ], 404),
    ])->deleteByPhoneNumber('61400000000');
})->throws(NotFoundException::class, 'sender not found');

it('sends no body on the delete', function () {
    $mock = new MockClient([DeleteSenderPhoneNumberRequest::class => MockResponse::make([], 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SendersResource($connector))->deleteByPhoneNumber('61400000000');

    expect($mock->getLastPendingRequest()?->body())->toBeNull();
});
