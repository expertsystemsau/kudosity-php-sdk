<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * SMS statistics DTO.
 *
 * Returned by get-sms-stats endpoint.
 */
final readonly class SmsStatsData
{
    public function __construct(
        public int $sent,
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
        $stats = $data['stats'] ?? $data;

        return new self(
            sent: (int) ($stats['sent'] ?? 0),
            delivered: (int) ($stats['delivered'] ?? 0),
            pending: (int) ($stats['pending'] ?? 0),
            bounced: (int) ($stats['bounced'] ?? 0),
            responses: (int) ($stats['responses'] ?? 0),
            optouts: (int) ($stats['optouts'] ?? 0),
        );
    }

    /**
     * Get the delivery rate as a percentage.
     */
    public function getDeliveryRate(): float
    {
        return $this->sent > 0 ? ($this->delivered / $this->sent) * 100 : 0.0;
    }

    /**
     * Get the bounce rate as a percentage.
     */
    public function getBounceRate(): float
    {
        return $this->sent > 0 ? ($this->bounced / $this->sent) * 100 : 0.0;
    }

    /**
     * Get the response rate as a percentage.
     */
    public function getResponseRate(): float
    {
        return $this->sent > 0 ? ($this->responses / $this->sent) * 100 : 0.0;
    }
}
