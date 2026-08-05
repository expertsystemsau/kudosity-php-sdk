<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Data\V2\Content\CustomContent;
use ExpertSystems\Kudosity\Data\V2\Content\TemplateContent;
use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\GetWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\SendWhatsAppRequest;
use ExpertSystems\Kudosity\Resources\WhatsAppResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The message object verbatim from .agents/skills/kudosity-whatsapp/SKILL.md.
 *
 * Note what is absent: no `status`, no `content`, no `campaign_id`. The send
 * response really does omit them, which is why those DTO fields are nullable.
 */
function whatsAppMessage(array $overrides = []): array
{
    return array_merge([
        'id' => '6fdae71c-dad7-4c36-9734-a69693ecf3b4',
        'message_ref' => 'order-12345',
        'sender' => '14155238886',
        'recipient' => '61411122211',
        'content_type' => 'template',
        'created_at' => '2026-07-29T00:00:00Z',
    ], $overrides);
}

/** The send response — WRAPPED in a `data` envelope, unlike SMS and MMS. */
function whatsAppSendBody(array $overrides = []): array
{
    return ['data' => whatsAppMessage($overrides)];
}

/** The list response — items at `data.messages`, cursor state at `meta.pagination`. */
function whatsAppListBody(): array
{
    return [
        'data' => [
            'messages' => [whatsAppMessage(), whatsAppMessage(['id' => 'second'])],
        ],
        'meta' => [
            'pagination' => [
                'has_next' => false,
                'next_cursor' => '',
            ],
        ],
    ];
}

function whatsAppResource(array $responses): WhatsAppResource
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return new WhatsAppResource($connector);
}

// ---------------------------------------------------------------------------
// The nested envelope — the whole reason the content value objects exist
// ---------------------------------------------------------------------------

it('derives content_type from the content object and nests content under its variant key', function () {
    // content_type and content come from one source, so they cannot disagree.
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->template('order_update', ['#12345', 'shipped'], '61411122211');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['content_type'])->toBe('template')
        ->and($body['content'])->toBe([
            'template' => [
                'name' => 'order_update',
                'parameters' => ['#12345', 'shipped'],
            ],
        ]);
});

it('sends no top-level name key, so flattening the content envelope fails this test', function () {
    // The skill names the flattened shape as the most common mistake: it is
    // content.template.name, never content.name and never a top-level name.
    // This inspects the body actually put on the wire, not the value object.
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->template('order_update', ['#12345'], '61411122211');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body)->not->toHaveKey('name')
        ->and($body)->not->toHaveKey('parameters')
        ->and($body)->not->toHaveKey('template')
        ->and($body['content'])->not->toHaveKey('name')
        ->and($body['content'])->toHaveKey('template')
        ->and($body['content']['template']['name'])->toBe('order_update');
});

it('nests free-form text under content.text.message', function () {
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(['content_type' => 'text']), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->text('Thanks — your refund is on its way.', '61411122211');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['content_type'])->toBe('text')
        ->and($body['content'])->toBe(['text' => ['message' => 'Thanks — your refund is on its way.']])
        ->and($body)->not->toHaveKey('message');
});

it('passes a custom payload through verbatim under content.custom', function () {
    // custom is Meta's own schema, not Kudosity's — the SDK must not reshape it.
    $payload = [
        'type' => 'template',
        'template' => [
            'name' => 'template_img_simple_1',
            'language' => ['code' => 'en', 'policy' => 'deterministic'],
            'components' => [
                [
                    'type' => 'HEADER',
                    'parameters' => [
                        ['type' => 'image', 'image' => ['link' => 'https://example.com/hero.jpg']],
                    ],
                ],
            ],
        ],
    ];

    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(['content_type' => 'custom']), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->custom($payload, '61411122211');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['content_type'])->toBe('custom')
        ->and($body['content'])->toBe(['custom' => $payload]);
});

it('derives both fields from whatever content object send() is handed', function () {
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(['content_type' => 'text']), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->send(new TextContent('Hi'), '61411122211');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['content_type'])->toBe('text')
        ->and($body['content'])->toBe(['text' => ['message' => 'Hi']]);
});

it('has every content variant report its own content type through the contract', function () {
    $text = new TextContent('Hi');
    $template = new TemplateContent('order_update');
    $custom = new CustomContent(['type' => 'template']);

    expect($text)->toBeInstanceOf(WhatsAppContent::class)
        ->and($template)->toBeInstanceOf(WhatsAppContent::class)
        ->and($custom)->toBeInstanceOf(WhatsAppContent::class)
        ->and($text->contentType())->toBe('text')
        ->and($template->contentType())->toBe('template')
        ->and($custom->contentType())->toBe('custom');
});

