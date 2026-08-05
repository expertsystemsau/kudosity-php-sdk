<?php

declare(strict_types=1);

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
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The message object verbatim from .agents/skills/kudosity-rcs/SKILL.md's send
 * response example, extended with the fields GET/list also carry.
 */
function rcsMessage(array $overrides = []): array
{
    return array_merge([
        'id' => '6fdae71c-dad7-4c36-9734-a69693ecf3b4',
        'message_ref' => 'order-7782',
        'sender' => 'DemoSender',
        'recipient' => '61438333061',
        'content_type' => 'text',
        'created_at' => '2026-07-29T00:00:00Z',
    ], $overrides);
}

/** The send response — WRAPPED in a `data` envelope, like WhatsApp. */
function rcsSendBody(array $overrides = []): array
{
    return ['data' => rcsMessage($overrides)];
}

/** The list response — items at `data.messages`, cursor state at `meta.pagination`. */
function rcsListBody(): array
{
    return [
        'data' => [
            'messages' => [rcsMessage(), rcsMessage(['id' => 'second'])],
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
 */
function rcsCapabilitiesBody(): array
{
    return [
        'data' => [
            'results' => [
                ['phone_number' => '61438333061', 'code' => 'ENABLED'],
                ['phone_number' => '61491570156', 'code' => 'UNREACHABLE'],
            ],
        ],
    ];
}

function rcsResource(array $responses): RcsResource
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return new RcsResource($connector);
}

// ---------------------------------------------------------------------------
// Content nesting
// ---------------------------------------------------------------------------

it('nests the message under content.text.message with a text content_type', function () {
    $mock = new MockClient([SendRcsRequest::class => MockResponse::make(rcsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new RcsResource($connector))->send('Your order has shipped.', '61438333061', 'DemoSender');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['content_type'])->toBe('text')
        ->and($body['content'])->toBe(['text' => ['message' => 'Your order has shipped.']])
        ->and($body)->not->toHaveKey('message');
});

it('sends the sender as the agent id and the recipient in international form', function () {
    $mock = new MockClient([SendRcsRequest::class => MockResponse::make(rcsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new RcsResource($connector))->send('Hi', '+61 438 333 061', 'DemoSender');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['sender'])->toBe('DemoSender')
        ->and($body['recipient'])->toBe('61438333061');
});

// ---------------------------------------------------------------------------
// The agent-ID trap
// ---------------------------------------------------------------------------

it('rejects a sender that looks like a phone number, naming the agent ID rule', function () {
    // A plausible E.164 value that PhoneNumber::isValid() actually accepts:
    // 11 digits once cleaned, no leading zero. If this stopped satisfying
    // isValid() for an unrelated reason, this test would prove nothing.
    expect(PhoneNumber::isValid('+61411122211'))->toBeTrue();

    new SendRcsRequest('Hi', '61438333061', '+61411122211');
})->throws(ValidationException::class, 'agent ID');

it('accepts an alphanumeric agent id', function () {
    expect(new SendRcsRequest('Hi', '61438333061', 'DemoSender'))
        ->toBeInstanceOf(SendRcsRequest::class);
});

it('accepts a numeric agent id that is not a valid phone number', function () {
    // The docs permit numeric agent IDs. This one is too short to satisfy
    // PhoneNumber::isValid() (which requires 7-15 digits), so the rule is
    // "looks like a valid phone number", not "contains only digits".
    expect(PhoneNumber::isValid('12345'))->toBeFalse();

    expect(new SendRcsRequest('Hi', '61438333061', '12345'))
        ->toBeInstanceOf(SendRcsRequest::class);
});

// ---------------------------------------------------------------------------
// Message length — up to 3072 characters, full UTF-8
// ---------------------------------------------------------------------------

it('accepts a message exactly at the 3072-character maximum', function () {
    expect(new SendRcsRequest(str_repeat('a', 3072), '61438333061', 'DemoSender'))
        ->toBeInstanceOf(SendRcsRequest::class);
});

it('rejects a message one character over the 3072 maximum', function () {
    new SendRcsRequest(str_repeat('a', 3073), '61438333061', 'DemoSender');
})->throws(ValidationException::class, '3072');

it('counts multi-byte characters rather than bytes against the 3072 limit', function () {
    // 'é' is two bytes in UTF-8. strlen() would count 6144 and wrongly reject
    // this; mb_strlen() counts 3072 characters and must accept it.
    $message = str_repeat('é', 3072);

    expect(mb_strlen($message))->toBe(3072)
        ->and(strlen($message))->toBe(6144);

    expect(new SendRcsRequest($message, '61438333061', 'DemoSender'))
        ->toBeInstanceOf(SendRcsRequest::class);
});

// ---------------------------------------------------------------------------
// message_ref — documented max 500 characters
// ---------------------------------------------------------------------------

it('rejects a message_ref longer than the documented 500 characters', function () {
    new SendRcsRequest('Hi', '61438333061', 'DemoSender', messageRef: str_repeat('a', 501));
})->throws(ValidationException::class, '500');

it('accepts a message_ref at exactly 500 characters', function () {
    expect(new SendRcsRequest('Hi', '61438333061', 'DemoSender', messageRef: str_repeat('a', 500)))
        ->toBeInstanceOf(SendRcsRequest::class);
});

// ---------------------------------------------------------------------------
// sms_fallback
// ---------------------------------------------------------------------------

it('serialises sms_fallback through SmsFallback::toArray()', function () {
    $mock = new MockClient([SendRcsRequest::class => MockResponse::make(rcsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new RcsResource($connector))->send(
        'Your order has shipped.',
        '61438333061',
        'DemoSender',
        fallback: new SmsFallback('Your order has shipped.', '61481074185'),
    );

    expect($mock->getLastPendingRequest()->body()->all()['sms_fallback'])
        ->toBe(['message' => 'Your order has shipped.', 'sender' => '61481074185']);
});

it('omits sms_fallback and message_ref when not given', function () {
    $mock = new MockClient([SendRcsRequest::class => MockResponse::make(rcsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new RcsResource($connector))->send('Hi', '61438333061', 'DemoSender');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body)->not->toHaveKey('sms_fallback')
        ->and($body)->not->toHaveKey('message_ref');
});

// ---------------------------------------------------------------------------
// The send request, end to end
// ---------------------------------------------------------------------------

it('sends an RCS message and returns a typed DTO', function () {
    $mock = new MockClient([SendRcsRequest::class => MockResponse::make(rcsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $rcs = (new RcsResource($connector))->send(
        'Your order has shipped.',
        '61438333061',
        'DemoSender',
        messageRef: 'order-7782',
    );

    expect($rcs)->toBeInstanceOf(RcsMessageData::class)
        ->and($rcs->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4')
        ->and($rcs->sender)->toBe('DemoSender')
        ->and($rcs->recipient)->toBe('61438333061')
        ->and($rcs->contentType)->toBe('text')
        ->and($rcs->messageRef)->toBe('order-7782');

    expect($mock->getLastPendingRequest()->body()->all())->toBe([
        'sender' => 'DemoSender',
        'recipient' => '61438333061',
        'content_type' => 'text',
        'content' => ['text' => ['message' => 'Your order has shipped.']],
        'message_ref' => 'order-7782',
    ]);
});

// ---------------------------------------------------------------------------
// Reading back
// ---------------------------------------------------------------------------

it('reads the id out of the data envelope rather than a top-level id', function () {
    // A decoy top-level id sits alongside the envelope, so a DTO reading
    // $json['id'] would resolve the wrong message rather than merely null.
    $rcs = rcsResource([
        SendRcsRequest::class => MockResponse::make([
            'id' => 'top-level-decoy',
            'data' => rcsMessage(),
        ], 200),
    ])->send('Hi', '61438333061', 'DemoSender');

    expect($rcs->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4');
});

it('reads one RCS message by id', function () {
    $mock = new MockClient([GetRcsRequest::class => MockResponse::make(rcsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $rcs = (new RcsResource($connector))->get('6fdae71c-dad7-4c36-9734-a69693ecf3b4');

    expect($rcs->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4')
        ->and((string) $mock->getLastPendingRequest()->getUri())
        ->toBe('https://api.transmitmessage.com/v2/rcs/messages/6fdae71c-dad7-4c36-9734-a69693ecf3b4');
});

it('sends no body on the GET reader', function () {
    $mock = new MockClient([GetRcsRequest::class => MockResponse::make(rcsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new RcsResource($connector))->get('abc');

    $pending = $mock->getLastPendingRequest();

    expect($pending->headers()->get('Content-Type'))->toBeNull()
        ->and((string) $pending->body())->toBe('');
});

it('turns a 404 into a NotFoundException', function () {
    rcsResource([GetRcsRequest::class => MockResponse::make(['error' => 'RCS message not found'], 404)])
        ->get('nope');
})->throws(NotFoundException::class, 'RCS message not found');

// ---------------------------------------------------------------------------
// The list endpoint
// ---------------------------------------------------------------------------

it('declares itself cursor-paginated, with items at data.messages walked forwards', function () {
    expect(new ListRcsRequest)->toBeInstanceOf(PaginatesV2Cursor::class)
        ->and((new ListRcsRequest)->paginationItemsKey())->toBe('data.messages')
        ->and((new ListRcsRequest)->paginationDirection())->toBe('next');
});

it('pages the list endpoint, reading items out of the data.messages path', function () {
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient([
        ListRcsRequest::class => MockResponse::make(rcsListBody(), 200),
    ]));

    $items = iterator_to_array((new RcsResource($connector))->list()->items());

    expect($items)->toHaveCount(2)
        ->and($items[0]['id'])->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4')
        ->and($items[1]['id'])->toBe('second');
});

it('passes list filters through as query parameters', function () {
    $mock = new MockClient([ListRcsRequest::class => MockResponse::make(rcsListBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array((new RcsResource($connector))->list(
        dateRange: 'custom_date',
        startDate: '2026-07-01',
        endDate: '2026-07-31',
    )->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('date_range'))->toBe('custom_date')
        ->and($query->get('start_date'))->toBe('2026-07-01')
        ->and($query->get('end_date'))->toBe('2026-07-31')
        ->and($query->get('direction'))->toBe('next');
});

it('sends no filter query parameters when none are given', function () {
    $mock = new MockClient([ListRcsRequest::class => MockResponse::make(rcsListBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array((new RcsResource($connector))->list()->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('date_range'))->toBeNull()
        ->and($query->get('start_date'))->toBeNull()
        ->and($query->get('end_date'))->toBeNull();
});

it('accepts every documented date_range value that needs no window', function (string $dateRange) {
    expect(new ListRcsRequest(dateRange: $dateRange))->toBeInstanceOf(ListRcsRequest::class);
})->with(['last_week', 'last_thirty', 'last_month', 'all']);

it('accepts custom_date when both dates are supplied', function () {
    expect(new ListRcsRequest(dateRange: 'custom_date', startDate: '2026-07-01', endDate: '2026-07-31'))
        ->toBeInstanceOf(ListRcsRequest::class);
});

it('rejects a date_range outside the documented set', function () {
    new ListRcsRequest(dateRange: 'yesterday');
})->throws(ValidationException::class, 'date_range must be one of');

it('rejects custom_date with neither date, because the API answers a generic 400', function () {
    new ListRcsRequest(dateRange: 'custom_date');
})->throws(ValidationException::class, 'both required');

it('rejects custom_date with only start_date', function () {
    new ListRcsRequest(dateRange: 'custom_date', startDate: '2026-07-01');
})->throws(ValidationException::class, 'both required');

it('rejects custom_date with only end_date', function () {
    new ListRcsRequest(dateRange: 'custom_date', endDate: '2026-07-31');
})->throws(ValidationException::class, 'both required');

it('rejects start_date with no date_range, because the API would ignore it silently', function () {
    new ListRcsRequest(startDate: '2026-07-01');
})->throws(ValidationException::class, 'only meaningful');

it('rejects end_date with no date_range', function () {
    new ListRcsRequest(endDate: '2026-07-31');
})->throws(ValidationException::class, 'only meaningful');

it('rejects both dates with no date_range', function () {
    new ListRcsRequest(startDate: '2026-07-01', endDate: '2026-07-31');
})->throws(ValidationException::class, 'only meaningful');

it('rejects dates alongside a date_range other than custom_date', function () {
    new ListRcsRequest(dateRange: 'last_week', startDate: '2026-07-01', endDate: '2026-07-31');
})->throws(ValidationException::class, 'only meaningful');

// ---------------------------------------------------------------------------
// RcsMessageData
// ---------------------------------------------------------------------------

it('keeps content raw, because the response echoes whichever variant was sent', function () {
    $rcs = RcsMessageData::fromArray(rcsMessage([
        'content' => ['text' => ['message' => 'Your order has shipped.']],
    ]));

    expect($rcs->content)->toBe(['text' => ['message' => 'Your order has shipped.']]);
});

it('leaves status null when the send response omits it', function () {
    expect(RcsMessageData::fromArray(rcsMessage())->status)->toBeNull();
});

it('resolves a present status through MessageStatus::fromApi()', function () {
    expect(RcsMessageData::fromArray(rcsMessage(['status' => 'delivered']))->status)
        ->toBe(MessageStatus::Delivered);
});

it('parses sms_fallback off a response into an SmsFallback', function () {
    $rcs = RcsMessageData::fromArray(rcsMessage([
        'sms_fallback' => ['sender' => '61481074185', 'message' => 'Order has shipped.'],
    ]));

    expect($rcs->smsFallback)->toBeInstanceOf(SmsFallback::class)
        ->and($rcs->smsFallback?->message)->toBe('Order has shipped.')
        ->and($rcs->smsFallback?->sender)->toBe('61481074185');
});

it('leaves smsFallback null when the response omits it', function () {
    expect(RcsMessageData::fromArray(rcsMessage())->smsFallback)->toBeNull();
});

it('leaves smsFallback null when a response carries a fallback with no message, rather than throwing', function () {
    // Uses SmsFallback::fromResponse() rather than a bespoke guard: that
    // factory already returns null for an absent/empty/non-string message.
    $rcs = RcsMessageData::fromArray(rcsMessage(['sms_fallback' => ['sender' => '61481074185']]));

    expect($rcs->smsFallback)->toBeNull();
});

it('parses the nine-fractional-digit created_at timestamp that RFC3339_EXTENDED rejects', function () {
    $rcs = RcsMessageData::fromArray(rcsMessage(['created_at' => '2022-03-29T04:42:01.631708761Z']));

    expect($rcs->createdAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($rcs->createdAt)->not->toBeNull();
});

it('returns null for a malformed created_at rather than throwing', function () {
    expect(RcsMessageData::fromArray(rcsMessage(['created_at' => 'not-a-date']))->createdAt)->toBeNull();
});

// ---------------------------------------------------------------------------
// RcsCapabilityCode
// ---------------------------------------------------------------------------

it('resolves every documented capability code case-insensitively', function (string $value, RcsCapabilityCode $expected) {
    expect(RcsCapabilityCode::fromApi($value))->toBe($expected)
        ->and(RcsCapabilityCode::fromApi(strtolower($value)))->toBe($expected);
})->with([
    ['ENABLED', RcsCapabilityCode::Enabled],
    ['UNREACHABLE', RcsCapabilityCode::Unreachable],
    ['REJECTED_NETWORK', RcsCapabilityCode::RejectedNetwork],
    ['REJECTED_ROUTE_NOT_AVAILABLE', RcsCapabilityCode::RejectedRouteNotAvailable],
    ['REQUEST_FAILED', RcsCapabilityCode::RequestFailed],
    ['PROCESSING_ERROR', RcsCapabilityCode::ProcessingError],
    ['INVALID_DESTINATION_ADDRESS', RcsCapabilityCode::InvalidDestinationAddress],
]);

it('resolves an unrecognised code to Unknown rather than throwing', function () {
    expect(RcsCapabilityCode::fromApi('SOME_FUTURE_CODE'))->toBe(RcsCapabilityCode::Unknown);
});

it('resolves a null or empty code to Unknown', function () {
    expect(RcsCapabilityCode::fromApi(null))->toBe(RcsCapabilityCode::Unknown)
        ->and(RcsCapabilityCode::fromApi(''))->toBe(RcsCapabilityCode::Unknown);
});

it('treats Enabled and Unknown as reachable and every other code as not, as a full allow-list', function () {
    // An implementer's instinct is that Unknown means "assume unreachable" —
    // the skill says the opposite: treat it as reachable, send anyway, and let
    // sms_fallback carry what does not land. Asserted as a full membership
    // check rather than spot-checking two cases, so a new case defaults closed.
    $reachable = array_values(array_filter(RcsCapabilityCode::cases(), fn (RcsCapabilityCode $c) => $c->isReachable()));

    expect($reachable)->toEqualCanonicalizing([RcsCapabilityCode::Enabled, RcsCapabilityCode::Unknown]);
});

// ---------------------------------------------------------------------------
// RcsCapabilityData
// ---------------------------------------------------------------------------

it('builds an RcsCapabilityData from a capability result', function () {
    $data = RcsCapabilityData::fromArray(['phone_number' => '61438333061', 'code' => 'ENABLED']);

    expect($data->phoneNumber)->toBe('61438333061')
        ->and($data->code)->toBe(RcsCapabilityCode::Enabled);
});

// ---------------------------------------------------------------------------
// Capability checks
// ---------------------------------------------------------------------------

it('sends sender and phone_numbers to the capabilities endpoint', function () {
    $mock = new MockClient([CheckRcsCapabilitiesRequest::class => MockResponse::make(rcsCapabilitiesBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new RcsResource($connector))->capabilities(['61438333061', '61491570156'], 'DemoSender');

    expect($mock->getLastPendingRequest()->body()->all())->toBe([
        'sender' => 'DemoSender',
        'phone_numbers' => ['61438333061', '61491570156'],
    ]);
});

it('returns one typed result per number, in request order', function () {
    $mock = new MockClient([CheckRcsCapabilitiesRequest::class => MockResponse::make(rcsCapabilitiesBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $results = (new RcsResource($connector))->capabilities(['61438333061', '61491570156'], 'DemoSender');

    expect($results)->toBeArray()
        ->and($results)->toHaveCount(2)
        ->and($results[0])->toBeInstanceOf(RcsCapabilityData::class)
        ->and($results[0]->phoneNumber)->toBe('61438333061')
        ->and($results[0]->code)->toBe(RcsCapabilityCode::Enabled)
        ->and($results[1]->phoneNumber)->toBe('61491570156')
        ->and($results[1]->code)->toBe(RcsCapabilityCode::Unreachable);
});

it('rejects more than 100 phone numbers per request', function () {
    $numbers = array_map(static fn (int $i): string => (string) (61400000000 + $i), range(1, 101));

    new CheckRcsCapabilitiesRequest($numbers, 'DemoSender');
})->throws(ValidationException::class, '100');

it('accepts exactly 100 phone numbers', function () {
    $numbers = array_map(static fn (int $i): string => (string) (61400000000 + $i), range(1, 100));

    expect(new CheckRcsCapabilitiesRequest($numbers, 'DemoSender'))
        ->toBeInstanceOf(CheckRcsCapabilitiesRequest::class);
});

it('rejects an empty phone_numbers array', function () {
    new CheckRcsCapabilitiesRequest([], 'DemoSender');
})->throws(ValidationException::class, 'empty');
