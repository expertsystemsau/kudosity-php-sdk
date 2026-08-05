<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * Where a sender registration sits in the registry lifecycle.
 *
 * `NEW` → `SUBMITTED_TO_REGISTRY` → `PENDING_CUSTOMER` → `PENDING_APPROVAL` →
 * `VERIFIED` → `READY_TO_USE`
 *
 * ## The one that costs people a day
 *
 * **`VERIFIED` does not mean you can send.** It means *provisioning*. Only
 * {@see self::ReadyToUse} can send, and a send on `VERIFIED` fails in a way that
 * looks like anything but a sender problem — which is the entire reason
 * {@see self::isReadyToUse()} exists rather than leaving callers to compare
 * strings.
 *
 * **`PENDING_CUSTOMER` is waiting on you**, not on the registry. Read
 * `status_reason` on the registration and act on it; nothing will move until you
 * do.
 *
 * The registry is expected to add states, so resolution goes through
 * {@see self::fromApi()} and lands on {@see self::Unknown}. Note that
 * `isReadyToUse()` is **false** for `Unknown`: defaulting an unrecognised state
 * to sendable is how a half-provisioned sender reaches production.
 */
enum SenderStatus: string
{
    case New = 'NEW';
    case SubmittedToRegistry = 'SUBMITTED_TO_REGISTRY';
    case PendingCustomer = 'PENDING_CUSTOMER';
    case PendingApproval = 'PENDING_APPROVAL';
    case Verified = 'VERIFIED';
    case ReadyToUse = 'READY_TO_USE';
    case Unknown = 'UNKNOWN';

    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }

    /**
     * Whether this sender can actually send right now.
     *
     * True for `READY_TO_USE` alone.
     */
    public function isReadyToUse(): bool
    {
        return $this === self::ReadyToUse;
    }

    /**
     * Whether the registry is waiting on the account holder to do something.
     */
    public function needsYourAction(): bool
    {
        return $this === self::PendingCustomer;
    }

    /**
     * Whether this registration is still moving through the registry.
     *
     * `Unknown` counts as in progress: an unrecognised state is more likely to be
     * a new intermediate step than a new terminal one, and treating it as
     * finished would report a sender as settled when it is not.
     */
    public function isInProgress(): bool
    {
        return ! $this->isReadyToUse();
    }
}
