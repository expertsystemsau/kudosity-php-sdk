<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use ExpertSystems\Kudosity\Concerns\GuardsMessageRef;
use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * A `message_ref` that carries its own signature.
 *
 * ## Why this exists
 *
 * Kudosity does not sign webhook deliveries — confirmed empirically, and the
 * complete observed header set is recorded on {@see WebhookEvent}. A receiver
 * therefore cannot verify that a delivery came from Kudosity at all. What it
 * *can* verify is that the delivery refers to one of its own entities, because
 * `message_ref` is echoed back intact on every event type.
 *
 * So: set the ref on the way out with {@see self::sign()}, check it on the way
 * in with {@see self::verify()}. A forger without the secret cannot fabricate a
 * ref that resolves to a real order, booking or conversation.
 *
 * ## What it does and does not protect
 *
 * It protects **correlation**, not the payload. Anyone can POST a syntactically
 * valid webhook at a public endpoint; this stops them making it point at
 * something of yours. The message text, status and phone numbers in an
 * unverified delivery are still unverified — treat a payload whose ref does not
 * verify as untrusted input, not as a slightly suspicious update.
 *
 * It also cannot help where there is no ref at all. An unsolicited inbound
 * message arrives with no `last_message`, so nothing links it to an outbound
 * and there is nothing to verify — see {@see InboundEvent::isCorrelated()}.
 *
 * ## Format
 *
 * `{entity}:{hmac}` — the entity you chose, a colon, and the first 16 hex
 * characters of `hash_hmac('sha256', entity, secret)`.
 *
 * **Parsing splits on the LAST colon, not the first.** Real refs are composite:
 * `order-9931:cust-4471` is a captured example that round-tripped intact
 * through a customer reply. Signing it produces
 * `order-9931:cust-4471:3f8a…`, and `explode(':', $ref)[0]` would silently
 * return `order-9931` — a truncated entity that looks plausible and points at
 * the wrong thing.
 *
 * ## One invariant no test can hold you to
 *
 * The comparison in {@see self::verify()} **must** stay `hash_equals()`. Swapping
 * it for `===` passes every test in this suite, because the difference is not in
 * the result — it is that `===` short-circuits on the first differing byte and
 * leaks, through timing, how much of a guess was right. That turns forgery from
 * 2^63 blind attempts into a byte-at-a-time walk.
 *
 * There is no unit test for it: timing is not observable from a test that runs on
 * shared CI. It is written down here instead, because a reviewer is the only
 * mechanism that catches it.
 */
final class SignedMessageRef
{
    use GuardsMessageRef;

    /**
     * Hex characters of the HMAC kept.
     *
     * Truncation is a deliberate trade against the 500-character ref budget.
     * 16 hex characters is 64 bits: forging one requires ~2^63 attempts, each
     * of which is a live webhook POST at an endpoint that can rate-limit. The
     * full 64 characters would spend an eighth of the budget on a margin that
     * changes nothing about the actual attack.
     */
    public const SIGNATURE_LENGTH = 16;

    private const SEPARATOR = ':';

    /**
     * Sign an entity reference for use as a `message_ref`.
     *
     * @param  string  $entity  Your own identifier — an order id, a composite key, whatever you will need back.
     *
     * @throws ValidationException If the entity is empty, the secret is empty, or the
     *                             signed result would exceed the documented ref length
     */
    public static function sign(string $entity, string $secret): string
    {
        if ($entity === '') {
            throw new ValidationException(
                message: 'Cannot sign an empty entity reference.',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if ($secret === '') {
            throw new ValidationException(
                message: 'Cannot sign a message_ref with an empty secret.',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if (mb_strlen($entity) > self::maxEntityLength()) {
            throw new ValidationException(
                message: sprintf(
                    'entity length (%d) leaves no room for a signature; the maximum is %d characters '.
                    'because the signed message_ref must fit within %d.',
                    mb_strlen($entity),
                    self::maxEntityLength(),
                    self::MAX_MESSAGE_REF_LENGTH,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        return $entity.self::SEPARATOR.self::signatureFor($entity, $secret);
    }

    /**
     * Recover the entity from a signed ref, or null if it does not verify.
     *
     * Returns null rather than throwing: a forged or unsigned ref is an
     * *expected* input on a public webhook endpoint, not an exceptional one, and
     * an exception per hostile POST is a denial-of-service lever.
     *
     * Null covers every failure the same way on purpose — wrong secret, tampered
     * entity, unsigned ref, truncated signature. Distinguishing them in the
     * return value would hand an attacker an oracle.
     */
    public static function verify(?string $ref, string $secret): ?string
    {
        if ($ref === null || $ref === '' || $secret === '') {
            return null;
        }

        $at = strrpos($ref, self::SEPARATOR);

        // No separator at all means an unsigned ref. Returning it would defeat
        // the entire point: an attacker would simply omit the signature.
        if ($at === false || $at === 0) {
            return null;
        }

        $entity = substr($ref, 0, $at);
        $provided = substr($ref, $at + 1);

        if ($provided === '') {
            return null;
        }

        return hash_equals(self::signatureFor($entity, $secret), $provided)
            ? $entity
            : null;
    }

    /**
     * Whether a ref carries a signature this secret produced.
     */
    public static function isValid(?string $ref, string $secret): bool
    {
        return self::verify($ref, $secret) !== null;
    }

    /**
     * The longest entity that still leaves room for a signature.
     */
    public static function maxEntityLength(): int
    {
        return self::MAX_MESSAGE_REF_LENGTH - self::SIGNATURE_LENGTH - mb_strlen(self::SEPARATOR);
    }

    private static function signatureFor(string $entity, string $secret): string
    {
        return substr(hash_hmac('sha256', $entity, $secret), 0, self::SIGNATURE_LENGTH);
    }
}
