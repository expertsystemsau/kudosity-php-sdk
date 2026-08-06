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
 */
#[CoversClass(StatusPrecedence::class)]
#[CoversClass(SignedMessageRef::class)]
final class WebhookGuardsTest extends TestCase
{
    public function test_a_late_sent_does_not_regress_a_recorded_delivered(): void
    {
        // Observed live: a SENT redelivered 60s later carrying its original
        // timestamp, arriving 57s AFTER its own DELIVERED, byte-identical to
        // the original. Nothing in the payload marks it as a duplicate.
        $this->assertFalse(StatusPrecedence::supersedes(MessageStatus::Sent, MessageStatus::Delivered));
    }

    public function test_a_read_receipt_legitimately_follows_delivery(): void
    {
        // This is why it is a rank rather than a terminal-status check.
        // MessageStatus::isTerminal() is true for BOTH Delivered and Read, so a
        // "never overwrite a terminal status" rule silently drops RCS read
        // receipts.
        $this->assertTrue(StatusPrecedence::supersedes(MessageStatus::Read, MessageStatus::Delivered));
        $this->assertTrue(MessageStatus::Delivered->isTerminal());
        $this->assertTrue(MessageStatus::Read->isTerminal());
    }

    public function test_replaying_the_same_status_does_not_supersede_itself(): void
    {
        // At-least-once means the identical event arrives twice. Treating the
        // second as a state change double-counts.
        $this->assertFalse(StatusPrecedence::supersedes(MessageStatus::Delivered, MessageStatus::Delivered));
    }

    public function test_anything_supersedes_nothing_recorded(): void
    {
        $this->assertTrue(StatusPrecedence::supersedes(MessageStatus::Sent, MessageStatus::Unknown));
    }

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

    public function test_a_signed_ref_round_trips(): void
    {
        $signed = SignedMessageRef::sign('order-9931', 'secret');

        $this->assertSame('order-9931', SignedMessageRef::verify($signed, 'secret'));
        $this->assertTrue(SignedMessageRef::isValid($signed, 'secret'));
    }

    public function test_a_composite_entity_is_parsed_from_the_last_colon(): void
    {
        // Real captured refs are composite — "order-9931:cust-4471" — so
        // explode(':', $ref)[0] truncates the entity while still looking like
        // it worked.
        $signed = SignedMessageRef::sign('order-9931:cust-4471', 'secret');

        $this->assertSame('order-9931:cust-4471', SignedMessageRef::verify($signed, 'secret'));
    }

    public function test_a_ref_signed_with_another_secret_does_not_verify(): void
    {
        $signed = SignedMessageRef::sign('order-9931', 'secret');

        $this->assertNull(SignedMessageRef::verify($signed, 'a-different-secret'));
        $this->assertFalse(SignedMessageRef::isValid($signed, 'a-different-secret'));
    }

    public function test_an_unsigned_ref_does_not_verify(): void
    {
        // What an attacker sends: a plausible entity with no signature at all.
        $this->assertNull(SignedMessageRef::verify('order-9931', 'secret'));
        $this->assertNull(SignedMessageRef::verify(null, 'secret'));
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
