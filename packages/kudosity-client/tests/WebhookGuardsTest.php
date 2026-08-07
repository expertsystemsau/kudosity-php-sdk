<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Tests\Fixtures\Fixtures;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two guards a receiver needs, because of two things the API does.
 *
 * Deliveries are at-least-once AND unordered, so recorded state can be
 * regressed by a redelivery. And deliveries are unsigned — confirmed in writing
 * by Kudosity on 2026-08-06 — so the only authenticity signal available is one
 * we generate ourselves.
 *
 * Four StatusPrecedence tests moved out in the Task 7b batch 5 port: each was
 * a single-pair subset of V2StatusPrecedenceTest.php's data-provider tests (or,
 * for the read-receipt test, a combination of one such pair and its own
 * rank-comparison test). See that file's docblock and the task report for the
 * full reconciliation. `test_it_reduces_the_captured_out_of_order_pair_to_delivered`
 * stayed — it is a genuinely different call (reduce() over the two captured
 * fixtures) from anything ported there.
 *
 * Three SignedMessageRef tests moved out the same way, folded into
 * V2SignedMessageRefTest.php: the round-trip test (its isValid() check is now
 * one more assertion on a stronger test there), the composite-entity test (a
 * strict subset of a stronger ported test there) and the unsigned-ref test
 * (its two cases split across two stronger ported tests there). The
 * different-secret test and the signature-length test stayed — the former is
 * an exact behavioural duplicate of a root test that was therefore not
 * re-ported, and the latter derives SIGNATURE_LENGTH from an actual sign()
 * output, which nothing ported there does. See the task report.
 */
#[CoversClass(StatusPrecedence::class)]
#[CoversClass(SignedMessageRef::class)]
final class WebhookGuardsTest extends TestCase
{
    public function test_it_reduces_the_captured_out_of_order_pair_to_delivered(): void
    {
        // The two captured fixtures are the real pair for one message, and
        // deliberately fed in the wrong order here.
        $events = [
            WebhookEvent::fromArray(Fixtures::webhook('sms-status-delivered')),
            WebhookEvent::fromArray(Fixtures::webhook('sms-status-sent')),
        ];

        $winner = StatusPrecedence::reduce($events);

        $this->assertInstanceOf(StatusEvent::class, $winner);
        $this->assertSame(MessageStatus::Delivered, $winner->status);
    }

    public function test_a_ref_signed_with_another_secret_does_not_verify(): void
    {
        $signed = SignedMessageRef::sign('order-9931', 'secret');

        $this->assertNull(SignedMessageRef::verify($signed, 'a-different-secret'));
        $this->assertFalse(SignedMessageRef::isValid($signed, 'a-different-secret'));
    }

    public function test_the_signature_is_the_documented_length(): void
    {
        // The ref rides in message_ref, which the API caps, so the signature's
        // length is part of the budget maxEntityLength() reports.
        $signed = SignedMessageRef::sign('order-9931', 'secret');

        $this->assertSame(
            SignedMessageRef::SIGNATURE_LENGTH,
            strlen(substr($signed, strrpos($signed, ':') + 1)),
        );
        $this->assertGreaterThan(0, SignedMessageRef::maxEntityLength());
    }
}
