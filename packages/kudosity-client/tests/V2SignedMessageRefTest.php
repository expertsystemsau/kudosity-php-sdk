<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;
use ExpertSystems\Kudosity\Tests\Fixtures\Fixtures;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/V2SignedMessageRefTest.php.
 *
 * The security-relevant part of this batch: SignedMessageRef protects
 * correlation, not the payload, because V2 deliveries are unsigned — see
 * SignedMessageRef's own class docblock and Fixtures/V2Webhooks/README.md.
 * Every forgery case below stays its own assertion.
 *
 * One root test was not re-ported: "rejects a ref signed with a different
 * secret" is an exact behavioural duplicate of WebhookGuardsTest's
 * `test_a_ref_signed_with_another_secret_does_not_verify` (same two
 * assertions, only the wrong-secret literal differs) — left in place there,
 * not ported here.
 *
 * Two WebhookGuardsTest tests were folded into this file, both removed from
 * there in this same commit:
 *
 * - `test_a_signed_ref_round_trips`'s isValid() assertion is now the third
 *   assertion of `test_round_trips_a_simple_entity` below (which already had
 *   the startsWith()/verify() checks that the old test lacked) — the union
 *   of both, not a replacement.
 * - `test_a_composite_entity_is_parsed_from_the_last_colon` is a strict
 *   subset of `test_round_trips_an_entity_that_itself_contains_a_colon`
 *   below (same verify() check, which also adds the colon-count and the
 *   negative "not the truncated entity" checks).
 * - `test_an_unsigned_ref_does_not_verify`'s two assertions are covered by
 *   the combination of `test_rejects_an_unsigned_ref_rather_than_returning_it`
 *   below (its 'no separator at all' row covers the same case) and
 *   `test_treats_a_null_ref_as_unverified_rather_than_throwing` below (which
 *   also adds an isValid() check the old test never made).
 *
 * `test_the_signature_is_the_documented_length` stayed in WebhookGuardsTest:
 * it derives SIGNATURE_LENGTH from an actual sign() output via strrpos()
 * rather than a hardcoded literal, which nothing ported here does. See the
 * task report for the full reconciliation.
 */
#[CoversClass(SignedMessageRef::class)]
final class V2SignedMessageRefTest extends TestCase
{
    private const SECRET = 'a-webhook-correlation-secret';

    public function test_round_trips_a_simple_entity(): void
    {
        $ref = SignedMessageRef::sign('order-9931', self::SECRET);

        $this->assertStringStartsWith('order-9931:', $ref);
        $this->assertSame('order-9931', SignedMessageRef::verify($ref, self::SECRET));
        $this->assertTrue(SignedMessageRef::isValid($ref, self::SECRET));
    }

    public function test_round_trips_an_entity_that_itself_contains_a_colon(): void
    {
        // THE test for this class. Real refs are composite —
        // order-9931:cust-4471 is a captured example that survived a
        // customer reply intact — so signing produces two colons and
        // parsing must split on the LAST one. explode(':', $ref)[0] returns
        // 'order-9931', which looks plausible and points at the wrong
        // entity.
        $entity = 'order-9931:cust-4471';
        $ref = SignedMessageRef::sign($entity, self::SECRET);

        $this->assertSame(2, substr_count($ref, ':'));
        $this->assertSame($entity, SignedMessageRef::verify($ref, self::SECRET));
        $this->assertNotSame('order-9931', SignedMessageRef::verify($ref, self::SECRET));
    }

    public function test_round_trips_an_entity_with_several_colons(): void
    {
        $entity = 'tenant-7:order-9931:line-2';

        $this->assertSame(
            $entity,
            SignedMessageRef::verify(SignedMessageRef::sign($entity, self::SECRET), self::SECRET),
        );
    }

    public function test_rejects_a_ref_whose_entity_was_tampered_with_signature_intact(): void
    {
        // The forgery that matters: an attacker who has seen one of your
        // refs edits the entity and keeps the signature, hoping it is not
        // checked.
        $ref = SignedMessageRef::sign('order-9931', self::SECRET);
        [$entity, $signature] = explode(':', $ref);

        $this->assertSame('order-9931', $entity);
        $this->assertNull(SignedMessageRef::verify('order-9932:'.$signature, self::SECRET));
    }

