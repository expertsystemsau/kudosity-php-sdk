<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Tests\Fixtures\Fixtures;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/V2StatusPrecedenceTest.php.
 *
 * Four of WebhookGuardsTest.php's pre-existing StatusPrecedence tests turned
 * out to be single-case subsets of what's ported here — each one asserts
 * exactly one pair this file's `precedencePairs()`/`redeliveredStatuses()`/
 * `overwritesUnresolved()` data providers already cover as a row, or, for the
 * READ/DELIVERED-terminal test, a combination of one such row and
 * `test_ranks_read_above_delivered_even_though_both_are_terminal` below (which
 * additionally pins the rank comparison, which the old test never checked).
 * Removed from WebhookGuardsTest.php in this same commit rather than kept as
 * a duplicate:
 *
 * - `test_a_late_sent_does_not_regress_a_recorded_delivered` — one row of
 *   `precedencePairs()` ('SENT over DELIVERED').
 * - `test_a_read_receipt_legitimately_follows_delivery` — its supersedes()
 *   assertion is one row of `precedencePairs()` ('READ over DELIVERED'); its
 *   two isTerminal() assertions are a subset of
 *   `test_ranks_read_above_delivered_even_though_both_are_terminal`.
 * - `test_replaying_the_same_status_does_not_supersede_itself` — one row of
 *   `redeliveredStatuses()` (`Delivered`).
 * - `test_anything_supersedes_nothing_recorded` — one row of
 *   `overwritesUnresolved()` (`Sent`).
 *
 * `test_it_reduces_the_captured_out_of_order_pair_to_delivered` was left in
 * place: it calls reduce() with exactly the two captured fixtures in
 * out-of-order arrival, which is a different call (a 2-event reduce(), not a
 * pairwise supersedes()) from anything ported here. See the task report for
 * the full reconciliation.
 */
#[CoversClass(StatusPrecedence::class)]
final class V2StatusPrecedenceTest extends TestCase
{
    /**
     * @param  array<int, string>  $fixtures
     */
    private static function replay(array $fixtures): ?StatusEvent
    {
        $events = array_map(
            static fn (string $name): StatusEvent => WebhookEvent::fromArray(Fixtures::webhook($name)),
            $fixtures,
        );

        return StatusPrecedence::reduce($events);
    }

    // -------------------------------------------------------------------
    // The pairs, both directions each
    // -------------------------------------------------------------------

    /** @return array<string, array{0: MessageStatus, 1: MessageStatus, 2: bool}> */
    public static function precedencePairs(): array
    {
        return [
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
        ];
    }

    /**
     * Both directions of every pair, because a one-directional assertion
     * cannot tell a working comparison from a constant.
     */
    #[DataProvider('precedencePairs')]
    public function test_decides_precedence_between_two_statuses_in_both_directions(
        MessageStatus $incoming,
        MessageStatus $recorded,
        bool $expected,
    ): void {
        $this->assertSame($expected, StatusPrecedence::supersedes($incoming, $recorded));
    }

    /** @return array<int, array{0: MessageStatus}> */
    public static function redeliveredStatuses(): array
    {
        return [
            [MessageStatus::Sent],
            [MessageStatus::Delivered],
            [MessageStatus::Failed],
            [MessageStatus::Read],
            [MessageStatus::Unknown],
        ];
    }

    /**
     * Equal rank must be false, not true: deliveries are at-least-once, and a
     * consumer counting state changes would otherwise double-count one event.
     */
    #[DataProvider('redeliveredStatuses')]
    public function test_treats_a_redelivery_of_the_same_status_as_no_update_at_all(MessageStatus $status): void
    {
        $this->assertFalse(StatusPrecedence::supersedes($status, $status));
    }

    /** @return array<int, array{0: MessageStatus}> */
    public static function knownStatusesExceptRead(): array
    {
        return [
            [MessageStatus::Pending],
            [MessageStatus::Sent],
            [MessageStatus::Accepted],
            [MessageStatus::Delivered],
            [MessageStatus::Failed],
            [MessageStatus::Read],
        ];
    }

    /**
     * A status Kudosity ships next quarter resolves to Unknown. Losing it
     * costs a reporting nuance; letting it overwrite DELIVERED costs the
     * record.
     */
    #[DataProvider('knownStatusesExceptRead')]
    public function test_never_lets_an_unresolved_status_overwrite_a_known_one(MessageStatus $recorded): void
    {
        $this->assertFalse(StatusPrecedence::supersedes(MessageStatus::Unknown, $recorded));
    }

