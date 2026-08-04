<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for account balance response.
 *
 * @see https://docs.saloon.dev/digging-deeper/data-transfer-objects
 */
final readonly class BalanceData
{
    public function __construct(
        public float $balance,
        public string $currency,
    ) {}

    /**
     * Create a BalanceData instance from API response array.
     *
     * @param  array{balance: float|int, currency: string}  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            balance: (float) $data['balance'],
            currency: $data['currency'],
        );
    }
}