    /** @return array<string, array{0: string}> */
    public static function unsignedRefs(): array
    {
        return [
            'no separator at all' => ['order-9931'],
            'composite but unsigned' => ['order-9931:cust-4471'],
            'leading separator, empty entity' => [':deadbeefdeadbeef'],
            'trailing separator, empty signature' => ['order-9931:'],
            'empty string' => [''],
        ];
    }

    /**
     * If an unsigned ref were returned as its own entity, an attacker would
     * simply omit the signature and the check would be decorative.
     */
    #[DataProvider('unsignedRefs')]
    public function test_rejects_an_unsigned_ref_rather_than_returning_it(string $ref): void
    {
        $this->assertNull(SignedMessageRef::verify($ref, self::SECRET));
    }

    public function test_rejects_a_signature_of_the_right_shape_but_the_wrong_content(): void
    {
        // 16 hex characters, so it passes a naive format check and only the
        // MAC comparison can reject it.
        $forged = 'order-9931:0123456789abcdef';

        $this->assertSame(SignedMessageRef::SIGNATURE_LENGTH, strlen(explode(':', $forged)[1]));
        $this->assertNull(SignedMessageRef::verify($forged, self::SECRET));
    }

    public function test_rejects_a_truncated_signature_of_the_correct_prefix(): void
    {
        // hash_equals compares length as well as content, so a prefix of the
        // real signature must not pass.
        $ref = SignedMessageRef::sign('order-9931', self::SECRET);
        $truncated = substr($ref, 0, -4);

        $this->assertNull(SignedMessageRef::verify($truncated, self::SECRET));
    }

    public function test_rejects_verification_against_an_empty_secret_even_for_an_unsigned_ref(): void
    {
        $this->assertNull(SignedMessageRef::verify('order-9931:whatever', ''));
    }

    public function test_refuses_to_verify_with_an_empty_secret_even_against_a_ref_computed_with_one(): void
    {
        // The misconfiguration case, and the reason verify() checks the
        // secret at all rather than relying on the MAC comparison to fail.
        // If KUDOSITY_WEBHOOK_SECRET is unset the secret arrives as '', and
        // an attacker who knows the format can compute
        // hash_hmac(..., '') themselves — so every forged ref would verify
        // against a silently misconfigured app. Signing already rejects an
        // empty secret; this closes the other side.
        $entity = 'order-9931';
        $forgedWithEmptySecret = $entity.':'.substr(hash_hmac('sha256', $entity, ''), 0, SignedMessageRef::SIGNATURE_LENGTH);

        $this->assertNull(SignedMessageRef::verify($forgedWithEmptySecret, ''));
        $this->assertFalse(SignedMessageRef::isValid($forgedWithEmptySecret, ''));
    }

