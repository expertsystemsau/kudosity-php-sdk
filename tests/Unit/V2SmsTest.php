<?php

declare(strict_types=1);

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
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** Verbatim from .agents/skills/kudosity-sms/SKILL.md — note the FLAT envelope. */
function smsSendBody(array $overrides = []): array
{
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

function smsResource(array $responses): SmsV2Resource
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return new SmsV2Resource($connector);
}

it('sends a single-recipient SMS and returns a typed DTO', function () {
    $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $sms = (new SmsV2Resource($connector))->send('Report to the ready room!', '61478038915', '61481074185');

    expect($sms)->toBeInstanceOf(SmsMessageData::class)
        ->and($sms->id)->toBe('2d2c8fb6-e514-4f5f-9706-0672b0259218')
        ->and($sms->status)->toBe(MessageStatus::Delivered)
        ->and($sms->recipientCountry)->toBe('AU');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body)->toBe([
        'message' => 'Report to the ready room!',
        'sender' => '61481074185',
        'recipient' => '61478038915',
    ]);
});

it('casts the string sms_count to an int', function () {
    // The API returns "1", not 1. Arithmetic on the raw value silently
    // concatenates — "1" + 1 gives "11".
    $sms = smsResource([SendSmsV2Request::class => MockResponse::make(smsSendBody(['sms_count' => '3']), 200)])
        ->send('Hi', '61478038915', '61481074185');

    expect($sms->smsCount)->toBe(3)->and($sms->smsCount)->toBeInt();
});

it('omits optional fields from the body rather than sending nulls', function () {
    $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SmsV2Resource($connector))->send('Hi', '61478038915', '61481074185');

    expect($mock->getLastPendingRequest()->body()->all())
        ->not->toHaveKey('message_ref')
        ->and($mock->getLastPendingRequest()->body()->all())->not->toHaveKey('track_links');
});

it('sends message_ref and track_links when given', function () {
    $mock = new MockClient([SendSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SmsV2Resource($connector))->send('Hi', '61478038915', '61481074185', messageRef: 'order-1', trackLinks: true);

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['message_ref'])->toBe('order-1')->and($body['track_links'])->toBeTrue();
});

it('rejects a message_ref longer than the documented 500 characters', function () {
    new SendSmsV2Request('Hi', '61478038915', '61481074185', messageRef: str_repeat('a', 501));
})->throws(ValidationException::class, '500');

