<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Virtual number DTO.
 *
 * Returned by get-number, lease-number endpoints.
 */
final readonly class NumberData
{
    public function __construct(
        public string $number,
        public string $country,
        public ?string $forwardEmail = null,
        public ?string $forwardUrl = null,
        public ?int $listId = null,
        public ?string $expiryDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $number = $data['number'] ?? $data;

        return new self(
            number: (string) ($number['number'] ?? $number['msisdn'] ?? ''),
            country: (string) ($number['country'] ?? ''),
            forwardEmail: $number['forward_email'] ?? null,
            forwardUrl: $number['forward_url'] ?? null,
            listId: isset($number['list_id']) ? (int) $number['list_id'] : null,
            expiryDate: $number['expiry_date'] ?? null,
        );
    }
}
