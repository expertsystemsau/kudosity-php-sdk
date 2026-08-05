<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use ExpertSystems\Kudosity\Enums\MessageStatus;

/**
 * Which status may overwrite which, for a message keyed on `status.id`.
 *
 * ## Why this is in the SDK and not in your application
 *
 * Multiple status events fire per message, arrival order is not guaranteed, and
 * deliveries are at-least-once. Every consumer that records delivery state has
 * to solve this, and getting it wrong does not raise an error — it silently
 * records the wrong outcome, which surfaces months later as delivery figures
 * nobody trusts.
 *
 * It is not hypothetical. In a captured run the `SENT` event was **redelivered
 * 60 seconds later carrying its original timestamp, arriving 57 seconds after
 * `DELIVERED`**, byte-identical to the first `SENT`. Nothing in the payload
 * distinguished the duplicate from the original, so the only defence is
 * comparing against what you already recorded. See
 * `tests/Fixtures/V2Webhooks/README.md` for the full timeline.
 *
 * ## Why a rank and not a boolean
 *
 * {@see MessageStatus::isTerminal()} answers a different question and cannot be
 * used here. It reports `true` for both `Delivered` and `Read`, so a rule of
 * "never overwrite a terminal status" would **drop an RCS `READ` that
 * legitimately follows `DELIVERED`** — a real sequence, and a real loss of
 * information. Progress through the lifecycle is ordered, not binary.
 *
 * The rank:
 *
 * | Rank | Statuses | Meaning |
 * |---|---|---|
 * | 0 | `Unknown` | unresolved; never overwrites anything |
 * | 1 | `Pending`, `Queued`, `PendingApproval` | accepted by us, not yet moving |
 * | 2 | `Submitted`, `Sent` | handed to the carrier |
 * | 3 | `Accepted` | carrier took it; **not** delivered |
 * | 4 | `SoftBounce` | temporarily undeliverable; a retry may still deliver |
 * | 5 | `Delivered`, `Failed`, `Rejected`, `Undeliverable`, `HardBounce`, `Other` | final outcome |
 * | 6 | `Read` | RCS only, and strictly after delivery |
 *
 * `SoftBounce` sits *below* the final outcomes deliberately: the handset was off
 * or out of range, and a later `Delivered` for the same message is the truth.
 * `Other` sits *with* them because it is the carrier's terminal catch-all — it
 * carries no lifecycle position of its own, and ranking it low would let a
 * stale `Sent` overwrite it.
 */
final class StatusPrecedence
{
    /**
     * Whether `$incoming` should replace `$recorded`.
     *
     * Equal ranks return false, which makes an at-least-once redelivery a no-op
     * rather than an update — so a consumer counting state changes does not
     * double-count one event.
     */
    public static function supersedes(MessageStatus $incoming, MessageStatus $recorded): bool
    {
        return self::rank($incoming) > self::rank($recorded);
    }

    /**
     * This status's position in the delivery lifecycle.
     *
     * A status the docs have not published yet resolves to
     * {@see MessageStatus::Unknown} and therefore ranks 0 — it never overwrites
     * a known value. That direction is chosen on purpose: losing a novel status
     * costs a reporting nuance, while letting it overwrite a recorded
     * `DELIVERED` costs the delivery record itself.
     */
    public static function rank(MessageStatus $status): int
    {
        return match ($status) {
            MessageStatus::Unknown => 0,
            MessageStatus::Pending, MessageStatus::Queued, MessageStatus::PendingApproval => 1,
            MessageStatus::Submitted, MessageStatus::Sent => 2,
            MessageStatus::Accepted => 3,
            MessageStatus::SoftBounce => 4,
            MessageStatus::Delivered, MessageStatus::Failed, MessageStatus::Rejected,
            MessageStatus::Undeliverable, MessageStatus::HardBounce, MessageStatus::Other => 5,
            MessageStatus::Read => 6,
        };
    }

    /**
     * Reduce a stream of status events to the one that should be recorded.
     *
     * Arrival order does not matter, and duplicates are absorbed. Events for
     * other messages are ignored rather than merged: `status.id` is the identity
     * here, and silently folding two messages together would be worse than
     * returning null.
     *
     * @param  iterable<StatusEvent>  $events
     */
    public static function reduce(iterable $events, ?string $statusId = null): ?StatusEvent
    {
        $winner = null;

        foreach ($events as $event) {
            if ($statusId !== null && $event->id !== $statusId) {
                continue;
            }

            if ($winner === null || self::supersedes($event->status, $winner->status)) {
                $winner = $event;
            }
        }

        return $winner;
    }
}
