<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\GetWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The webhook resource shape, verbatim from a live `POST /v2/webhook` response.
 *
 * Note the nine-fractional-digit timestamps; a read response returns six.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function webhookBody(array $overrides = []): array
{
    return array_merge([
        'id' => '8ab7060c-6c74-482b-baf6-8e7ef36cdf63',
        'name' => 'SDK 2.0 link-hit capture',
        'url' => 'https://example.com/webhooks/kudosity',
        'filter' => ['event_type' => ['LINK_HIT', 'SMS_STATUS']],
        'rate_limit' => 0,
        'is_sandbox' => false,
        'created_at' => '2026-08-05T15:23:11.730743151Z',
        'updated_at' => '2026-08-05T15:23:11.730743151Z',
    ], $overrides);
}

/**
 * @param  array<class-string, MockResponse>  $responses
 */
function webhooksResource(array $responses): WebhooksResource
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return new WebhooksResource($connector);
}

// ---------------------------------------------------------------------------
// create
// ---------------------------------------------------------------------------

it('creates a webhook and returns a typed DTO built from the 201 body', function () {
    // Asserted on the DTO's contents rather than the status code: Saloon treats
    // any 2xx as success, so "it accepts a 201" passes trivially and proves
    // nothing. What matters is that the 201's body was parsed.
    $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(webhookBody(), 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $hook = (new WebhooksResource($connector))->create(
        'SDK 2.0 link-hit capture',
        'https://example.com/webhooks/kudosity',
        [WebhookEventType::LinkHit, WebhookEventType::SmsStatus],
    );

    expect($hook)->toBeInstanceOf(WebhookData::class)
        ->and($hook->id)->toBe('8ab7060c-6c74-482b-baf6-8e7ef36cdf63')
        ->and($hook->name)->toBe('SDK 2.0 link-hit capture')
        ->and($hook->filter->eventType)->toBe(['LINK_HIT', 'SMS_STATUS'])
        ->and($mock->getLastPendingRequest()?->getMethod()->value)->toBe('POST');
});

it('sends event types under filter.event_type and never as a top-level event_type', function () {
    // The top-level field is deprecated upstream and silently ignored, so sending
    // it LOOKS like it worked — the registration is created, just not filtered.
    // Its absence is the assertion.
    $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(webhookBody(), 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->create('Events', 'https://e.com/h', [WebhookEventType::SmsStatus]);

    $body = $mock->getLastPendingRequest()?->body()?->all();

    expect($body)->toHaveKey('filter')
        ->and($body['filter'])->toBe(['event_type' => ['SMS_STATUS']])
        ->and($body)->not->toHaveKey('event_type');
});

it('accepts enum cases and raw strings in the same filter', function () {
    // Enums are the point of having them, but a filter read back from the API is
    // strings — refusing those would make round-tripping needlessly awkward.
    $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(webhookBody(), 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->create('Events', 'https://e.com/h', [
        WebhookEventType::SmsStatus,
        'MMS_STATUS',
    ]);

    expect($mock->getLastPendingRequest()?->body()?->all()['filter'])
        ->toBe(['event_type' => ['SMS_STATUS', 'MMS_STATUS']]);
});

it('omits the filter entirely when no event types or filters are given', function () {
    // An absent filter means every event type. Sending `"filter": {}` is a
    // different request, and "match nothing" is not what a caller who left the
    // parameter alone meant.
    $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(webhookBody(), 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->create('Everything', 'https://e.com/h');

    expect($mock->getLastPendingRequest()?->body()?->all())->toBe([
        'name' => 'Everything',
        'url' => 'https://e.com/h',
    ]);
});

it('sends a full filter with statuses, senders and refs, dropping the empty parts', function () {
    $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(webhookBody(), 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->create('Failures', 'https://e.com/h', filter: new WebhookFilter(
        eventType: [WebhookEventType::SmsStatus],
        status: [MessageStatus::Failed, MessageStatus::HardBounce],
        sender: ['61481074185'],
    ));

    expect($mock->getLastPendingRequest()?->body()?->all()['filter'])->toBe([
        'event_type' => ['SMS_STATUS'],
        'sender' => ['61481074185'],
        'status' => ['FAILED', 'HARD_BOUNCE'],
    ]);
});

it('lets an explicit event-type list win over one already in the filter', function () {
    $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(webhookBody(), 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->create(
        'Events',
        'https://e.com/h',
        [WebhookEventType::OptOut],
        new WebhookFilter(eventType: [WebhookEventType::SmsStatus], sender: ['61481074185']),
    );

    $filter = $mock->getLastPendingRequest()?->body()?->all()['filter'];

    // The shortcut replaces event_type but must not discard the rest of the filter.
    expect($filter['event_type'])->toBe(['OPT_OUT'])
        ->and($filter['sender'])->toBe(['61481074185']);
});

it('sends rate_limit only when given, since 0 means the system default', function () {
    $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(webhookBody(), 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);
    $resource = new WebhooksResource($connector);

    $resource->create('Rate limited', 'https://e.com/h');
    expect($mock->getLastPendingRequest()?->body()?->all())->not->toHaveKey('rate_limit');

    $resource->create('Rate limited', 'https://e.com/h', rateLimit: 250);
    expect($mock->getLastPendingRequest()?->body()?->all()['rate_limit'])->toBe(250);
});

// ---------------------------------------------------------------------------
// Client-side guards
// ---------------------------------------------------------------------------

it('rejects a non-HTTPS webhook URL, which is stricter than the platform', function (string $url) {
    // The docs require HTTPS; a live probe registering http://example.com/x
    // returned 201, so the API does NOT enforce it. Rejected here anyway:
    // deliveries carry message text and phone numbers, and they are unsigned, so
    // a plaintext endpoint is readable and forgeable by anyone on the path.
    new CreateWebhookRequest('Events', $url);
})->with([
    'plain http' => ['http://example.com/hook'],
    'uppercase scheme' => ['HTTP://example.com/hook'],
    'no scheme' => ['example.com/hook'],
    'ftp' => ['ftp://example.com/hook'],
])->throws(ValidationException::class, 'must use HTTPS');

it('accepts an uppercase HTTPS scheme', function () {
    // The scheme check is case-insensitive in both directions, or a legitimate
    // URL gets rejected for its capitalisation.
    expect(new CreateWebhookRequest('Events', 'HTTPS://example.com/hook'))
        ->toBeInstanceOf(CreateWebhookRequest::class);
});

it('rejects a name outside the documented length, naming the bounds', function (string $name) {
    // Each rule gets its own single-violation input: these URLs are valid HTTPS,
    // so only the name rule can fire.
    new CreateWebhookRequest($name, 'https://example.com/hook');
})->with([
    'one character' => ['x'],
    'empty' => [''],
    '101 characters' => [str_repeat('a', 101)],
])->throws(ValidationException::class, 'must be between 2 and 100');

it('accepts a name at both documented bounds', function (string $name) {
    expect(new CreateWebhookRequest($name, 'https://example.com/hook'))
        ->toBeInstanceOf(CreateWebhookRequest::class);
})->with([
    'two characters' => ['ab'],
    '100 characters' => [str_repeat('a', 100)],
]);

it('rejects a rate limit outside the documented range', function (int $limit) {
    new CreateWebhookRequest('Events', 'https://example.com/hook', rateLimit: $limit);
})->with([
    'negative' => [-1],
    'above the ceiling' => [10_001],
])->throws(ValidationException::class, 'must be between 0 and 10000');

it('accepts 0 as the system default and the documented ceiling', function (int $limit) {
    expect(new CreateWebhookRequest('Events', 'https://example.com/hook', rateLimit: $limit))
        ->toBeInstanceOf(CreateWebhookRequest::class);
})->with([
    'system default' => [0],
    'ceiling' => [10_000],
]);

it('applies the same guards to an update as to a create', function () {
    // Shared guards rather than a second copy, so the two cannot drift into
    // accepting different things.
    expect(fn () => new UpdateWebhookRequest('id-1', 'Events', 'http://example.com/hook'))
        ->toThrow(ValidationException::class, 'must use HTTPS')
        ->and(fn () => new UpdateWebhookRequest('id-1', 'x', 'https://example.com/hook'))
        ->toThrow(ValidationException::class, 'must be between 2 and 100');
});

// ---------------------------------------------------------------------------
// all()
// ---------------------------------------------------------------------------

it('reads the list out of the named webhooks key', function () {
    $hooks = webhooksResource([
        ListWebhooksRequest::class => MockResponse::make([
            // A decoy top-level id: a wrong read resolves this rather than null,
            // which is a failure that looks like success.
            'id' => 'decoy-not-a-webhook',
            'webhooks' => [webhookBody(), webhookBody(['id' => 'second', 'name' => 'Another'])],
        ], 200),
    ])->all();

    expect($hooks)->toHaveCount(2)
        ->and($hooks[0])->toBeInstanceOf(WebhookData::class)
        ->and($hooks[0]->id)->toBe('8ab7060c-6c74-482b-baf6-8e7ef36cdf63')
        ->and($hooks[1]->id)->toBe('second');
});

it('returns an empty array when the account has no webhooks and the key is omitted', function () {
    // The live API answers `{}` — not `{"webhooks": []}` — when there are none.
    // Confirmed twice. A test using the empty-array form would not cover this.
    expect(webhooksResource([ListWebhooksRequest::class => MockResponse::make([], 200)])->all())
        ->toBe([]);
});

it('returns an empty array for an explicitly empty collection too', function () {
    expect(webhooksResource([ListWebhooksRequest::class => MockResponse::make(['webhooks' => []], 200)])->all())
        ->toBe([]);
});

it('skips a malformed entry rather than failing the whole list', function () {
    $hooks = webhooksResource([
        ListWebhooksRequest::class => MockResponse::make(['webhooks' => [webhookBody(), 'not-an-object', null]], 200),
    ])->all();

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]->id)->toBe('8ab7060c-6c74-482b-baf6-8e7ef36cdf63');
});

it('tolerates a webhooks key that is not a list at all', function () {
    expect(webhooksResource([ListWebhooksRequest::class => MockResponse::make(['webhooks' => 'nope'], 200)])->all())
        ->toBe([]);
});

it('declares no pagination, because neither the docs nor any live response has any', function () {
    // Asserted rather than assumed: a paginator contract here would mean guessing
    // a scheme, and Phase 3 already removed one speculative feature for that.
    expect(new ListWebhooksRequest)
        ->not->toBeInstanceOf(PaginatesV2Pages::class)
        ->and(new ListWebhooksRequest)
        ->not->toBeInstanceOf(PaginatesV2Cursor::class);
});

// ---------------------------------------------------------------------------
// get()
// ---------------------------------------------------------------------------

it('reads one registration by id, an endpoint the skill does not document', function () {
    // Confirmed by probing the live API: GET /v2/webhook/{id} answers 200 with
    // the same flat shape as the create response.
    $hook = webhooksResource([GetWebhookRequest::class => MockResponse::make(webhookBody(), 200)])
        ->get('8ab7060c-6c74-482b-baf6-8e7ef36cdf63');

    expect($hook->id)->toBe('8ab7060c-6c74-482b-baf6-8e7ef36cdf63')
        ->and($hook->url)->toBe('https://example.com/webhooks/kudosity');
});

it('sends no body on the GET reader', function () {
    // A body on a GET — even [] — is stripped or rejected by some gateways.
    $mock = new MockClient([GetWebhookRequest::class => MockResponse::make(webhookBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->get('id-1');

    expect($mock->getLastPendingRequest()?->body())->toBeNull();
});

it('turns a missing registration into a NotFoundException', function () {
    webhooksResource([GetWebhookRequest::class => MockResponse::make(['error' => 'webhook not found'], 404)])
        ->get('nope');
})->throws(NotFoundException::class, 'webhook not found');

// ---------------------------------------------------------------------------
// update() — replace, not patch
// ---------------------------------------------------------------------------

it('sends name and url in full on an update, because PUT replaces', function () {
    // Confirmed live: a PUT carrying only `url` answers 400 with
    // {"error":"Validation Error: name: length must be between 2 and 100"} — the
    // name was not preserved, it was missing. This is the test that documents it.
    $mock = new MockClient([UpdateWebhookRequest::class => MockResponse::make(webhookBody(['url' => 'https://e.com/new']), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->update(
        'id-1',
        'SDK 2.0 link-hit capture',
        'https://e.com/new',
        [WebhookEventType::SmsStatus],
    );

    $request = $mock->getLastPendingRequest();

    expect($request?->getMethod()->value)->toBe('PUT')
        ->and($request?->body()?->all())->toBe([
            'name' => 'SDK 2.0 link-hit capture',
            'url' => 'https://e.com/new',
            'filter' => ['event_type' => ['SMS_STATUS']],
        ]);
});

it('targets the registration id in the path', function () {
    expect((new UpdateWebhookRequest('abc-123', 'Events', 'https://e.com/h'))->resolveEndpoint())
        ->toBe('/v2/webhook/abc-123')
        ->and((new GetWebhookRequest('abc-123'))->resolveEndpoint())->toBe('/v2/webhook/abc-123')
        ->and((new DeleteWebhookRequest('abc-123'))->resolveEndpoint())->toBe('/v2/webhook/abc-123')
        ->and((new ListWebhooksRequest)->resolveEndpoint())->toBe('/v2/webhook')
        ->and((new CreateWebhookRequest('Events', 'https://e.com/h'))->resolveEndpoint())->toBe('/v2/webhook');
});

// ---------------------------------------------------------------------------
// delete()
// ---------------------------------------------------------------------------

it('reports a successful delete, which answers 200 rather than 204', function () {
    $mock = new MockClient([DeleteWebhookRequest::class => MockResponse::make([], 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    expect((new WebhooksResource($connector))->delete('id-1'))->toBeTrue()
        ->and($mock->getLastPendingRequest()?->getMethod()->value)->toBe('DELETE');
});

it('sends no body on the delete', function () {
    $mock = new MockClient([DeleteWebhookRequest::class => MockResponse::make([], 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new WebhooksResource($connector))->delete('id-1');

    expect($mock->getLastPendingRequest()?->body())->toBeNull();
});

it('throws rather than returning false when the registration does not exist', function () {
    webhooksResource([DeleteWebhookRequest::class => MockResponse::make(['error' => 'webhook not found'], 404)])
        ->delete('nope');
})->throws(NotFoundException::class);

// ---------------------------------------------------------------------------
// Error shape — this endpoint family is not RFC 9457
// ---------------------------------------------------------------------------

it('maps the plain string error body these endpoints return, not RFC 9457', function () {
    // Verbatim from the live 400. The messaging endpoints answer RFC 9457 problem
    // details; this family answers {"error": "..."}, and fromV2Response() has a
    // branch for it that must stay reachable through these requests.
    webhooksResource([
        CreateWebhookRequest::class => MockResponse::make(
            ['error' => 'Validation Error: name: length must be between 2 and 100'],
            400,
        ),
    ])->create('Events', 'https://example.com/hook');
})->throws(ValidationException::class, 'length must be between 2 and 100');

// ---------------------------------------------------------------------------
// WebhookData
// ---------------------------------------------------------------------------

it('carries the three fields the documentation never mentions', function () {
    $hook = WebhookData::fromArray(webhookBody(['is_sandbox' => true]));

    expect($hook->isSandbox)->toBeTrue()
        ->and($hook->createdAt)->not->toBeNull()
        ->and($hook->updatedAt)->not->toBeNull();
});

it('parses timestamps at either fractional precision the API sends', function () {
    // Nine digits on a create response, six on a read. A fixed format would
    // parse one and drop the other.
    expect(WebhookData::fromArray(webhookBody(['created_at' => '2026-08-05T15:23:11.730743151Z']))->createdAt)
        ->not->toBeNull()
        ->and(WebhookData::fromArray(webhookBody(['created_at' => '2026-08-05T15:48:45.072031Z']))->createdAt)
        ->not->toBeNull();
});

it('treats rate_limit 0 as the system default rather than a real limit', function () {
    expect(WebhookData::fromArray(webhookBody(['rate_limit' => 0]))->hasRateLimit())->toBeFalse()
        ->and(WebhookData::fromArray(webhookBody(['rate_limit' => 250]))->hasRateLimit())->toBeTrue()
        ->and(WebhookData::fromArray(webhookBody(['rate_limit' => 250]))->rateLimit)->toBe(250);
});

it('reports whether a registration read back from the API uses TLS', function () {
    // The platform accepts http:// even though the docs forbid it, so a plaintext
    // registration can exist — made by another tool, or before this SDK guarded it.
    expect(WebhookData::fromArray(webhookBody(['url' => 'https://e.com/h']))->isSecure())->toBeTrue()
        ->and(WebhookData::fromArray(webhookBody(['url' => 'http://e.com/h']))->isSecure())->toBeFalse()
        ->and(WebhookData::fromArray(webhookBody(['url' => 'HTTPS://e.com/h']))->isSecure())->toBeTrue();
});

it('survives a response with every optional field missing', function () {
    $hook = WebhookData::fromArray(['id' => 'x']);

    expect($hook->id)->toBe('x')
        ->and($hook->name)->toBe('')
        ->and($hook->filter->isEmpty())->toBeTrue()
        ->and($hook->rateLimit)->toBe(0)
        ->and($hook->isSandbox)->toBeFalse()
        ->and($hook->createdAt)->toBeNull();
});

// ---------------------------------------------------------------------------
// WebhookFilter
// ---------------------------------------------------------------------------

it('round-trips a filter through the API shape', function () {
    $filter = WebhookFilter::fromArray([
        'event_type' => ['SMS_STATUS', 'LINK_HIT'],
        'sender' => ['61481074185'],
        'status' => ['DELIVERED'],
        'message_ref' => ['order-1'],
        'campaign_id' => ['camp-1'],
    ]);

    expect($filter->toArray())->toBe([
        'event_type' => ['SMS_STATUS', 'LINK_HIT'],
        'sender' => ['61481074185'],
        'status' => ['DELIVERED'],
        'message_ref' => ['order-1'],
        'campaign_id' => ['camp-1'],
    ]);
});

it('drops empty values and non-strings rather than sending them', function () {
    $filter = new WebhookFilter(eventType: ['SMS_STATUS', '', 'LINK_HIT'], sender: []);

    expect($filter->toArray())->toBe(['event_type' => ['SMS_STATUS', 'LINK_HIT']]);
});

it('serialises event types as a JSON array even after dropping an entry', function () {
    // A gapped numeric array encodes as a JSON object, which the API would
    // reject — the trap TemplateContent's parameters had to be reindexed for.
    $filter = new WebhookFilter(eventType: ['', 'SMS_STATUS', '', 'LINK_HIT']);

    expect(json_encode($filter->toArray()))->toBe('{"event_type":["SMS_STATUS","LINK_HIT"]}');
});

it('reports an entirely empty filter as empty', function () {
    expect((new WebhookFilter)->isEmpty())->toBeTrue()
        ->and((new WebhookFilter(eventType: ['SMS_STATUS']))->isEmpty())->toBeFalse();
});