    public function test_treats_a_null_ref_as_unverified_rather_than_throwing(): void
    {
        // An inbound event with no last_message has no ref at all. That is a
        // normal state on a public endpoint, so the accessor result goes
        // straight in.
        $this->assertNull(SignedMessageRef::verify(null, self::SECRET));
        $this->assertFalse(SignedMessageRef::isValid(null, self::SECRET));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function emptyInputs(): array
    {
        return [
            'empty entity' => ['', self::SECRET, 'empty entity reference'],
            'empty secret' => ['order-9931', '', 'empty secret'],
        ];
    }

    /**
     * Each has its own single-violation input, and each asserts on the
     * fragment only its own rule produces.
     */
    #[DataProvider('emptyInputs')]
    public function test_refuses_to_sign_an_empty_entity_or_with_an_empty_secret(
        string $entity,
        string $secret,
        string $fragment,
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($fragment);

        SignedMessageRef::sign($entity, $secret);
    }

    public function test_produces_the_same_signature_for_the_same_input_and_different_for_different(): void
    {
        // Deterministic, because the ref has to survive a round trip
        // through Kudosity and come back matching. Nothing random may enter
        // it.
        $this->assertSame(
            SignedMessageRef::sign('order-9931', self::SECRET),
            SignedMessageRef::sign('order-9931', self::SECRET),
        );
        $this->assertNotSame(
            SignedMessageRef::sign('order-9932', self::SECRET),
            SignedMessageRef::sign('order-9931', self::SECRET),
        );
    }

    // -------------------------------------------------------------------
    // The 500-character budget
    // -------------------------------------------------------------------

    public function test_keeps_a_realistic_signed_ref_inside_the_documented_message_ref_limit(): void
    {
        $ref = SignedMessageRef::sign('order-9931:cust-4471', self::SECRET);

        $this->assertLessThanOrEqual(SendSmsV2Request::MAX_MESSAGE_REF_LENGTH, mb_strlen($ref));
    }

    public function test_signs_an_entity_of_exactly_the_maximum_length_and_the_result_fits_exactly(): void
    {
        // The boundary in both directions: the longest allowed entity must
        // sign, and its signed form must land exactly on the limit rather
        // than one over.
        $entity = str_repeat('a', SignedMessageRef::maxEntityLength());
        $ref = SignedMessageRef::sign($entity, self::SECRET);

        $this->assertSame(SendSmsV2Request::MAX_MESSAGE_REF_LENGTH, mb_strlen($ref));
        $this->assertSame($entity, SignedMessageRef::verify($ref, self::SECRET));
    }

    public function test_refuses_an_entity_one_character_too_long_instead_of_producing_a_ref_the_api_rejects(): void
    {
        // A helper that can only produce a rejected value is worse than no
        // helper: the failure would surface at send time, far from the
        // cause.
        $entity = str_repeat('a', SignedMessageRef::maxEntityLength() + 1);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('leaves no room for a signature');

        SignedMessageRef::sign($entity, self::SECRET);
    }

    public function test_agrees_with_the_send_guard_about_the_limit_rather_than_keeping_its_own_copy(): void
    {
        // The whole reason MAX_MESSAGE_REF_LENGTH moved into a concern. If
        // these two ever disagree, one of them is producing refs the other
        // rejects.
        $entity = str_repeat('b', SignedMessageRef::maxEntityLength());
        $ref = SignedMessageRef::sign($entity, self::SECRET);

        // Constructing the request is the assertion: it throws if the ref is
        // too long.
        $request = new SendSmsV2Request(
            message: 'Hi',
            recipient: '61491570006',
            sender: '61491570017',
            messageRef: $ref,
        );

        $this->assertInstanceOf(SendSmsV2Request::class, $request);
        $this->assertSame(
            SendSmsV2Request::MAX_MESSAGE_REF_LENGTH - SignedMessageRef::SIGNATURE_LENGTH - 1,
            SignedMessageRef::maxEntityLength(),
        );
    }

    // -------------------------------------------------------------------
    // End to end, against a real captured delivery
    // -------------------------------------------------------------------

    public function test_verifies_a_ref_carried_on_a_real_webhook_payload(): void
    {
        // The point of the whole class: a delivery arrives, the ref comes
        // off it through the uniform accessor, and it resolves to our
        // entity — or it does not, and the delivery is untrusted.
        $entity = 'order-9931:cust-4471';
        $signed = SignedMessageRef::sign($entity, self::SECRET);

        $payload = Fixtures::webhook('sms-status-delivered');
        $payload['status']['message_ref'] = $signed;

        $event = WebhookEvent::fromArray($payload);

        $this->assertSame($entity, SignedMessageRef::verify($event->messageRef(), self::SECRET));
    }

    public function test_does_not_verify_the_unsigned_refs_the_real_fixtures_actually_carry(): void
    {
        // The captured refs predate signing, so they must NOT verify. This
        // is the assertion that would fail if verify() ever fell back to
        // returning the ref when no signature is present.
        foreach (['sms-status-delivered', 'sms-inbound-with-last-message', 'link-hit-sms'] as $fixture) {
            $ref = WebhookEvent::fromArray(Fixtures::webhook($fixture))->messageRef();

            $this->assertNotNull($ref);
            $this->assertNull(SignedMessageRef::verify($ref, self::SECRET));
        }
    }

    public function test_survives_a_forged_delivery_aimed_at_a_real_entity(): void
    {
        // An attacker who knows the entity id but not the secret cannot make
        // a payload that correlates — which is exactly the protection
        // claimed, and no more than that: the rest of the forged payload is
        // still whatever they sent.
        $payload = Fixtures::webhook('sms-status-delivered');
        $payload['status']['message_ref'] = 'order-9931:cust-4471:0000000000000000';

        $event = WebhookEvent::fromArray($payload);

        $this->assertSame('order-9931:cust-4471:0000000000000000', $event->messageRef());
        $this->assertNull(SignedMessageRef::verify($event->messageRef(), self::SECRET));
    }
}
