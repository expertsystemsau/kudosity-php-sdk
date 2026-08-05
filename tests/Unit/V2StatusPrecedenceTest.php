<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;

// ---------------------------------------------------------------------------
// The pairs, both directions each
// ---------------------------------------------------------------------------

it('decides precedence between two statuses, in both directions', function (
    MessageStatus $incoming,
    MessageStatus $recorded,
    bool $expected,
) {
    // Both directions of every pair, because a one-directional assertion cannot
    // tell a working comparison from a constant.
    expect(StatusPrecedence::supersedes($incoming, $recorded))->toBe($expected);
})->with([
    'DELIVERED over SENT — forward progress' => [MessageStatus::Delivered, MessageStatus::Sent, true],
    'SENT over DELIVERED — the late-SENT bug this exists to stop' => [MessageStatus::Sent, MessageStatus::Delivered, false],

    'READ over DELIVERED — the RCS read receipt follows delivery' => [MessageStatus::Read, MessageStatus::Delivered, true],
    'DELIVERED over READ — already past it' => [MessageStatus::Delivered, MessageStatus::Read, false],

    'DELIVERED over ACCEPTED — ACCEPTED is not DELIVERED' => [MessageStatus::Delivered, MessageStatus::Accepted, true],
    'ACCEPTED over DELIVERED — the over-reported-success trap, inverted' => [MessageStatus::Accepted, MessageStatus::Delivered, false],

    'FAILED over SENT — a failure after submission is news' => [MessageStatus::Failed, MessageStatus::Sent, true],
    'SENT over FAILED — terminal failure stands' => [MessageStatus::Sent, MessageStatus::Failed, false],

    'DELIVERED over SOFT_BOUNCE — a retry got through' => [MessageStatus::Delivered, MessageStatus::SoftBounce, true],
    'SOFT_BOUNCE over DELIVERED — cannot un-deliver' => [MessageStatus::SoftBounce, MessageStatus::Delivered, false],

    'SOFT_BOUNCE over SENT — worth recording' => [MessageStatus::SoftBounce, MessageStatus::Sent, true],
    'SENT over SOFT_BOUNCE' => [MessageStatus::Sent, MessageStatus::SoftBounce, false],

    'HARD_BOUNCE over ACCEPTED' => [MessageStatus::HardBounce, MessageStatus::Accepted, true],
    'ACCEPTED over HARD_BOUNCE' => [MessageStatus::Accepted, MessageStatus::HardBounce, false],

    'SENT over QUEUED' => [MessageStatus::Sent, MessageStatus::Queued, true],
    'QUEUED over SENT' => [MessageStatus::Queued, MessageStatus::Sent, false],
]);

it('treats a redelivery of the same status as no update at all', function (MessageStatus $status) {
    // Equal rank must be false, not true: deliveries are at-least-once, and a
    // consumer counting state changes would otherwise double-count one event.
    expect(StatusPrecedence::supersedes($status, $status))->toBeFalse();
})->with([
    [MessageStatus::Sent],
    [MessageStatus::Delivered],
    [MessageStatus::Failed],
    [MessageStatus::Read],
    [MessageStatus::Unknown],
]);

it('never lets an unresolved status overwrite a known one', function (MessageStatus $recorded) {
    // A status Kudosity ships next quarter resolves to Unknown. Losing it costs
    // a reporting nuance; letting it overwrite DELIVERED costs the record.
    expect(StatusPrecedence::supersedes(MessageStatus::Unknown, $recorded))->toBeFalse();
})->with([
    [MessageStatus::Pending],
    [MessageStatus::Sent],
    [MessageStatus::Accepted],
    [MessageStatus::Delivered],
    [MessageStatus::Failed],
    [MessageStatus::Read],
]);

it('always lets a known status overwrite an unresolved one', function (MessageStatus $incoming) {
    expect(StatusPrecedence::supersedes($incoming, MessageStatus::Unknown))->toBeTrue();
})->with([
    [MessageStatus::Pending],
    [MessageStatus::Sent],
    [MessageStatus::Delivered],
    [MessageStatus::Failed],
    [MessageStatus::Read],
]);

