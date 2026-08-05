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
