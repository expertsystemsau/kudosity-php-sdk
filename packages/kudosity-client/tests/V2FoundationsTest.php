<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/V2FoundationsTest.php.
 *
 * SmsFallback's tests move here in full, consolidating its coverage into one
 * owning file (it was previously split with ValueObjectTest.php) — the same
 * move batch 1 made for Support\PhoneNumber. Eight of the eleven ported tests
 * below duplicate, or supersede, what ValueObjectTest.php used to assert
 * (different literal fixture values in most cases, same behaviour); those
 * eight tests come out of that file in this commit. Two are genuinely novel
 * — `fromResponse([])` and `fromResponse(['message' => 12345])` — confirmed
 * by an earlier review to have no counterpart anywhere. A third gap beyond
 * those two was found while porting:
 * `test_builds_from_a_response_payload_that_carries_a_message` below is the
 * only test anywhere that calls `fromResponse()` with *both* `message` and
 * `sender` present — ValueObjectTest.php's version only ever omitted the
 * sender. See the task report for the full reconciliation.
 */
#[CoversClass(MessageStatus::class)]
#[CoversClass(SmsFallback::class)]
final class V2FoundationsTest extends TestCase
{
    // -----------------------------------------------------------------
    // MessageStatus
    // -----------------------------------------------------------------

    /** @return array<int, array{0: string, 1: MessageStatus}> */
    public static function documentedApiValues(): array
    {
        return [
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
        ];
    }

    #[DataProvider('documentedApiValues')]
    public function test_maps_every_documented_status_across_all_three_v2_vocabularies(
        string $api,
        MessageStatus $expected,
    ): void {
        $this->assertSame($expected, MessageStatus::fromApi($api));
    }

    public function test_resolves_an_unrecognised_or_absent_status_to_unknown_rather_than_throwing(): void
    {
        // The docs state these lists will grow, so a new upstream value must
        // not break a client that is only reading messages. (The
        // 'TELEPORTED' case here is also asserted, in isolation, by
        // V2TransportTest — kept in both since it's inseparable here from
        // the null/'' assertions this compound test exists to cover.)
        $this->assertSame(MessageStatus::Unknown, MessageStatus::fromApi('TELEPORTED'));
        $this->assertSame(MessageStatus::Unknown, MessageStatus::fromApi(null));
        $this->assertSame(MessageStatus::Unknown, MessageStatus::fromApi(''));
    }

    public function test_accepts_a_lowercase_status_since_the_send_response_returns_lowercase(): void
    {
        // POST /v2/sms answers "status": "delivered"; the list filter uses
        // "DELIVERED". Same value, two casings, one enum.
        $this->assertSame(MessageStatus::Delivered, MessageStatus::fromApi('delivered'));
        $this->assertSame(MessageStatus::Pending, MessageStatus::fromApi('pending'));
    }

    public function test_treats_only_delivered_and_read_as_is_delivered_deliberately_excluding_accepted(): void
    {
        // ACCEPTED means the carrier took the message, not that it arrived.
        // Treating it as delivered is the documented cause of over-reported
        // success rates — do not "fix" this to include it.
        $this->assertTrue(MessageStatus::Delivered->isDelivered());
        $this->assertTrue(MessageStatus::Read->isDelivered());
        $this->assertFalse(MessageStatus::Accepted->isDelivered());
    }

    public function test_is_terminal_for_exactly_the_documented_final_statuses_and_no_others(): void
    {
        // An allow-list of the full membership, not spot checks either way —
        // this project has a documented history of tests that only denied a
        // couple of hardcoded values.
        $terminal = [
            MessageStatus::Delivered,
            MessageStatus::Read,
            MessageStatus::Failed,
            MessageStatus::Rejected,
            MessageStatus::Undeliverable,
            MessageStatus::HardBounce,
        ];

        foreach (MessageStatus::cases() as $status) {
            $this->assertSame(in_array($status, $terminal, true), $status->isTerminal());
        }
    }

    // -----------------------------------------------------------------
    // SmsFallback
    // -----------------------------------------------------------------

    public function test_builds_an_sms_fallback_body_with_the_sender_omitted_when_absent(): void
    {
        $this->assertSame(['message' => 'Shorter plain text'], (new SmsFallback('Shorter plain text'))->toArray());
    }

    public function test_includes_the_sender_when_given(): void
    {
        $this->assertSame(
            ['message' => 'Body', 'sender' => '61481074185'],
            (new SmsFallback('Body', '61481074185'))->toArray(),
        );
    }

    public function test_rejects_an_empty_fallback_message_which_the_api_requires(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('message');

        new SmsFallback('');
    }

    public function test_round_trips_from_an_api_payload(): void
    {
        $f = SmsFallback::fromArray(['message' => 'Body', 'sender' => '614810']);

        $this->assertSame('Body', $f->message);
        $this->assertSame('614810', $f->sender);
    }

    public function test_builds_from_a_response_payload_that_carries_a_message(): void
    {
        // fromResponse() is the read path shared by WhatsApp and RCS, both of
        // which echo sms_fallback back. It exists so that reading a message
        // back never throws on this field — see the null cases below. The
        // only test anywhere (either suite) that exercises fromResponse()
        // with a sender *present* — ValueObjectTest.php's equivalent always
        // omitted it.
        $f = SmsFallback::fromResponse(['message' => 'Body', 'sender' => '614810']);

        $this->assertInstanceOf(SmsFallback::class, $f);
        $this->assertSame('Body', $f?->message);
        $this->assertSame('614810', $f?->sender);
    }

    public function test_builds_from_a_response_payload_with_no_sender(): void
    {
        // Folded in from ValueObjectTest.php's equivalent coverage, which
        // additionally checked the instance and message value rather than
        // just the null sender — both checks kept here so consolidating
        // SmsFallback's tests into this one owning file loses nothing.
        $f = SmsFallback::fromResponse(['message' => 'Body']);

        $this->assertNotNull($f);
        $this->assertSame('Body', $f->message);
        $this->assertNull($f->sender);
    }

    public function test_returns_null_rather_than_throwing_when_a_response_fallback_has_an_empty_message(): void
    {
        // The constructor rejects this, correctly, for a request-shaped
        // object. The read path returns null instead: a null is inspectable,
        // an exception thrown part-way through hydrating a message is not.
        $this->assertNull(SmsFallback::fromResponse(['message' => '', 'sender' => '614810']));
    }

    public function test_returns_null_rather_than_throwing_when_a_response_fallback_has_no_message_key(): void
    {
        $this->assertNull(SmsFallback::fromResponse(['sender' => '614810']));
    }

    public function test_returns_null_rather_than_throwing_on_an_empty_response_fallback(): void
    {
        $this->assertNull(SmsFallback::fromResponse([]));
    }

    public function test_returns_null_rather_than_throwing_when_a_response_fallback_message_is_not_a_string(): void
    {
        $this->assertNull(SmsFallback::fromResponse(['message' => 12345]));
    }

    public function test_keeps_the_constructor_invariant_intact_fromresponse_is_a_separate_path(): void
    {
        // The point of the split: adding a permissive read path must not
        // make the request-shaped object constructible without a message.
        $this->assertThrowsValidationException(fn () => new SmsFallback(''));
        $this->assertThrowsValidationException(fn () => SmsFallback::fromArray(['sender' => '614810']));
    }

    private function assertThrowsValidationException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
        }
    }
}
