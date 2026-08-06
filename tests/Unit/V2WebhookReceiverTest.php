<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Laravel\Events\KudosityInboundReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityLinkHitReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityOptOutReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityStatusReceived;
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;
use Illuminate\Support\Facades\Event;

/**
 * The signed query string for the events route.
 *
 * Built through the real CallbackUrlBuilder rather than hand-assembled, because
 * the point is that the receiver verifies what the installer produces.
 *
 * @return array<string, mixed>
 */
function signedEventsQuery(array $context = []): array
{
    /** @var CallbackUrlBuilder $builder */
    $builder = app(CallbackUrlBuilder::class);

    $url = $builder->build(CallbackType::DLR, 'App\\Handlers\\Events', $context);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

function postEvent(array $payload, ?array $query = null)
{
    $query = $query ?? signedEventsQuery();

    return test()->postJson('webhooks/kudosity/events?'.http_build_query($query), $payload);
}

// ---------------------------------------------------------------------------
// Dispatch, against the captured fixtures
// ---------------------------------------------------------------------------

it('dispatches the right typed event for each captured delivery', function (string $fixture, string $expected) {
    Event::fake();

    postEvent(webhookFixture($fixture))->assertOk();

    Event::assertDispatched($expected);
})->with([
    ['sms-status-sent', KudosityStatusReceived::class],
    ['sms-status-delivered', KudosityStatusReceived::class],
    ['mms-status-delivered', KudosityStatusReceived::class],
    ['sms-inbound-with-last-message', KudosityInboundReceived::class],
    ['link-hit-sms', KudosityLinkHitReceived::class],
    ['link-hit-sms-repeat', KudosityLinkHitReceived::class],
]);

it('dispatches an opt-out event from the documented payload', function () {
    Event::fake();

    postEvent(documentedWebhook('OPT_OUT', [
        'opt_out' => [
            'source' => 'SMS_INBOUND',
            'source_message' => ['id' => 'a1', 'recipient' => '61435790000', 'sender' => '61481074190'],
        ],
    ]))->assertOk();

    Event::assertDispatched(KudosityOptOutReceived::class, function (KudosityOptOutReceived $e) {
        return $e->optOut->optedOutNumber() === '61435790000';
    });
});

it('hands the typed event to the listener, not a raw array', function () {
    Event::fake();

    postEvent(webhookFixture('sms-status-delivered'))->assertOk();

    Event::assertDispatched(KudosityStatusReceived::class, function (KudosityStatusReceived $e) {
        return $e->status->status === MessageStatus::Delivered
            && $e->status->messageRef() === 'order-9931:cust-4471'
            && $e->status->id === '85fdf391-cfd8-4340-b004-419223c84e83';
    });
});

it('passes the signed URL context through to the event', function () {
    Event::fake();

    postEvent(webhookFixture('sms-status-sent'), signedEventsQuery(['order_id' => 991]));

    Event::assertDispatched(KudosityStatusReceived::class, function (KudosityStatusReceived $e) {
        return ($e->context['order_id'] ?? null) === 991;
    });
});

// ---------------------------------------------------------------------------
// Degrading rather than failing
// ---------------------------------------------------------------------------

it('accepts an event type this SDK does not recognise, rather than 500ing', function () {
    // A non-2xx tells Kudosity the endpoint is unhealthy and earns a retry into
    // the same failure. An unknown type is not retryable, so it is logged and
    // accepted.
    Event::fake();

    $payload = webhookFixture('sms-status-sent');
    $payload['event_type'] = 'TELEPATHY_STATUS';

    postEvent($payload)->assertOk();

    Event::assertNotDispatched(KudosityStatusReceived::class);
});

it('accepts a malformed body rather than 500ing', function (array $payload) {
    Event::fake();

    postEvent($payload)->assertOk();

    Event::assertNotDispatched(KudosityStatusReceived::class);
    Event::assertNotDispatched(KudosityInboundReceived::class);
})->with([
    'empty object' => [[]],
    'no event_type' => [[['status' => ['id' => 'x']]]],
    'status is a string' => [[['event_type' => 'SMS_STATUS', 'status' => 'nope']]],
]);

it('still dispatches when a status payload is present but hollow', function () {
    // A truncated delivery is not a reason to drop the event: the type is known,
    // so a listener gets it with empty fields and can decide.
    Event::fake();

    postEvent(['event_type' => 'SMS_STATUS', 'status' => []])->assertOk();

    Event::assertDispatched(KudosityStatusReceived::class, function (KudosityStatusReceived $e) {
        return $e->status->id === '' && $e->status->status === MessageStatus::Unknown;
    });
});

// ---------------------------------------------------------------------------
// Authenticity
// ---------------------------------------------------------------------------

it('refuses an unsigned delivery, even though the parser would allow it', function () {
    // CallbackUrlParser returns without verifying when neither handler nor context
    // is present — its "events-only mode", which is correct for the V1 GET routes.
    // Relying on it here would leave this route open to anyone who read the README,
    // since its default path is documented. The receiver requires the signature.
    Event::fake();

    test()->postJson('webhooks/kudosity/events?s=&h=', webhookFixture('sms-status-sent'))
        ->assertStatus(403);

    Event::assertNotDispatched(KudosityStatusReceived::class);
});

it('refuses a delivery with no query string at all', function () {
    Event::fake();

    test()->postJson('webhooks/kudosity/events', webhookFixture('sms-status-sent'))
        ->assertStatus(403);

    Event::assertNotDispatched(KudosityStatusReceived::class);
});

it('refuses a delivery whose signature does not verify', function () {
    Event::fake();

    $query = signedEventsQuery();
    // The signed URL's parameters are h (handler), c (context) and s (signature).
    $query['s'] = str_repeat('0', strlen((string) $query['s']));

    postEvent(webhookFixture('sms-status-sent'), $query)->assertStatus(403);

    Event::assertNotDispatched(KudosityStatusReceived::class);
});

it('reuses the V1 signed-URL scheme rather than a second verifier', function () {
    // There is no signature on a V2 delivery to verify, so the unguessable URL is
    // the only check available — and CallbackUrlParser already implements it.
    // Asserted by round-tripping a builder-produced URL through the receiver.
    Event::fake();

    postEvent(webhookFixture('sms-status-sent'), signedEventsQuery(['a' => 'b']))->assertOk();

    Event::assertDispatched(KudosityStatusReceived::class);
});

// ---------------------------------------------------------------------------
// At-least-once delivery
// ---------------------------------------------------------------------------

it('does not let a redelivered SENT regress a recorded DELIVERED', function () {
    // The observed sequence, replayed through the HTTP layer: SENT, DELIVERED,
    // then SENT again — which on the live account arrived 57 seconds after
    // DELIVERED carrying its original timestamp.
    $recorded = null;
    $writes = 0;

    Event::listen(KudosityStatusReceived::class, function (KudosityStatusReceived $e) use (&$recorded, &$writes) {
        if ($recorded === null || StatusPrecedence::supersedes($e->status->status, $recorded)) {
            $recorded = $e->status->status;
            $writes++;
        }
    });

    postEvent(webhookFixture('sms-status-sent'))->assertOk();
    postEvent(webhookFixture('sms-status-delivered'))->assertOk();
    postEvent(webhookFixture('sms-status-sent'))->assertOk();

    expect($recorded)->toBe(MessageStatus::Delivered)
        // Two writes, not three: the redelivery is absorbed.
        ->and($writes)->toBe(2);
});

it('absorbs an exact duplicate delivery without a second write', function () {
    $writes = 0;
    $recorded = null;

    Event::listen(KudosityStatusReceived::class, function (KudosityStatusReceived $e) use (&$recorded, &$writes) {
        if ($recorded === null || StatusPrecedence::supersedes($e->status->status, $recorded)) {
            $recorded = $e->status->status;
            $writes++;
        }
    });

    postEvent(webhookFixture('sms-status-delivered'))->assertOk();
    postEvent(webhookFixture('sms-status-delivered'))->assertOk();

    expect($writes)->toBe(1);
});

// ---------------------------------------------------------------------------
// The V1 routes are untouched
// ---------------------------------------------------------------------------

it('keeps the three V1 GET callback routes registered', function () {
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->methods()[0].' '.$r->uri())->all();

    expect($routes)->toContain('GET webhooks/kudosity/dlr')
        ->and($routes)->toContain('GET webhooks/kudosity/reply')
        ->and($routes)->toContain('GET webhooks/kudosity/link-hits')
        ->and($routes)->toContain('POST webhooks/kudosity/events');
});

it('registers the events route under the configured prefix and path', function () {
    expect(route('kudosity.webhooks.events', [], false))->toBe('/webhooks/kudosity/events');
});
