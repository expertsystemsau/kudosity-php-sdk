<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Lease number result DTO.
 *
 * Returned by lease-number endpoint.
 */
final readonly class LeaseResultData
{
    public function __construct(
        public string $number,
        public float $cost,
        public ?string $expiryDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            number: (string) ($data['number'] ?? ''),
            cost: (float) ($data['cost'] ?? 0),
            expiryDate: $data['expiry_date'] ?? null,
        );
    }
}
