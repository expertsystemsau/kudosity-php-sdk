<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

use ExpertSystems\Kudosity\Exceptions\KudosityException;

/**
 * Contact SMS statistics DTO.
 *
 * Returned by get-contact-sms-stats endpoint — or rather, this is the shape
 * the endpoint was assumed to return. Confirmed live 2026-08-07/2026-08-10:
 * the real response is a paginated list of per-message delivery receipts
 * ({page, total, records[]}), never {mobile, stats:{sent,delivered,...}}.
 * Modeling the real shape correctly needs aggregation logic this DTO does
 * not have (2.1.0 work); fromResponse() throws on it rather than silently
 * returning an all-zero DTO, which is a worse failure than an error — a
 * consumer reading zeros cannot tell "no activity" from "wrong shape".
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
     *
     * @throws KudosityException if the response is the real paginated
     *                           records[] shape rather than the
     *                           {mobile, stats:{...}} shape this DTO models
     */
    public static function fromResponse(array $data): self
    {
        if (isset($data['records']) && ! isset($data['mobile'])) {
            throw new KudosityException(
                'get-contact-sms-stats.json returned a response shape ContactSmsStatsData cannot represent '.
                '(a paginated list of per-message records, not aggregate contact stats). '.
                'This is a known API/SDK mismatch; see ContactSmsStatsData\'s class docblock.'
            );
        }

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