// ---------------------------------------------------------------------------
// TemplateContent
// ---------------------------------------------------------------------------

it('omits parameters when empty and locale when null', function () {
    expect((new TemplateContent('order_update'))->toArray())
        ->toBe(['template' => ['name' => 'order_update']]);
});

it('includes locale when given', function () {
    expect((new TemplateContent('order_update', ['#12345'], 'en_US'))->toArray())
        ->toBe(['template' => [
            'name' => 'order_update',
            'parameters' => ['#12345'],
            'locale' => 'en_US',
        ]]);
});

it('reindexes gapped parameter keys so they serialise as a positional array', function () {
    // Parameters fill {1}, {2}, ... positionally. A gapped array — what
    // array_filter() leaves behind — would encode as a JSON object and break
    // the send.
    expect((new TemplateContent('order_update', [1 => '#12345', 3 => 'shipped']))->toArray())
        ->toBe(['template' => [
            'name' => 'order_update',
            'parameters' => ['#12345', 'shipped'],
        ]]);
});

it('rejects a template name containing an uppercase letter, naming the rule', function () {
    new TemplateContent('Order_Update');
})->throws(ValidationException::class, 'lowercase');

it('rejects a template name containing a hyphen', function () {
    new TemplateContent('order-update');
})->throws(ValidationException::class, 'lowercase');

it('rejects a template name containing a space', function () {
    new TemplateContent('order update');
})->throws(ValidationException::class, 'lowercase');

it('rejects an empty template name', function () {
    new TemplateContent('');
})->throws(ValidationException::class);

it('accepts a lowercase alphanumeric name with underscores', function () {
    expect((new TemplateContent('order_update_2'))->toArray())
        ->toBe(['template' => ['name' => 'order_update_2']]);
});

it('rejects a non-string template parameter rather than stringifying it', function () {
    // Deliberate choice: reject. Template parameters are positional strings
    // upstream, so silently stringifying an int, array or object is exactly
    // the silent-wrong behaviour this channel exists to prevent. The name here
    // is valid, so only the parameter rule can be what fires.
    new TemplateContent('order_update', [12345]);
})->throws(ValidationException::class, 'string');

it('rejects an array template parameter', function () {
    new TemplateContent('order_update', [['type' => 'image']]);
})->throws(ValidationException::class, 'string');

// ---------------------------------------------------------------------------
// The send request
// ---------------------------------------------------------------------------

