<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Contact SMS statistics DTO.
 *
 * Returned by get-contact-sms-stats endpoint.
 */
final readonly class ContactSmsStatsData
{
    public function __construct(
        public string $mobile,
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
            mobile: (string) ($data['mobile'] ?? ''),
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
}
