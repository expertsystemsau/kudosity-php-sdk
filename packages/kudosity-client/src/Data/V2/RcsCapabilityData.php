<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use ExpertSystems\Kudosity\Enums\RcsCapabilityCode;

/**
 * One result from `POST /v2/rcs/capabilities`, one per requested phone number.
 *
 * The endpoint returns results in `data.results`, in the same order the
 * numbers were requested in — the request class does not re-sort or index
 * them by number.
 */
final readonly class RcsCapabilityData
{
    public function __construct(
        public string $phoneNumber,
        public RcsCapabilityCode $code,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            phoneNumber: (string) ($data['phone_number'] ?? ''),
            code: RcsCapabilityCode::fromApi(is_string($data['code'] ?? null) ? $data['code'] : null),
        );
    }
}