it('sends a template WhatsApp message and returns a typed DTO', function () {
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $wa = (new WhatsAppResource($connector))->template(
        'order_update',
        ['#12345', 'shipped'],
        '61411122211',
        from: '14155238886',
        messageRef: 'order-12345',
    );

    expect($wa)->toBeInstanceOf(WhatsAppMessageData::class)
        ->and($wa->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4')
        ->and($wa->recipient)->toBe('61411122211')
        ->and($wa->sender)->toBe('14155238886')
        ->and($wa->contentType)->toBe('template')
        ->and($wa->messageRef)->toBe('order-12345');

    expect($mock->getLastPendingRequest()->body()->all())->toBe([
        'recipient' => '61411122211',
        'content_type' => 'template',
        'content' => ['template' => ['name' => 'order_update', 'parameters' => ['#12345', 'shipped']]],
        'sender' => '14155238886',
        'message_ref' => 'order-12345',
    ]);
});

it('omits sender so the account default applies, rather than substituting a placeholder', function () {
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->text('Hi', '61411122211');

    expect($mock->getLastPendingRequest()->body()->all())
        ->not->toHaveKey('sender')
        ->and($mock->getLastPendingRequest()->body()->all())->not->toHaveKey('sms_fallback')
        ->and($mock->getLastPendingRequest()->body()->all())->not->toHaveKey('message_ref');
});

it('normalises a spaced international recipient rather than sending it raw', function () {
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->text('Hi', '+61 411 122 211');

    expect($mock->getLastPendingRequest()->body()->all()['recipient'])->toBe('61411122211');
});

it('strips punctuation from a local recipient without guessing a country', function () {
    // A leading-zero local number cannot be resolved to E.164 without knowing
    // the country, and the SDK does not pick one. Punctuation still goes, so
    // the value is never sent raw; the API rejects the remaining leading zero
    // with a 400, which is a loud failure rather than a silent misdial.
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->text('Hi', '0411 122 211');

    expect($mock->getLastPendingRequest()->body()->all()['recipient'])->toBe('0411122211');
});

it('serialises sms_fallback through SmsFallback::toArray()', function () {
    $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->template(
        'order_update',
        ['#12345'],
        '61411122211',
        fallback: new SmsFallback('Order #12345 has shipped.', '61481074185'),
    );

    expect($mock->getLastPendingRequest()->body()->all()['sms_fallback'])
        ->toBe(['message' => 'Order #12345 has shipped.', 'sender' => '61481074185']);
});

it('rejects a message_ref longer than the documented 500 characters', function () {
    new SendWhatsAppRequest(new TextContent('Hi'), '61411122211', messageRef: str_repeat('a', 501));
})->throws(ValidationException::class, '500');

// ---------------------------------------------------------------------------
// Reading back
// ---------------------------------------------------------------------------

it('reads the id out of the data envelope rather than a top-level id', function () {
    // WhatsApp wraps its payload. A decoy top-level id sits alongside the
    // envelope here, so a DTO reading $json['id'] would resolve the wrong
    // message rather than merely reading null.
    $wa = whatsAppResource([
        SendWhatsAppRequest::class => MockResponse::make([
            'id' => 'top-level-decoy',
            'data' => whatsAppMessage(),
        ], 200),
    ])->text('Hi', '61411122211');

    expect($wa->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4');
});

it('reads one WhatsApp message by id', function () {
    $mock = new MockClient([GetWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $wa = (new WhatsAppResource($connector))->get('6fdae71c-dad7-4c36-9734-a69693ecf3b4');

    expect($wa->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4')
        ->and((string) $mock->getLastPendingRequest()->getUri())
        ->toBe('https://api.transmitmessage.com/v2/whatsapp/messages/6fdae71c-dad7-4c36-9734-a69693ecf3b4');
});

it('sends no body on the GET reader', function () {
    // Readers extend KudosityV2Request, which carries no body: a JSON body on
    // a GET — even an empty one — is stripped or rejected by some gateways.
    $mock = new MockClient([GetWhatsAppRequest::class => MockResponse::make(whatsAppSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WhatsAppResource($connector))->get('abc');

    $pending = $mock->getLastPendingRequest();

    expect($pending->headers()->get('Content-Type'))->toBeNull()
        ->and((string) $pending->body())->toBe('');
});

it('turns a 404 into a NotFoundException', function () {
    whatsAppResource([GetWhatsAppRequest::class => MockResponse::make(['error' => 'WhatsApp message not found'], 404)])
        ->get('nope');
})->throws(NotFoundException::class, 'WhatsApp message not found');

// ---------------------------------------------------------------------------
// The list endpoint
// ---------------------------------------------------------------------------

it('declares itself cursor-paginated, with items at data.messages walked forwards', function () {
    expect(new ListWhatsAppRequest)->toBeInstanceOf(PaginatesV2Cursor::class)
        ->and((new ListWhatsAppRequest)->paginationItemsKey())->toBe('data.messages')
        ->and((new ListWhatsAppRequest)->paginationDirection())->toBe('next');
});

it('pages the list endpoint, reading items out of the data.messages path', function () {
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient([
        ListWhatsAppRequest::class => MockResponse::make(whatsAppListBody(), 200),
    ]));

    $items = iterator_to_array((new WhatsAppResource($connector))->list()->items());

    expect($items)->toHaveCount(2)
        ->and($items[0]['id'])->toBe('6fdae71c-dad7-4c36-9734-a69693ecf3b4')
        ->and($items[1]['id'])->toBe('second');
});

it('passes list filters through as query parameters', function () {
    $mock = new MockClient([ListWhatsAppRequest::class => MockResponse::make(whatsAppListBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array((new WhatsAppResource($connector))->list(
        dateRange: 'custom_date',
        startDate: '2026-07-01',
        endDate: '2026-07-31',
        campaignId: 'camp-1',
    )->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('date_range'))->toBe('custom_date')
        ->and($query->get('start_date'))->toBe('2026-07-01')
        ->and($query->get('end_date'))->toBe('2026-07-31')
        ->and($query->get('campaign_id'))->toBe('camp-1')
        ->and($query->get('direction'))->toBe('next');
});

it('sends no filter query parameters when none are given', function () {
    $mock = new MockClient([ListWhatsAppRequest::class => MockResponse::make(whatsAppListBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array((new WhatsAppResource($connector))->list()->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('date_range'))->toBeNull()
        ->and($query->get('start_date'))->toBeNull()
        ->and($query->get('end_date'))->toBeNull()
        ->and($query->get('campaign_id'))->toBeNull();
});

it('accepts every documented date_range value that needs no window', function (string $dateRange) {
    // An allow-list assertion, not a deny-list: all five documented values must
    // be accepted — the fifth, custom_date, immediately below because it also
    // needs its dates — and the unlisted value must not be.
    expect(new ListWhatsAppRequest(dateRange: $dateRange))->toBeInstanceOf(ListWhatsAppRequest::class);
})->with(['last_week', 'last_thirty', 'last_month', 'all']);

it('accepts custom_date when both dates are supplied', function () {
    expect(new ListWhatsAppRequest(dateRange: 'custom_date', startDate: '2026-07-01', endDate: '2026-07-31'))
        ->toBeInstanceOf(ListWhatsAppRequest::class);
});

it('rejects a date_range outside the documented set', function () {
    // The asserted fragment belongs to the allow-list rule alone — the pairing
    // rule below phrases itself differently — and 'yesterday' cannot trigger
    // the pairing rule anyway.
    new ListWhatsAppRequest(dateRange: 'yesterday');
})->throws(ValidationException::class, 'date_range must be one of');

it('rejects custom_date with neither date, because the API answers a generic 400', function () {
    // date_range itself is valid here, so the pairing rule is the only one
    // that can fire.
    new ListWhatsAppRequest(dateRange: 'custom_date');
})->throws(ValidationException::class, 'custom_date');

it('rejects custom_date with only start_date', function () {
    new ListWhatsAppRequest(dateRange: 'custom_date', startDate: '2026-07-01');
})->throws(ValidationException::class, 'custom_date');

it('rejects custom_date with only end_date', function () {
    new ListWhatsAppRequest(dateRange: 'custom_date', endDate: '2026-07-31');
})->throws(ValidationException::class, 'custom_date');

// ---------------------------------------------------------------------------
// WhatsAppMessageData
// ---------------------------------------------------------------------------

it('keeps content raw, because the response echoes whichever variant was sent', function () {
    $wa = WhatsAppMessageData::fromArray(whatsAppMessage([
        'content' => ['template' => ['name' => 'order_update', 'parameters' => ['#12345']]],
    ]));

    expect($wa->content)->toBe(['template' => ['name' => 'order_update', 'parameters' => ['#12345']]]);
});

it('leaves status null when the send response omits it', function () {
    // The documented send response carries no status field at all, so a
    // non-nullable status would have to invent one.
    expect(WhatsAppMessageData::fromArray(whatsAppMessage())->status)->toBeNull();
});

it('resolves a present status through MessageStatus::fromApi()', function () {
    expect(WhatsAppMessageData::fromArray(whatsAppMessage(['status' => 'queued']))->status)
        ->toBe(MessageStatus::Queued);
});

it('reads campaign_id when present and leaves it null otherwise', function () {
    expect(WhatsAppMessageData::fromArray(whatsAppMessage(['campaign_id' => 'camp-1']))->campaignId)->toBe('camp-1')
        ->and(WhatsAppMessageData::fromArray(whatsAppMessage())->campaignId)->toBeNull();
});

it('parses sms_fallback off a response into an SmsFallback', function () {
    $wa = WhatsAppMessageData::fromArray(whatsAppMessage([
        'sms_fallback' => ['sender' => '61481074185', 'message' => 'Order #12345 has shipped.'],
    ]));

    expect($wa->smsFallback)->toBeInstanceOf(SmsFallback::class)
        ->and($wa->smsFallback?->message)->toBe('Order #12345 has shipped.')
        ->and($wa->smsFallback?->sender)->toBe('61481074185');
});

it('leaves smsFallback null when the response omits it', function () {
    expect(WhatsAppMessageData::fromArray(whatsAppMessage())->smsFallback)->toBeNull();
});

it('leaves smsFallback null when a response carries a fallback with no message, rather than throwing', function () {
    // SmsFallback's constructor requires a message — correct for a
    // request-shaped object, wrong to impose on a response we do not control.
    // The invariant stays; the DTO only builds a fallback when the response
    // actually carries a non-empty message.
    $wa = WhatsAppMessageData::fromArray(whatsAppMessage(['sms_fallback' => ['sender' => '61481074185']]));

    expect($wa->smsFallback)->toBeNull();
});

it('parses the nine-fractional-digit created_at timestamp that RFC3339_EXTENDED rejects', function () {
    $wa = WhatsAppMessageData::fromArray(whatsAppMessage(['created_at' => '2022-03-29T04:42:01.631708761Z']));

    expect($wa->createdAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($wa->createdAt)->not->toBeNull();
});

it('returns null for a malformed created_at rather than throwing', function () {
    expect(WhatsAppMessageData::fromArray(whatsAppMessage(['created_at' => 'not-a-date']))->createdAt)->toBeNull();
});
