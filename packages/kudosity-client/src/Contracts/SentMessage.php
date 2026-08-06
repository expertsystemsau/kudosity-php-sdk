<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Enums\MessageStatus;

/**
 * The little that a V1 and a V2 send response genuinely have in common.
 *
 * The Laravel SMS channel routes to V2 by default and to V1 only when the
 * message uses something V2 cannot do. That decision is made *inside* the
 * channel, so without this contract the channel's return type would change based
 * on a choice the caller never made — and a caller reading `$sent->id` would
 * break the first time someone added `sendAt()` to a notification.
 *
 * Three members, chosen because they are the only ones that mean the same thing
 * on both sides:
 *
 * - {@see self::id()} — the V2 UUID, or V1's integer `message_id` as a string.
 *   A string on both sides because the two id spaces are not interchangeable and
 *   an int would imply they are.
 * - {@see self::recipientCount()} — always 1 for V2, which takes exactly one
 *   recipient; V1's `recipients` count otherwise.
 * - {@see self::status()} — **nullable, and null for every V1 send.** The V1 send
 *   response carries no status at all. Returning `Pending` for it would be an
 *   invention a caller cannot distinguish from a status the API really sent,
 *   which is the exact failure mode Phase 3's enum work exists to prevent. Read
 *   the status back with `reporting()` (V1) or `sms()->get()` (V2) instead.
 */
interface SentMessage
{
    /**
     * The provider's id for this send.
     */
    public function id(): string;

    /**
     * How many recipients this send went to.
     */
    public function recipientCount(): int;

    /**
     * The status at send time, or null when the API did not report one.
     */
    public function status(): ?MessageStatus;
}
