<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Contracts\SentMessage;
use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Laravel\Notifications\ApiVersion;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;

// ---------------------------------------------------------------------------
// The default, and the six V1-only triggers
// ---------------------------------------------------------------------------

it('routes a plain message to V2', function () {
    // V2 is the default surface. Nothing about a simple send needs V1.
    expect((new KudosityMessage('Hi'))->to('61491570006')->apiVersion())
        ->toBe(ApiVersion::V2);
});

it('routes to V1 for each option V2 cannot express', function (callable $configure, string $because) {
    // One trigger per case, set in isolation, so a passing test names WHICH rule
    // fired. A message with two triggers cannot tell you that.
    $message = $configure(new KudosityMessage('Hi'));

    expect($message->apiVersion())->toBe(ApiVersion::V1, $because)
        ->and($message->v1Reasons())->toHaveCount(1);
})->with([
    'a list send' => [
        fn (KudosityMessage $m) => $m->toList(4213644),
        'V2 has no list send endpoint',
    ],
    'scheduling' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->sendAt('2026-09-01 09:00:00'),
        'POST /v2/sms cannot schedule',
    ],
    'a validity window' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->validity(60),
        'V1-only option',
    ],
    'replies to email' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->repliesToEmail('inbox@example.com'),
        'V1-only option',
    ],
    'a dlr callback' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->dlrCallback('https://e.com/dlr'),
        'V2 has no per-send callback URL at all',
    ],
    'a reply callback' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->replyCallback('https://e.com/reply'),
        'V2 has no per-send callback URL at all',
    ],
    'a link-hits callback' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->linkHitsCallback('https://e.com/hits'),
        'V2 has no per-send callback URL at all',
    ],
    'a dlr handler' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->onDlr('App\\Handlers\\Dlr'),
        'onDlr() becomes a per-send dlr_callback, which V2 has no room for',
    ],
    'a reply handler' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->onReply('App\\Handlers\\Reply'),
        'onReply() becomes a per-send reply_callback',
    ],
    'a link-hit handler' => [
        fn (KudosityMessage $m) => $m->to('61491570006')->onLinkHit('App\\Handlers\\LinkHit'),
        'onLinkHit() becomes a per-send link_hits_callback',
    ],
    'multiple recipients' => [
        fn (KudosityMessage $m) => $m->to('61491570006,61491570007'),
        'POST /v2/sms takes exactly one recipient',
    ],
]);

it('routes the handler form to V1 as surely as the raw callback URL', function () {
    // The trap worth its own test. onDlr() is the idiomatic way to use this
    // package — the whole signed-URL mechanism exists for it — and it ends up as
    // a dlr_callback on the request. A message using it that routed to V2 would
    // send perfectly and never call the handler, which is a silence, not an
    // error. Asserted against the raw form so the two cannot diverge.
    $viaHandler = (new KudosityMessage('Hi'))->to('61491570006')->onDlr('App\\Handlers\\Dlr');
    $viaUrl = (new KudosityMessage('Hi'))->to('61491570006')->dlrCallback('https://e.com/dlr');

    expect($viaHandler->apiVersion())->toBe(ApiVersion::V1)
        ->and($viaUrl->apiVersion())->toBe($viaHandler->apiVersion())
        ->and($viaHandler->hasCallbackHandlers())->toBeTrue();
});

it('throws on forceV2() with a callback handler, not just a callback URL', function () {
    (new KudosityMessage('Hi'))
        ->to('61491570006')
        ->onReply('App\\Handlers\\Reply')
        ->forceV2()
        ->apiVersion();
})->throws(ValidationException::class, 'onReply');

it('treats a single recipient with surrounding whitespace as one recipient', function () {
    // A trailing comma or a space must not be mistaken for a second recipient and
    // silently downgrade an otherwise-V2 send.
    expect((new KudosityMessage('Hi'))->to(' 61491570006 ')->apiVersion())->toBe(ApiVersion::V2)
        ->and((new KudosityMessage('Hi'))->to('61491570006,')->apiVersion())->toBe(ApiVersion::V2);
});

it('names every reason when several V1-only options are set', function () {
    // For diagnostics: a developer wondering why their send went to V1 wants the
    // whole list, not the first one found.
    $message = (new KudosityMessage('Hi'))
        ->to('61491570006')
        ->sendAt('2026-09-01 09:00:00')
        ->validity(60)
        ->dlrCallback('https://e.com/dlr');

    expect($message->apiVersion())->toBe(ApiVersion::V1)
        ->and($message->v1Reasons())->toHaveCount(3)
        ->and(implode(' ', $message->v1Reasons()))
        ->toContain('sendAt')
        ->toContain('validity')
        ->toContain('dlrCallback');
});

it('reports no reasons for a message that routes to V2', function () {
    expect((new KudosityMessage('Hi'))->to('61491570006')->v1Reasons())->toBe([]);
});