    /** @return array<int, array{0: MessageStatus}> */
    public static function overwritesUnresolved(): array
    {
        return [
            [MessageStatus::Pending],
            [MessageStatus::Sent],
            [MessageStatus::Delivered],
            [MessageStatus::Failed],
            [MessageStatus::Read],
        ];
    }

    #[DataProvider('overwritesUnresolved')]
    public function test_always_lets_a_known_status_overwrite_an_unresolved_one(MessageStatus $incoming): void
    {
        $this->assertTrue(StatusPrecedence::supersedes($incoming, MessageStatus::Unknown));
    }

    // -------------------------------------------------------------------
    // Why isTerminal() could not have been used
    // -------------------------------------------------------------------

    public function test_ranks_read_above_delivered_even_though_both_are_terminal(): void
    {
        // The reason this class is a rank and not a boolean.
        // MessageStatus::isTerminal() is true for both, so "never overwrite a
        // terminal status" would drop the RCS read receipt. Asserted against
        // isTerminal() directly so the trap is visible to whoever reads this
        // next.
        $this->assertTrue(MessageStatus::Delivered->isTerminal());
        $this->assertTrue(MessageStatus::Read->isTerminal());
        $this->assertGreaterThan(
            StatusPrecedence::rank(MessageStatus::Delivered),
            StatusPrecedence::rank(MessageStatus::Read),
        );
    }

    public function test_gives_every_status_a_rank_so_a_new_enum_case_cannot_be_silently_unranked(): void
    {
        // A full-membership check rather than spot-checks: match() without a
        // default means an unranked new case is a fatal, and this asserts
        // that stays true.
        foreach (MessageStatus::cases() as $case) {
            $this->assertGreaterThanOrEqual(0, StatusPrecedence::rank($case));
        }

        $this->assertSame(0, StatusPrecedence::rank(MessageStatus::Unknown));
    }

    // -------------------------------------------------------------------
    // The observed sequence, replayed
    // -------------------------------------------------------------------

    public function test_ends_at_delivered_for_the_exact_sequence_the_live_api_delivered(): void
    {
        // SENT, then DELIVERED, then SENT AGAIN — the real redelivery, 60s
        // later, carrying its original timestamp. Not an invented case: see
        // the timeline in Fixtures/V2Webhooks/README.md.
        $winner = self::replay(['sms-status-sent', 'sms-status-delivered', 'sms-status-sent']);

        $this->assertSame(MessageStatus::Delivered, $winner?->status);
    }

    public function test_ends_at_delivered_whatever_order_those_three_arrive_in(): void
    {
        foreach ([
            ['sms-status-sent', 'sms-status-delivered', 'sms-status-sent'],
            ['sms-status-sent', 'sms-status-sent', 'sms-status-delivered'],
            ['sms-status-delivered', 'sms-status-sent', 'sms-status-sent'],
        ] as $order) {
            $this->assertSame(MessageStatus::Delivered, self::replay($order)?->status);
        }
    }

    public function test_reports_no_update_when_delivered_is_redelivered_on_top_of_itself(): void
    {
        // The at-least-once case in its purest form.
        $delivered = WebhookEvent::fromArray(Fixtures::webhook('sms-status-delivered'));

        $this->assertFalse(StatusPrecedence::supersedes($delivered->status, $delivered->status));
        $this->assertSame(
            MessageStatus::Delivered,
            self::replay(['sms-status-delivered', 'sms-status-delivered'])?->status,
        );
    }

    public function test_returns_null_for_an_empty_stream_rather_than_inventing_a_status(): void
    {
        $this->assertNull(StatusPrecedence::reduce([]));
    }

    public function test_ignores_events_for_a_different_message_when_a_status_id_is_given(): void
    {
        // status.id is the identity. Folding two messages together would
        // corrupt both records, so a non-matching event is skipped rather
        // than merged.
        $mine = WebhookEvent::fromArray(Fixtures::webhook('sms-status-sent'));
        $other = WebhookEvent::fromArray(Fixtures::webhook('mms-status-delivered'));

        $this->assertNotSame($other->id, $mine->id);

        $winner = StatusPrecedence::reduce([$mine, $other], $mine->id);

        // DELIVERED outranks SENT, so an unfiltered reduce would have
        // returned the other message's event — that is what makes this
        // assertion meaningful.
        $this->assertSame(MessageStatus::Sent, $winner?->status);
        $this->assertSame($mine->id, $winner?->id);
        $this->assertSame($other->id, StatusPrecedence::reduce([$mine, $other])?->id);
    }

    public function test_returns_null_when_no_event_matches_the_requested_status_id(): void
    {
        $mine = WebhookEvent::fromArray(Fixtures::webhook('sms-status-sent'));

        $this->assertNull(StatusPrecedence::reduce([$mine], 'a-different-id'));
    }
}
