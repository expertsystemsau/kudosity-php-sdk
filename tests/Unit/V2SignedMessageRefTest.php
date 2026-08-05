<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;

const SECRET = 'a-webhook-correlation-secret';

it('round-trips a simple entity', function () {
    $ref = SignedMessageRef::sign('order-9931', SECRET);

    expect($ref)->toStartWith('order-9931:')
        ->and(SignedMessageRef::verify($ref, SECRET))->toBe('order-9931');
});

it('round-trips an entity that itself contains a colon', function () {
    // THE test for this class. Real refs are composite — order-9931:cust-4471
    // is a captured example that survived a customer reply intact — so signing
    // produces two colons and parsing must split on the LAST one.
    // explode(':', $ref)[0] returns 'order-9931', which looks plausible and
    // points at the wrong entity.
    $entity = 'order-9931:cust-4471';
    $ref = SignedMessageRef::sign($entity, SECRET);

    expect(substr_count($ref, ':'))->toBe(2)
        ->and(SignedMessageRef::verify($ref, SECRET))->toBe($entity)
        ->and(SignedMessageRef::verify($ref, SECRET))->not->toBe('order-9931');
});

it('round-trips an entity with several colons', function () {
    $entity = 'tenant-7:order-9931:line-2';

    expect(SignedMessageRef::verify(SignedMessageRef::sign($entity, SECRET), SECRET))
        ->toBe($entity);
});

it('rejects a ref signed with a different secret', function () {
    $ref = SignedMessageRef::sign('order-9931', SECRET);

    expect(SignedMessageRef::verify($ref, 'not-the-secret'))->toBeNull()
        ->and(SignedMessageRef::isValid($ref, 'not-the-secret'))->toBeFalse();
});

it('rejects a ref whose entity was tampered with, signature intact', function () {
    // The forgery that matters: an attacker who has seen one of your refs edits
    // the entity and keeps the signature, hoping it is not checked.
    $ref = SignedMessageRef::sign('order-9931', SECRET);
    [$entity, $signature] = explode(':', $ref);

    expect($entity)->toBe('order-9931');

    expect(SignedMessageRef::verify('order-9932:'.$signature, SECRET))->toBeNull();
});

it('rejects an unsigned ref rather than returning it', function (string $ref) {
    // If an unsigned ref were returned as its own entity, an attacker would
    // simply omit the signature and the check would be decorative.
    expect(SignedMessageRef::verify($ref, SECRET))->toBeNull();
})->with([
    'no separator at all' => ['order-9931'],
    'composite but unsigned' => ['order-9931:cust-4471'],
    'leading separator, empty entity' => [':deadbeefdeadbeef'],
    'trailing separator, empty signature' => ['order-9931:'],
    'empty string' => [''],
]);

it('rejects a signature of the right shape but the wrong content', function () {
    // 16 hex characters, so it passes a naive format check and only the MAC
    // comparison can reject it.
    $forged = 'order-9931:0123456789abcdef';

    expect(strlen(explode(':', $forged)[1]))->toBe(SignedMessageRef::SIGNATURE_LENGTH)
        ->and(SignedMessageRef::verify($forged, SECRET))->toBeNull();
});

it('rejects a truncated signature of the correct prefix', function () {
    // hash_equals compares length as well as content, so a prefix of the real
    // signature must not pass.
    $ref = SignedMessageRef::sign('order-9931', SECRET);
    $truncated = substr($ref, 0, -4);

    expect(SignedMessageRef::verify($truncated, SECRET))->toBeNull();
});

it('rejects verification against an empty secret, even for an unsigned ref', function () {
    expect(SignedMessageRef::verify('order-9931:whatever', ''))->toBeNull();
});

it('refuses to verify with an empty secret even against a ref computed with one', function () {
    // The misconfiguration case, and the reason verify() checks the secret at all
    // rather than relying on the MAC comparison to fail. If KUDOSITY_WEBHOOK_SECRET
    // is unset the secret arrives as '', and an attacker who knows the format can
    // compute hash_hmac(..., '') themselves — so every forged ref would verify
    // against a silently misconfigured app. Signing already rejects an empty
    // secret; this closes the other side.
    $entity = 'order-9931';
    $forgedWithEmptySecret = $entity.':'.substr(hash_hmac('sha256', $entity, ''), 0, SignedMessageRef::SIGNATURE_LENGTH);

    expect(SignedMessageRef::verify($forgedWithEmptySecret, ''))->toBeNull()
        ->and(SignedMessageRef::isValid($forgedWithEmptySecret, ''))->toBeFalse();
});

it('treats a null ref as unverified rather than throwing', function () {
    // An inbound event with no last_message has no ref at all. That is a normal
    // state on a public endpoint, so the accessor result goes straight in.
    expect(SignedMessageRef::verify(null, SECRET))->toBeNull()
        ->and(SignedMessageRef::isValid(null, SECRET))->toBeFalse();
});

