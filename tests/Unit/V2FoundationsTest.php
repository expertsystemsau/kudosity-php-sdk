<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\ValidationException;

it('maps every documented status across all three V2 vocabularies', function (string $api, MessageStatus $expected) {
    expect(MessageStatus::fromApi($api))->toBe($expected);
})->with([
    // GET /v2/sms filter vocabulary (13)
    ['PENDING', MessageStatus::Pending],
    ['SENT', MessageStatus::Sent],
    ['FAILED', MessageStatus::Failed],
    ['DELIVERED', MessageStatus::Delivered],
    ['ACCEPTED', MessageStatus::Accepted],
    ['SOFT_BOUNCE', MessageStatus::SoftBounce],
    ['HARD_BOUNCE', MessageStatus::HardBounce],
    ['OTHER', MessageStatus::Other],
    ['REJECTED', MessageStatus::Rejected],
    ['PENDING_APPROVAL', MessageStatus::PendingApproval],
    ['SUBMITTED', MessageStatus::Submitted],
    ['UNDELIVERABLE', MessageStatus::Undeliverable],
    ['READ', MessageStatus::Read],
    // WhatsApp/RCS list vocabulary contributes one more
    ['QUEUED', MessageStatus::Queued],
]);

it('resolves an unrecognised or absent status to Unknown rather than throwing', function () {
    // The docs state these lists will grow, so a new upstream value must not
    // break a client that is only reading messages.
    expect(MessageStatus::fromApi('TELEPORTED'))->toBe(MessageStatus::Unknown)
        ->and(MessageStatus::fromApi(null))->toBe(MessageStatus::Unknown)
        ->and(MessageStatus::fromApi(''))->toBe(MessageStatus::Unknown);
});

it('accepts a lowercase status, since the send response returns lowercase', function () {
    // POST /v2/sms answers "status": "delivered"; the list filter uses
    // "DELIVERED". Same value, two casings, one enum.
    expect(MessageStatus::fromApi('delivered'))->toBe(MessageStatus::Delivered)
        ->and(MessageStatus::fromApi('pending'))->toBe(MessageStatus::Pending);
});

it('treats only Delivered and Read as isDelivered, deliberately excluding Accepted', function () {
    // ACCEPTED means the carrier took the message, not that it arrived.
    // Treating it as delivered is the documented cause of over-reported
    // success rates — do not "fix" this to include it.
    expect(MessageStatus::Delivered->isDelivered())->toBeTrue()
        ->and(MessageStatus::Read->isDelivered())->toBeTrue()
        ->and(MessageStatus::Accepted->isDelivered())->toBeFalse();
});

it('is isTerminal for exactly the documented final statuses, and no others', function () {
    // An allow-list of the full membership, not spot checks either way — this
    // project has a documented history of tests that only denied a couple of
    // hardcoded values.
    $terminal = [
        MessageStatus::Delivered,
        MessageStatus::Read,
        MessageStatus::Failed,
        MessageStatus::Rejected,
        MessageStatus::Undeliverable,
        MessageStatus::HardBounce,
    ];

    foreach (MessageStatus::cases() as $status) {
        expect($status->isTerminal())->toBe(in_array($status, $terminal, true));
    }
});

it('builds an sms_fallback body with the sender omitted when absent', function () {
    expect((new SmsFallback('Shorter plain text'))->toArray())
        ->toBe(['message' => 'Shorter plain text']);
});

it('includes the sender when given', function () {
    expect((new SmsFallback('Body', '61481074185'))->toArray())
        ->toBe(['message' => 'Body', 'sender' => '61481074185']);
});

it('rejects an empty fallback message, which the API requires', function () {
    new SmsFallback('');
})->throws(ValidationException::class, 'message');

it('round-trips from an API payload', function () {
    $f = SmsFallback::fromArray(['message' => 'Body', 'sender' => '614810']);

    expect($f->message)->toBe('Body')->and($f->sender)->toBe('614810');
});

it('builds from a response payload that carries a message', function () {
    // fromResponse() is the read path shared by WhatsApp and RCS, both of which
    // echo sms_fallback back. It exists so that reading a message back never
    // throws on this field — see the null cases below.
    $f = SmsFallback::fromResponse(['message' => 'Body', 'sender' => '614810']);

    expect($f)->toBeInstanceOf(SmsFallback::class)
        ->and($f?->message)->toBe('Body')
        ->and($f?->sender)->toBe('614810');
});

it('builds from a response payload with no sender', function () {
    expect(SmsFallback::fromResponse(['message' => 'Body'])?->sender)->toBeNull();
});

it('returns null rather than throwing when a response fallback has an empty message', function () {
    // The constructor rejects this, correctly, for a request-shaped object. The
    // read path returns null instead: a null is inspectable, an exception thrown
    // part-way through hydrating a message is not.
    expect(SmsFallback::fromResponse(['message' => '', 'sender' => '614810']))->toBeNull();
});

it('returns null rather than throwing when a response fallback has no message key', function () {
    expect(SmsFallback::fromResponse(['sender' => '614810']))->toBeNull();
});

it('returns null rather than throwing on an empty response fallback', function () {
    expect(SmsFallback::fromResponse([]))->toBeNull();
});

it('returns null rather than throwing when a response fallback message is not a string', function () {
    expect(SmsFallback::fromResponse(['message' => 12345]))->toBeNull();
});

it('keeps the constructor invariant intact — fromResponse is a separate path, not a weakened rule', function () {
    // The point of the split: adding a permissive read path must not make the
    // request-shaped object constructible without a message.
    expect(fn () => new SmsFallback(''))->toThrow(ValidationException::class)
        ->and(fn () => SmsFallback::fromArray(['sender' => '614810']))->toThrow(ValidationException::class);
});
