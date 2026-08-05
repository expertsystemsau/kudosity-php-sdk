<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;

/**
 * The documented limit on `message_ref`, and the guard that enforces it.
 *
 * One home rather than five. It was copied verbatim into all four V2 send
 * requests, and {@see SignedMessageRef} needs
 * the same number — a signing helper whose limit disagrees with the send
 * guard's is worse than no limit at all, because it produces refs the API
 * rejects at send time rather than at signing time.
 *
 * The constant stays reachable as `SendSmsV2Request::MAX_MESSAGE_REF_LENGTH`
 * and friends: trait constants resolve through the using class, so nothing
 * leaves the public surface.
 */
trait GuardsMessageRef
{
    /**
     * The documented maximum for the caller's own reference field.
     */
    public const MAX_MESSAGE_REF_LENGTH = 500;

    /**
     * @throws ValidationException If the ref is longer than the API accepts
     */
    protected static function guardMessageRef(?string $messageRef): void
    {
        if ($messageRef === null) {
            return;
        }

        // mb_strlen, not strlen: the limit is characters, and a ref carrying a
        // multi-byte order reference would otherwise be rejected early.
        $length = mb_strlen($messageRef);

        if ($length > self::MAX_MESSAGE_REF_LENGTH) {
            throw new ValidationException(
                message: sprintf(
                    'message_ref length (%d) exceeds the maximum of %d characters',
                    $length,
                    self::MAX_MESSAGE_REF_LENGTH,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }
}