it('reads one SMS by id', function () {
    $mock = new MockClient([GetSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $sms = (new SmsV2Resource($connector))->get('2d2c8fb6-e514-4f5f-9706-0672b0259218');

    expect($sms->id)->toBe('2d2c8fb6-e514-4f5f-9706-0672b0259218')
        ->and((string) $mock->getLastPendingRequest()->getUri())
        ->toBe('https://api.transmitmessage.com/v2/sms/2d2c8fb6-e514-4f5f-9706-0672b0259218');
});

it('sends no body on the GET reader', function () {
    // Phase 2 split the request bases precisely so readers do not ship a JSON
    // body; a V2 GET carrying one is stripped or rejected by some gateways.
    $mock = new MockClient([GetSmsV2Request::class => MockResponse::make(smsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new SmsV2Resource($connector))->get('abc');

    $pending = $mock->getLastPendingRequest();

    expect($pending->headers()->get('Content-Type'))->toBeNull()
        ->and((string) $pending->body())->toBe('');
});

it('turns a 404 into a NotFoundException', function () {
    smsResource([GetSmsV2Request::class => MockResponse::make(['error' => 'SMS not found'], 404)])->get('nope');
})->throws(NotFoundException::class, 'SMS not found');

it('pages the list endpoint and hydrates each item', function () {
    // The paginator reads items straight off the response JSON (Phase 2's
    // design — createDtoFromResponse() is never invoked through list()), so
    // this covers paging and item hydration only. The total_records/
    // total_segments string->int cast is proven separately, directly against
    // SmsListData::fromArray(), below.
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient([
        ListSmsV2Request::class => MockResponse::make([
            'smses' => [smsSendBody(), smsSendBody(['id' => 'second'])],
            'total_records' => '2',
            'total_segments' => '2',
        ], 200),
    ]));

    $items = iterator_to_array((new SmsV2Resource($connector))->list()->items());

    expect($items)->toHaveCount(2)->and($items[0]['id'])->toBe('2d2c8fb6-e514-4f5f-9706-0672b0259218');
});

it('casts SmsListData total_records and total_segments strings to int and hydrates messages', function () {
    // GET /v2/sms answers "total_records": "2", not 2 — same trap as
    // sms_count. Proven here directly against the DTO, decoupled from the
    // paginator, since list() never reaches createDtoFromResponse().
    $list = SmsListData::fromArray([
        'smses' => [smsSendBody(), smsSendBody(['id' => 'second'])],
        'total_records' => '2',
        'total_segments' => '3',
    ]);

    expect($list->totalRecords)->toBe(2)->and($list->totalRecords)->toBeInt()
        ->and($list->totalSegments)->toBe(3)->and($list->totalSegments)->toBeInt()
        ->and($list->messages)->toHaveCount(2)
        ->and($list->messages[0])->toBeInstanceOf(SmsMessageData::class)
        ->and($list->messages[1])->toBeInstanceOf(SmsMessageData::class);
});

it('passes list filters through as query parameters', function () {
    $mock = new MockClient([
        ListSmsV2Request::class => MockResponse::make(['smses' => [smsSendBody()], 'total_records' => '1'], 200),
    ]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array((new SmsV2Resource($connector))->list(
        status: MessageStatus::Delivered,
        recipient: '61478038915',
        sender: '61481074185',
        messageRef: 'order-1',
        direction: 'OUT',
    )->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('status'))->toBe('DELIVERED')
        ->and($query->get('recipient'))->toBe('61478038915')
        ->and($query->get('sender'))->toBe('61481074185')
        ->and($query->get('message_ref'))->toBe('order-1')
        ->and($query->get('direction'))->toBe('OUT');
});

it('sends no filter query parameters when none are given', function () {
    $mock = new MockClient([
        ListSmsV2Request::class => MockResponse::make(['smses' => [smsSendBody()], 'total_records' => '1'], 200),
    ]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array((new SmsV2Resource($connector))->list()->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('status'))->toBeNull()
        ->and($query->get('recipient'))->toBeNull()
        ->and($query->get('sender'))->toBeNull()
        ->and($query->get('message_ref'))->toBeNull()
        ->and($query->get('direction'))->toBeNull();
});

it('rejects QUEUED as a status filter, because GET /v2/sms does not document it', function () {
    // MessageStatus is deliberately the union of three vocabularies (SMS,
    // WhatsApp/RCS, and the SDK's own UNKNOWN sentinel). QUEUED belongs to
    // WhatsApp/RCS only — a caller who reads it off a WhatsApp message and
    // feeds it back into sms()->list() would otherwise have it silently
    // ignored by the API rather than rejected.
    expect(fn () => new ListSmsV2Request(status: MessageStatus::Queued))
        ->toThrow(function (ValidationException $e) {
            expect($e->getErrorCode())->toBe('FIELD_INVALID')
                ->and($e->getMessage())->toContain('status must be one of');
        });
});

it('rejects UNKNOWN as a status filter, because it is a sentinel, not an API value', function () {
    expect(fn () => new ListSmsV2Request(status: MessageStatus::Unknown))
        ->toThrow(function (ValidationException $e) {
            expect($e->getErrorCode())->toBe('FIELD_INVALID')
                ->and($e->getMessage())->toContain('status must be one of');
        });
});

it('accepts a documented status value', function () {
    expect(new ListSmsV2Request(status: MessageStatus::Delivered))->toBeInstanceOf(ListSmsV2Request::class);
});

it('declares itself paged so the connector picks the right paginator', function () {
    expect(new ListSmsV2Request)->toBeInstanceOf(PaginatesV2Pages::class)
        ->and((new ListSmsV2Request)->paginationItemsKey())->toBe('smses');
});

it('parses the nine-fractional-digit created_at timestamp that RFC3339_EXTENDED rejects', function () {
    // DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, ...)
    // expects exactly six fractional digits; the API sends nine
    // ("...450674000Z"). The permissive `new DateTimeImmutable($value)`
    // parse this DTO uses tolerates it instead of silently dropping the field.
    $sms = SmsMessageData::fromArray(smsSendBody(['created_at' => '2022-03-28T06:12:52.450674000Z']));

    expect($sms->createdAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($sms->createdAt)->not->toBeNull();
});

it('returns null for a malformed created_at rather than throwing', function () {
    $sms = SmsMessageData::fromArray(smsSendBody(['created_at' => 'not-a-date']));

    expect($sms->createdAt)->toBeNull();
});

it('normalises an empty routed_via to null, because the API sends "" for no shared number', function () {
    $sms = SmsMessageData::fromArray(smsSendBody(['routed_via' => '']));

    expect($sms->routedVia)->toBeNull();
});

it('keeps a real routed_via value', function () {
    $sms = SmsMessageData::fromArray(smsSendBody(['routed_via' => '61481074185']));

    expect($sms->routedVia)->toBe('61481074185');
});