// ---------------------------------------------------------------------------
// Why isTerminal() could not have been used
// ---------------------------------------------------------------------------

it('ranks READ above DELIVERED even though both are terminal', function () {
    // The reason this class is a rank and not a boolean. MessageStatus::isTerminal()
    // is true for both, so "never overwrite a terminal status" would drop the RCS
    // read receipt. Asserted against isTerminal() directly so the trap is visible
    // to whoever reads this next.
    expect(MessageStatus::Delivered->isTerminal())->toBeTrue()
        ->and(MessageStatus::Read->isTerminal())->toBeTrue()
        ->and(StatusPrecedence::rank(MessageStatus::Read))
        ->toBeGreaterThan(StatusPrecedence::rank(MessageStatus::Delivered));
});

it('gives every status a rank, so a new enum case cannot be silently unranked', function () {
    // A full-membership check rather than spot-checks: match() without a default
    // means an unranked new case is a fatal, and this asserts that stays true.
    foreach (MessageStatus::cases() as $case) {
        expect(StatusPrecedence::rank($case))->toBeGreaterThanOrEqual(0);
    }

    expect(StatusPrecedence::rank(MessageStatus::Unknown))->toBe(0);
});

// ---------------------------------------------------------------------------
// The observed sequence, replayed
// ---------------------------------------------------------------------------

/** @param array<int, string> $fixtures */
function replay(array $fixtures): ?StatusEvent
{
    $events = array_map(
        static fn (string $name): StatusEvent => WebhookEvent::fromArray(webhookFixture($name)),
        $fixtures,
    );

    return StatusPrecedence::reduce($events);
}

it('ends at DELIVERED for the exact sequence the live API delivered', function () {
    // SENT, then DELIVERED, then SENT AGAIN — the real redelivery, 60s later,
    // carrying its original timestamp. Not an invented case: see the timeline in
    // tests/Fixtures/V2Webhooks/README.md.
    $winner = replay(['sms-status-sent', 'sms-status-delivered', 'sms-status-sent']);

    expect($winner?->status)->toBe(MessageStatus::Delivered);
});

it('ends at DELIVERED whatever order those three arrive in', function () {
    foreach ([
        ['sms-status-sent', 'sms-status-delivered', 'sms-status-sent'],
        ['sms-status-sent', 'sms-status-sent', 'sms-status-delivered'],
        ['sms-status-delivered', 'sms-status-sent', 'sms-status-sent'],
    ] as $order) {
        expect(replay($order)?->status)->toBe(MessageStatus::Delivered);
    }
});

it('reports no update when DELIVERED is redelivered on top of itself', function () {
    // The at-least-once case in its purest form.
    $delivered = WebhookEvent::fromArray(webhookFixture('sms-status-delivered'));

    expect(StatusPrecedence::supersedes($delivered->status, $delivered->status))->toBeFalse()
        ->and(replay(['sms-status-delivered', 'sms-status-delivered'])?->status)
        ->toBe(MessageStatus::Delivered);
});

it('returns null for an empty stream rather than inventing a status', function () {
    expect(StatusPrecedence::reduce([]))->toBeNull();
});

it('ignores events for a different message when a status id is given', function () {
    // status.id is the identity. Folding two messages together would corrupt
    // both records, so a non-matching event is skipped rather than merged.
    $mine = WebhookEvent::fromArray(webhookFixture('sms-status-sent'));
    $other = WebhookEvent::fromArray(webhookFixture('mms-status-delivered'));

    expect($mine->id)->not->toBe($other->id);

    $winner = StatusPrecedence::reduce([$mine, $other], $mine->id);

    // DELIVERED outranks SENT, so an unfiltered reduce would have returned the
    // other message's event — that is what makes this assertion meaningful.
    expect($winner?->status)->toBe(MessageStatus::Sent)
        ->and($winner?->id)->toBe($mine->id)
        ->and(StatusPrecedence::reduce([$mine, $other])?->id)->toBe($other->id);
});

it('returns null when no event matches the requested status id', function () {
    $mine = WebhookEvent::fromArray(webhookFixture('sms-status-sent'));

    expect(StatusPrecedence::reduce([$mine], 'a-different-id'))->toBeNull();
});
