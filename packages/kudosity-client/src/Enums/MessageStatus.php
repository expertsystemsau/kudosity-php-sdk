<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * Every message status the V2 API documents, across three vocabularies.
 *
 * `GET /v2/sms`'s filter accepts 13 values; webhook status events document 8;
 * the WhatsApp and RCS list endpoints document 5, one of which — QUEUED —
 * appears nowhere else. Rather than three enums that disagree, this is their
 * union, and each endpoint's README section records which subset it emits.
 *
 * The upstream docs say these lists will grow, so resolution goes through
 * {@see self::fromApi()} and lands on {@see self::Unknown} rather than
 * throwing: a client reading its own message history must not break because
 * Kudosity added a status.
 */
enum MessageStatus: string
{
    case Pending = 'PENDING';
    case Queued = 'QUEUED';
    case Submitted = 'SUBMITTED';
    case Sent = 'SENT';
    case Accepted = 'ACCEPTED';
    case Delivered = 'DELIVERED';
    case Read = 'READ';
    case Failed = 'FAILED';
    case Rejected = 'REJECTED';
    case Undeliverable = 'UNDELIVERABLE';
    case SoftBounce = 'SOFT_BOUNCE';
    case HardBounce = 'HARD_BOUNCE';
    case PendingApproval = 'PENDING_APPROVAL';
    case Other = 'OTHER';
    case Unknown = 'UNKNOWN';

    /**
     * Resolve a status from the API, tolerating case and novelty.
     *
     * The send endpoints answer lowercase (`"status": "delivered"`) while the
     * list filter uses uppercase (`DELIVERED`) — same value, two casings.
     */
    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }

    /**
     * Whether this status means the message reached the handset.
     *
     * `ACCEPTED` is deliberately excluded — it means the carrier took the
     * message, not that it arrived, and treating it as delivered is the
     * documented cause of over-reported success rates.
     */
    public function isDelivered(): bool
    {
        return $this === self::Delivered || $this === self::Read;
    }

    /**
     * Whether this status is final — no further status event will follow.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Read, self::Failed, self::Rejected,
            self::Undeliverable, self::HardBounce => true,
            default => false,
        };
    }
}