// ---------------------------------------------------------------------------
// Overrides
// ---------------------------------------------------------------------------

it('lets forceV1() send an otherwise-V2 message over V1', function () {
    // A legitimate escape hatch: an account might have V1-side reporting a team
    // depends on, and nothing about a plain send is V2-only.
    expect((new KudosityMessage('Hi'))->to('61491570006')->forceV1()->apiVersion())
        ->toBe(ApiVersion::V1);
});

it('lets forceV2() send a plain message over V2 explicitly', function () {
    expect((new KudosityMessage('Hi'))->to('61491570006')->forceV2()->apiVersion())
        ->toBe(ApiVersion::V2);
});

it('throws when forceV2() is combined with an option V2 cannot express', function () {
    // THE test in this file. Silently dropping sendAt() turns a scheduled send
    // into an immediate one — a wrong send, not a failed one, and the kind of
    // failure nobody notices until a customer gets a 3am message.
    (new KudosityMessage('Hi'))
        ->to('61491570006')
        ->sendAt('2026-09-01 09:00:00')
        ->forceV2()
        ->apiVersion();
})->throws(ValidationException::class, 'sendAt');

it('names every offending option when forceV2() cannot be honoured', function () {
    try {
        (new KudosityMessage('Hi'))
            ->to('61491570006')
            ->validity(60)
            ->replyCallback('https://e.com/reply')
            ->forceV2()
            ->apiVersion();
    } catch (ValidationException $e) {
        expect($e->getMessage())->toContain('validity')
            ->and($e->getMessage())->toContain('replyCallback')
            ->and($e->getErrorCode())->toBe('FIELD_INVALID');

        return;
    }

    throw new RuntimeException('forceV2() silently dropped a V1-only option');
});

it('throws on forceV2() with a list send, which has no V2 equivalent at all', function () {
    (new KudosityMessage('Hi'))->toList(4213644)->forceV2()->apiVersion();
})->throws(ValidationException::class, 'toList');

it('lets the last override win, so a builder can be reconfigured', function () {
    $message = (new KudosityMessage('Hi'))->to('61491570006')->forceV1()->forceV2();

    expect($message->apiVersion())->toBe(ApiVersion::V2);

    expect((new KudosityMessage('Hi'))->to('61491570006')->forceV2()->forceV1()->apiVersion())
        ->toBe(ApiVersion::V1);
});

it('makes the routing decision inspectable before anything is sent', function () {
    // apiVersion() is a query, not a side effect: it must be safe to call in a
    // test or a log line without sending anything.
    $message = (new KudosityMessage('Hi'))->to('61491570006');

    expect($message->apiVersion())->toBe($message->apiVersion());
});

// ---------------------------------------------------------------------------
// SentMessage — one return type across the routing decision
// ---------------------------------------------------------------------------

it('has both send responses satisfy one contract', function () {
    expect(new ReflectionClass(SmsData::class))->toBeInstanceOf(ReflectionClass::class);

    expect((new ReflectionClass(SmsData::class))->implementsInterface(SentMessage::class))->toBeTrue()
        ->and((new ReflectionClass(SmsMessageData::class))->implementsInterface(SentMessage::class))->toBeTrue();
});

it('reads a V1 send response through the contract', function () {
    $sent = SmsData::fromResponse([
        'message_id' => 123456,
        'send_at' => '2026-08-06 09:00:00',
        'recipients' => 3,
        'cost' => 0.15,
        'sms' => 1,
    ]);

    expect($sent->id())->toBe('123456')          // string, not int
        ->and($sent->recipientCount())->toBe(3)
        ->and($sent->status())->toBeNull();      // V1 sends report no status
});

it('reads a V2 send response through the same contract', function () {
    $sent = SmsMessageData::fromArray([
        'id' => '953b88be-5b6f-4b6d-8fcb-3436ec21c0be',
        'recipient' => '61491570006',
        'sender' => '61491570017',
        'message' => 'Hi',
        'status' => 'delivered',
        'sms_count' => '2',
    ]);

    expect($sent->id())->toBe('953b88be-5b6f-4b6d-8fcb-3436ec21c0be')
        // 1, not sms_count: two SEGMENTS to one person is one recipient, and
        // conflating them over-reports reach.
        ->and($sent->recipientCount())->toBe(1)
        ->and($sent->smsCount)->toBe(2)
        ->and($sent->status())->toBe(MessageStatus::Delivered);
});

it('never invents a status for a V1 send', function () {
    // Returning Pending here would be indistinguishable from a status the API
    // actually sent — the failure mode the tolerant-enum work exists to prevent.
    $sent = SmsData::fromResponse([
        'message_id' => 1, 'send_at' => '2026-08-06 09:00:00',
        'recipients' => 1, 'cost' => 0.05, 'sms' => 1,
    ]);

    expect($sent->status())->toBeNull()
        ->and($sent->status())->not->toBe(MessageStatus::Pending);
});
