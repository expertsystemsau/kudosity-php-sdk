<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\OptOutSource;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use ExpertSystems\Kudosity\Webhooks\OptOutEvent;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\UnknownEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;

/**
 * A real delivery, captured against the live API.
 *
 * Loaded from disk rather than inlined: the fixture is the evidence, and a
 * pasted copy stops tracking it the moment either drifts. See
 * tests/Fixtures/V2Webhooks/README.md for what each one pins.
 */
function webhookFixture(string $name): array
{
    $path = dirname(__DIR__).'/Fixtures/V2Webhooks/'.$name.'.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A documented payload shape with no fixture yet — the skill's own example.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function documentedWebhook(string $eventType, array $payload): array
{
    return array_merge([
        'event_type' => $eventType,
        'timestamp' => '2026-08-05T12:00:00Z',
        'webhook_id' => '8aa2842d-8931-4f6f-b55a-826a6698699a',
        'webhook_name' => 'documented example',
    ], $payload);
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

it('dispatches each captured payload to its event class', function (string $fixture, string $expected) {
    expect(WebhookEvent::fromArray(webhookFixture($fixture)))->toBeInstanceOf($expected);
})->with([
    ['sms-status-sent', StatusEvent::class],
    ['sms-status-delivered', StatusEvent::class],
    ['mms-status-delivered', StatusEvent::class],
    ['sms-inbound-with-last-message', InboundEvent::class],
    ['link-hit-sms', LinkHitEvent::class],
    ['link-hit-sms-repeat', LinkHitEvent::class],
]);

it('dispatches all ten documented event types to the right payload shape', function (string $eventType, string $expected) {
    // Four shapes, ten types. The mapping is the thing a consumer cannot see
    // from the event name alone — WHATSAPP_STATUS and RCS_STATUS share
    // StatusEvent with SMS, which is why SMS_STATUS not reporting WhatsApp is
    // surprising in the first place.
    $bodies = [
        'status' => ['status' => ['id' => 'x', 'status' => 'SENT', 'recipient' => '614', 'sender' => '614']],
        'mo' => ['mo' => ['id' => 'x', 'message' => 'hi', 'recipient' => '614', 'sender' => '614']],
        'link_hit' => ['link_hit' => ['hits' => 1, 'url' => 'https://e.com']],
        'opt_out' => ['opt_out' => ['source' => 'SMS_INBOUND']],
    ];

    $key = match (true) {
        str_ends_with($eventType, '_STATUS') => 'status',
        str_ends_with($eventType, '_INBOUND') => 'mo',
        $eventType === 'LINK_HIT' => 'link_hit',
        default => 'opt_out',
    };

    expect(WebhookEvent::fromArray(documentedWebhook($eventType, $bodies[$key])))
        ->toBeInstanceOf($expected);
})->with([
    ['SMS_STATUS', StatusEvent::class],
    ['MMS_STATUS', StatusEvent::class],
    ['WHATSAPP_STATUS', StatusEvent::class],
    ['RCS_STATUS', StatusEvent::class],
    ['SMS_INBOUND', InboundEvent::class],
    ['MMS_INBOUND', InboundEvent::class],
    ['WHATSAPP_INBOUND', InboundEvent::class],
    ['RCS_INBOUND', InboundEvent::class],
    ['LINK_HIT', LinkHitEvent::class],
    ['OPT_OUT', OptOutEvent::class],
]);

it('resolves an event type Kudosity has not published yet to an UnknownEvent, not an exception', function () {
    // A receiver does not choose what it is sent, and a 500 here reads as a
    // dead endpoint from Kudosity's side — which triggers a retry into the
    // same 500.
    $payload = webhookFixture('sms-status-sent');
    $payload['event_type'] = 'TELEPATHY_STATUS';

    $event = WebhookEvent::fromArray($payload);

    expect($event)->toBeInstanceOf(UnknownEvent::class)
        ->and($event->eventType)->toBe(WebhookEventType::Unknown)
        ->and($event->messageRef())->toBeNull()
        ->and($event->raw['event_type'])->toBe('TELEPATHY_STATUS');
});

it('keeps the raw payload verbatim so an undocumented field is never lost', function () {
    // Three undocumented fields have already turned up in live payloads. raw()
    // is the escape hatch for the fourth.
    $payload = webhookFixture('mms-status-delivered');
    $payload['some_field_shipped_next_quarter'] = ['nested' => true];

    expect(WebhookEvent::fromArray($payload)->raw['some_field_shipped_next_quarter'])
        ->toBe(['nested' => true]);
});

// ---------------------------------------------------------------------------
// The uniform correlation accessor — the whole point of the base class
// ---------------------------------------------------------------------------

it('reads the correlation key through one accessor regardless of where it hides', function (string $fixture) {
    // Four event categories keep message_ref at four different paths. Every
    // captured fixture in this run carries the same ref, so one expectation
    // covers all of them — and a class reading the wrong path fails here.
    expect(WebhookEvent::fromArray(webhookFixture($fixture))->messageRef())
        ->toBeIn(['order-9931:cust-4471', 'linkhit-8842:cust-4471']);
})->with([
    'status.message_ref' => ['sms-status-delivered'],
    'mo.last_message.message_ref' => ['sms-inbound-with-last-message'],
    'link_hit.source_message.message_ref' => ['link-hit-sms'],
]);

it('reads the opt-out correlation key from opt_out.source_message', function () {
    $event = WebhookEvent::fromArray(documentedWebhook('OPT_OUT', [
        'opt_out' => [
            'source' => 'SMS_INBOUND',
            'source_message' => ['id' => 'a51ebe4e', 'message_ref' => 'ncc5009d', 'recipient' => '61435790000', 'sender' => '61481074190'],
        ],
    ]));

    expect($event->messageRef())->toBe('ncc5009d');
});

it('does not read the inbound ref off mo, which has no message_ref of its own', function () {
    // The trap this accessor exists for: mo carries the customer's message,
    // last_message carries yours. Only the latter has the ref, so a class
    // reading mo.message_ref returns null and the reply goes unrouted.
    $payload = webhookFixture('sms-inbound-with-last-message');

    expect($payload['mo'])->not->toHaveKey('message_ref')
        ->and(WebhookEvent::fromArray($payload)->messageRef())->toBe('order-9931:cust-4471');
});

// ---------------------------------------------------------------------------
// StatusEvent
// ---------------------------------------------------------------------------

it('carries the three payload fields the documentation never mentions', function () {
    $status = WebhookEvent::fromArray(webhookFixture('mms-status-delivered'));

    expect($status->webhookId)->toBe('8aa2842d-8931-4f6f-b55a-826a6698699a')
        ->and($status->webhookName)->toBe('SDK 2.0 phase 4 recon')
        ->and($status->description)->toBe('Sent to Vodafone (response status details: Success)');
});

it('leaves description null on the channels that do not send it', function () {
    expect(WebhookEvent::fromArray(webhookFixture('sms-status-delivered'))->description)->toBeNull();
});

it('resolves an UPPERCASE webhook status through the same enum as a lowercase send response', function () {
    // Webhooks send DELIVERED; POST /v2/sms answers "delivered". One enum,
    // case-insensitive, which is load-bearing rather than defensive.
    expect(WebhookEvent::fromArray(webhookFixture('sms-status-delivered'))->status)
        ->toBe(MessageStatus::Delivered)
        ->and(MessageStatus::fromApi('delivered'))->toBe(MessageStatus::Delivered);
});

it('accepts an MMS_STATUS of DELIVERED, which the documentation says cannot happen', function () {
    // The skill says MMS carries "internal statuses only — SENT, FAILED". The
    // captured fixture is DELIVERED. The fixture wins, so nothing constrains
    // MMS status to a subset.
    expect(WebhookEvent::fromArray(webhookFixture('mms-status-delivered'))->status)
        ->toBe(MessageStatus::Delivered);
});

it('keeps one stable status.id across every status event for a message', function () {
    // The key the idempotency guard rests on. Both fixtures are the same
    // message, two events, four seconds apart.
    $sent = WebhookEvent::fromArray(webhookFixture('sms-status-sent'));
    $delivered = WebhookEvent::fromArray(webhookFixture('sms-status-delivered'));

    expect($sent->id)->toBe($delivered->id)
        ->and($sent->status)->toBe(MessageStatus::Sent)
        ->and($delivered->status)->toBe(MessageStatus::Delivered);
});

it('normalises an empty routed_via to null rather than reporting a shared number', function () {
    // The live API sends "" when no shared number was involved. "" is not a
    // number, and a truthiness check on it would report shared routing.
    $payload = webhookFixture('sms-status-delivered');
    $payload['status']['routed_via'] = '';

    expect(WebhookEvent::fromArray($payload)->routedVia)->toBeNull();
});

it('exposes routed_via when a shared number delivered the message', function () {
    $payload = webhookFixture('sms-status-delivered');
    $payload['status']['routed_via'] = '447507333300';

    expect(WebhookEvent::fromArray($payload)->routedVia)->toBe('447507333300');
});

// ---------------------------------------------------------------------------
// InboundEvent
// ---------------------------------------------------------------------------

it('does not trim inbound message text', function () {
    // The real captured reply is "YES " with a trailing space. A DTO that trims
    // hides what the customer actually sent, and the decision about what
    // "equal" means belongs at the comparison, not the parse.
    expect(WebhookEvent::fromArray(webhookFixture('sms-inbound-with-last-message'))->message)
        ->toBe('YES ');
});

it('exposes the attached outbound text verbatim too, not just the inbound', function () {
    // SourceMessage is a separate parse path from InboundEvent's own message, so
    // it needs its own assertion — a mutation that trimmed only this one went
    // unnoticed until it had one. The outbound text is what YOU sent, and code
    // reconciling it against a template needs it unmodified.
    $payload = webhookFixture('sms-inbound-with-last-message');
    $payload['mo']['last_message']['message'] = "  Order #12345 shipped.\n";

    expect(WebhookEvent::fromArray($payload)->lastMessage?->message)
        ->toBe("  Order #12345 shipped.\n");
});

it('keeps the customer as sender and your own number as recipient on an inbound event', function () {
    // The reverse of an outbound, and the source of the filter surprise: the
    // webhook filter's `sender` key matches mo.recipient for inbound events,
    // i.e. it filters by YOUR number.
    $inbound = WebhookEvent::fromArray(webhookFixture('sms-inbound-with-last-message'));

    expect($inbound->sender)->toBe('61400000000')
        ->and($inbound->recipient)->toBe('61481074185')
        ->and($inbound->lastMessage?->sender)->toBe('61481074185')
        ->and($inbound->lastMessage?->recipient)->toBe('61400000000');
});

it('reports an inbound with last_message as correlated, and carries the outbound text', function () {
    $inbound = WebhookEvent::fromArray(webhookFixture('sms-inbound-with-last-message'));

    expect($inbound->isCorrelated())->toBeTrue()
        ->and($inbound->lastMessage?->message)
        ->toContain('Please reply YES to this message');
});

it('leaves lastMessage null when Kudosity found no recent outbound to attach', function () {
    // Best-effort by documentation. An unsolicited inbound can be neither
    // correlated nor authenticated, so null is a valid state rather than a
    // parse failure — and isCorrelated() is how a consumer branches on it.
    $payload = webhookFixture('sms-inbound-with-last-message');
    unset($payload['mo']['last_message']);

    $inbound = WebhookEvent::fromArray($payload);

    expect($inbound->lastMessage)->toBeNull()
        ->and($inbound->messageRef())->toBeNull()
        ->and($inbound->isCorrelated())->toBeFalse()
        ->and($inbound->message)->toBe('YES ');
});

it('models an inbound MMS subject and content_urls', function () {
    // No MMS_INBOUND fixture exists — two picture replies produced no event at
    // all against a verified-reachable endpoint, which points at provisioning
    // and is an open question with Kudosity. Shape from the documented example.
    $inbound = WebhookEvent::fromArray(documentedWebhook('MMS_INBOUND', [
        'mo' => [
            'type' => 'MMS',
            'id' => 'mo-1',
            'message' => 'here you go',
            'recipient' => '61481074190',
            'sender' => '447507222200',
            'subject' => 'My picture',
            'content_urls' => ['https://example.com/a.jpg', 42, 'https://example.com/b.jpg'],
        ],
    ]));

    expect($inbound->subject)->toBe('My picture')
        // The non-string is dropped rather than cast: a URL that is not a
        // string is not a URL, and "42" would be a broken link.
        ->and($inbound->contentUrls)->toBe(['https://example.com/a.jpg', 'https://example.com/b.jpg']);
});

// ---------------------------------------------------------------------------
// LinkHitEvent
// ---------------------------------------------------------------------------

it('reads the original destination from url and the shortened link from the message', function () {
    // The pairing that catches people: link_hit.url is where the recipient was
    // going, source_message.message is what was actually sent — carrying the
    // SHORTENED link. Code looking for the original URL in the text finds the
    // short one.
    $hit = WebhookEvent::fromArray(webhookFixture('link-hit-sms'));

    expect($hit->url)->toBe('https://www.example.com/abc')
        ->and($hit->sourceMessage?->message)->toContain('https://tapth.at/')
        ->and($hit->sourceMessage?->message)->not->toContain('www.example.com');
});

it('counts hits cumulatively for the tracked link rather than per unique recipient', function () {
    // Two real deliveries for ONE send, sixteen seconds apart. The first was a
    // machine prefetch — it arrived in the same second as DELIVERED, about two
    // seconds after the send — and the second was the human tap. So hits is not
    // an engagement count, and isFirstHit() is named for the fetch, not a click.
    $first = WebhookEvent::fromArray(webhookFixture('link-hit-sms'));
    $second = WebhookEvent::fromArray(webhookFixture('link-hit-sms-repeat'));

    expect($first->hits)->toBe(1)
        ->and($first->isFirstHit())->toBeTrue()
        ->and($second->hits)->toBe(2)
        ->and($second->isFirstHit())->toBeFalse()
        // Same message, same link, same correlation key: the repeat is not a
        // second send.
        ->and($second->sourceMessage?->id)->toBe($first->sourceMessage?->id)
        ->and($second->url)->toBe($first->url);
});

it('casts a string hits count to int, since V2 sends numerics as strings elsewhere', function () {
    $payload = webhookFixture('link-hit-sms');
    $payload['link_hit']['hits'] = '7';

    expect(WebhookEvent::fromArray($payload)->hits)->toBe(7);
});

it('models an MMS link hit whose source_message carries subject and content_urls', function () {
    $hit = WebhookEvent::fromArray(documentedWebhook('LINK_HIT', [
        'link_hit' => [
            'hits' => 1,
            'url' => 'https://www.example.com/abc',
            'source_message' => [
                'type' => 'MMS',
                'id' => 'faf68308',
                'message' => 'Check this out http://clckme.info/KYhSsuIH',
                'message_ref' => 'D301',
                'recipient' => '61435795809',
                'sender' => '61481074185',
                'subject' => 'Sale',
                'content_urls' => ['https://example.com/promo.png'],
            ],
        ],
    ]));

    expect($hit->sourceMessage?->subject)->toBe('Sale')
        ->and($hit->sourceMessage?->contentUrls)->toBe(['https://example.com/promo.png'])
        ->and($hit->sourceMessage?->type)->toBe('MMS');
});

// ---------------------------------------------------------------------------
// OptOutEvent
// ---------------------------------------------------------------------------

it('resolves both documented opt-out sources, and treats an unknown one as an opt-out', function (?string $source, OptOutSource $expected) {
    // No OPT_OUT fixture and deliberately none: triggering it means replying
    // STOP, which opts the only test handset out of receiving messages.
    $event = WebhookEvent::fromArray(documentedWebhook('OPT_OUT', [
        'opt_out' => [
            'source' => $source,
            'source_message' => ['id' => 'a51ebe4e', 'recipient' => '61435790000', 'sender' => '61481074190'],
        ],
    ]));

    expect($event)->toBeInstanceOf(OptOutEvent::class)
        ->and($event->source)->toBe($expected);
})->with([
    'STOP reply' => ['SMS_INBOUND', OptOutSource::SmsInbound],
    'opt-out link' => ['LINK_HIT', OptOutSource::LinkHit],
    'undocumented' => ['CARRIER_BLOCKLIST', OptOutSource::Unknown],
    'absent' => [null, OptOutSource::Unknown],
]);

it('reports the number that opted out as the source message recipient, not its sender', function () {
    // On an opt-out the source_message is the outbound they reacted to, so the
    // person is its recipient. Reading `sender` here returns your own number
    // and would opt YOU out of your own list.
    $event = WebhookEvent::fromArray(documentedWebhook('OPT_OUT', [
        'opt_out' => [
            'source' => 'SMS_INBOUND',
            'source_message' => ['id' => 'a51ebe4e', 'recipient' => '61435790000', 'sender' => '61481074190'],
        ],
    ]));

    expect($event->optedOutNumber())->toBe('61435790000');
});

// ---------------------------------------------------------------------------
// Timestamps and shared parsing
// ---------------------------------------------------------------------------

it('parses the delivery timestamp', function () {
    expect(WebhookEvent::fromArray(webhookFixture('sms-status-sent'))->timestamp?->format('c'))
        ->toBe('2026-08-05T11:57:59+00:00');
});

it('returns null for an absent or malformed timestamp rather than throwing', function (mixed $value) {
    $payload = webhookFixture('sms-status-sent');
    $payload['timestamp'] = $value;

    expect(WebhookEvent::fromArray($payload)->timestamp)->toBeNull();
})->with([
    'malformed' => ['not-a-date'],
    'empty' => [''],
    'null' => [null],
    'non-string' => [12345],
]);

it('parses a nine-fractional-digit timestamp, which RFC3339_EXTENDED rejects', function () {
    // The webhook resource's created_at/updated_at use this format too.
    $payload = webhookFixture('sms-status-sent');
    $payload['timestamp'] = '2026-08-05T11:57:59.730743151Z';

    expect(WebhookEvent::fromArray($payload)->timestamp)->not->toBeNull();
});

it('survives a payload whose section key is missing or the wrong type', function (mixed $section) {
    // A truncated or hostile POST body must not fatal a public endpoint.
    $event = WebhookEvent::fromArray([
        'event_type' => 'SMS_STATUS',
        'status' => $section,
    ]);

    expect($event)->toBeInstanceOf(StatusEvent::class)
        ->and($event->id)->toBe('')
        ->and($event->status)->toBe(MessageStatus::Unknown)
        ->and($event->messageRef())->toBeNull();
})->with([
    'missing' => [null],
    'a string' => ['nope'],
    'a list' => [[1, 2, 3]],
]);
