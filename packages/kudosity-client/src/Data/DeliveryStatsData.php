<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Delivery statistics for an SMS message/campaign.
 */
final readonly class DeliveryStatsData
{
    public function __construct(
        public int $delivered,
        public int $pending,
        public int $bounced,
        public int $responses,
        public int $optouts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            delivered: (int) ($data['delivered'] ?? 0),
            pending: (int) ($data['pending'] ?? 0),
            bounced: (int) ($data['bounced'] ?? 0),
            responses: (int) ($data['responses'] ?? 0),
            optouts: (int) ($data['optouts'] ?? 0),
        );
    }

    /**
     * Get total messages (delivered + pending + bounced).
     */
    public function getTotal(): int
    {
        return $this->delivered + $this->pending + $this->bounced;
    }

    /**
     * Get delivery rate as percentage.
     */
    public function getDeliveryRate(): float
    {
        $total = $this->getTotal();

        return $total > 0 ? ($this->delivered / $total) * 100 : 0.0;
    }
}
