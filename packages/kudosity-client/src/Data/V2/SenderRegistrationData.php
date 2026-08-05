<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\ParsesV2Timestamps;
use ExpertSystems\Kudosity\Enums\SenderRegistrationType;
use ExpertSystems\Kudosity\Enums\SenderStatus;

/**
 * A sender registration from `GET /v2/senders/registrations`.
 *
 * ## What is verified here, and what is not
 *
 * The **envelope** is confirmed against the live API:
 * `{"data":{"registrations":[…]},"meta":{"pagination":{…}}}`.
 *
 * The **item shape is not.** The test account holds zero registrations, so no
 * real item has ever been observed, and the vendored skill documents only three
 * paths: `details.<type>.status`, `status_reason` and `child_account_id`. Every
 * field below is therefore read defensively and nothing is required — a missing
 * key yields null rather than an error.
 *
 * {@see self::$raw} carries the payload verbatim, and it is the honest answer
 * until a registration exists to model against. **If you are reading this with a
 * real registration in hand, dump `raw` and widen this DTO** — that is the
 * intended next step, not a workaround.
 *
 * ## Reading the status
 *
 * The skill documents the status at `details.alphanumeric.status`, but the API
 * only accepts `type: PERSONAL_MOBILE_NUMBER`, so the key under `details` is
 * whatever matches the registration's own type. Rather than hardcode either
 * guess, {@see self::statusFrom()} takes the first `status` it finds under any
 * `details` key. Both documented and observed reality are satisfied without
 * inventing a path.
 */
final readonly class SenderRegistrationData
{
    use ParsesV2Timestamps;

    /**
     * @param  array<string, mixed>  $raw  The registration exactly as returned.
     */
    public function __construct(
        public ?string $id,
        public ?string $sender,
        public ?string $country,
        public SenderRegistrationType $type,
        public SenderStatus $status,
        public ?string $statusReason,
        public ?string $childAccountId,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : null,
            sender: is_string($data['sender'] ?? null) ? $data['sender'] : null,
            country: is_string($data['country'] ?? null) ? $data['country'] : null,
            type: SenderRegistrationType::fromApi(is_string($data['type'] ?? null) ? $data['type'] : null),
            status: self::statusFrom($data),
            statusReason: is_string($data['status_reason'] ?? null) ? $data['status_reason'] : null,
            childAccountId: is_string($data['child_account_id'] ?? null) ? $data['child_account_id'] : null,
            createdAt: self::parseTimestamp($data['created_at'] ?? null),
            updatedAt: self::parseTimestamp($data['updated_at'] ?? null),
            raw: $data,
        );
    }

    /**
     * Whether this sender can actually send right now.
     *
     * Convenience for the distinction that costs people a day: `VERIFIED` means
     * provisioning, and only `READY_TO_USE` can send.
     */
    public function isReadyToUse(): bool
    {
        return $this->status->isReadyToUse();
    }

    /**
     * Whether the registry is waiting on the account holder.
     *
     * When true, {@see self::$statusReason} says what for.
     */
    public function needsYourAction(): bool
    {
        return $this->status->needsYourAction();
    }

    /**
     * The status, from whichever `details` key this registration's type uses.
     *
     * Falls back to a top-level `status` first, since that is where a flat
     * response would put it.
     *
     * @param  array<string, mixed>  $data
     */
    private static function statusFrom(array $data): SenderStatus
    {
        if (is_string($data['status'] ?? null)) {
            return SenderStatus::fromApi($data['status']);
        }

        if (! is_array($data['details'] ?? null)) {
            return SenderStatus::Unknown;
        }

        foreach ($data['details'] as $detail) {
            if (is_array($detail) && is_string($detail['status'] ?? null)) {
                return SenderStatus::fromApi($detail['status']);
            }
        }

        return SenderStatus::Unknown;
    }
}