it('refuses to sign an empty entity or with an empty secret', function (string $entity, string $secret, string $fragment) {
    // Each has its own single-violation input, and each asserts on the fragment
    // only its own rule produces.
    expect(fn () => SignedMessageRef::sign($entity, $secret))
        ->toThrow(ValidationException::class, $fragment);
})->with([
    'empty entity' => ['', SECRET, 'empty entity reference'],
    'empty secret' => ['order-9931', '', 'empty secret'],
]);

it('produces the same signature for the same input, and different for different', function () {
    // Deterministic, because the ref has to survive a round trip through
    // Kudosity and come back matching. Nothing random may enter it.
    expect(SignedMessageRef::sign('order-9931', SECRET))
        ->toBe(SignedMessageRef::sign('order-9931', SECRET))
        ->and(SignedMessageRef::sign('order-9931', SECRET))
        ->not->toBe(SignedMessageRef::sign('order-9932', SECRET));
});

// ---------------------------------------------------------------------------
// The 500-character budget
// ---------------------------------------------------------------------------

it('keeps a realistic signed ref inside the documented message_ref limit', function () {
    $ref = SignedMessageRef::sign('order-9931:cust-4471', SECRET);

    expect(mb_strlen($ref))->toBeLessThanOrEqual(SendSmsV2Request::MAX_MESSAGE_REF_LENGTH);
});

it('signs an entity of exactly the maximum length, and the result fits exactly', function () {
    // The boundary in both directions: the longest allowed entity must sign, and
    // its signed form must land exactly on the limit rather than one over.
    $entity = str_repeat('a', SignedMessageRef::maxEntityLength());
    $ref = SignedMessageRef::sign($entity, SECRET);

    expect(mb_strlen($ref))->toBe(SendSmsV2Request::MAX_MESSAGE_REF_LENGTH)
        ->and(SignedMessageRef::verify($ref, SECRET))->toBe($entity);
});

it('refuses an entity one character too long instead of producing a ref the API rejects', function () {
    // A helper that can only produce a rejected value is worse than no helper:
    // the failure would surface at send time, far from the cause.
    $entity = str_repeat('a', SignedMessageRef::maxEntityLength() + 1);

    expect(fn () => SignedMessageRef::sign($entity, SECRET))
        ->toThrow(ValidationException::class, 'leaves no room for a signature');
});

it('agrees with the send guard about the limit, rather than keeping its own copy', function () {
    // The whole reason MAX_MESSAGE_REF_LENGTH moved into a concern. If these two
    // ever disagree, one of them is producing refs the other rejects.
    $entity = str_repeat('b', SignedMessageRef::maxEntityLength());
    $ref = SignedMessageRef::sign($entity, SECRET);

    // Constructing the request is the assertion: it throws if the ref is too long.
    $request = new SendSmsV2Request(
        message: 'Hi',
        recipient: '61400000000',
        sender: '61481074185',
        messageRef: $ref,
    );

    expect($request)->toBeInstanceOf(SendSmsV2Request::class)
        ->and(SignedMessageRef::maxEntityLength())
        ->toBe(SendSmsV2Request::MAX_MESSAGE_REF_LENGTH - SignedMessageRef::SIGNATURE_LENGTH - 1);
});

// ---------------------------------------------------------------------------
// End to end, against a real captured delivery
// ---------------------------------------------------------------------------

it('verifies a ref carried on a real webhook payload', function () {
    // The point of the whole class: a delivery arrives, the ref comes off it
    // through the uniform accessor, and it resolves to our entity — or it does
    // not, and the delivery is untrusted.
    $entity = 'order-9931:cust-4471';
    $signed = SignedMessageRef::sign($entity, SECRET);

    $payload = webhookFixture('sms-status-delivered');
    $payload['status']['message_ref'] = $signed;

    $event = WebhookEvent::fromArray($payload);

    expect(SignedMessageRef::verify($event->messageRef(), SECRET))->toBe($entity);
});

it('does not verify the unsigned refs the real fixtures actually carry', function () {
    // The captured refs predate signing, so they must NOT verify. This is the
    // assertion that would fail if verify() ever fell back to returning the ref
    // when no signature is present.
    foreach (['sms-status-delivered', 'sms-inbound-with-last-message', 'link-hit-sms'] as $fixture) {
        $ref = WebhookEvent::fromArray(webhookFixture($fixture))->messageRef();

        expect($ref)->not->toBeNull()
            ->and(SignedMessageRef::verify($ref, SECRET))->toBeNull();
    }
});

it('survives a forged delivery aimed at a real entity', function () {
    // An attacker who knows the entity id but not the secret cannot make a
    // payload that correlates — which is exactly the protection claimed, and no
    // more than that: the rest of the forged payload is still whatever they sent.
    $payload = webhookFixture('sms-status-delivered');
    $payload['status']['message_ref'] = 'order-9931:cust-4471:0000000000000000';

    $event = WebhookEvent::fromArray($payload);

    expect($event->messageRef())->toBe('order-9931:cust-4471:0000000000000000')
        ->and(SignedMessageRef::verify($event->messageRef(), SECRET))->toBeNull();
});
