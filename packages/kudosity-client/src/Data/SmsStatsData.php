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
        /**
         * Hard bounces — permanent failures. Subset of {@see self::$bounced}.
         */
        public int $hardBounced = 0,
        /**
         * Soft bounces — transient failures. Subset of {@see self::$bounced}.
         */
        public int $softBounced = 0,
        /**
         * Clicks on tracked links. Counts machine fetches too: a messaging
         * app's link preview registers as a hit, so this is not an engagement
         * metric.
         */
        public int $linkHits = 0,
        /**
         * Distinct recipients, as opposed to {@see self::$sent}, which counts
         * SMS parts. A single long message to one recipient reports
         * `sent > recipientCount`.
         */
        public int $recipientCount = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $stats = $data['stats'] ?? $data;

        // Live get-sms-stats.json (2026-08-07) has no 'sent' key at all — the
        // real key is 'total' — and 'opt-outs' is hyphenated, not 'optouts'.
        // delivered/pending/bounced/responses are already correct.
        //
        // Verified again live 2026-08-10 against a real message_id. The full
        // response is:
        //   {"hard_bounced":0,"soft_bounced":0,"total":1,"recipientCount":1,
        //    "delivered":1,"pending":0,"bounced":0,"responses":0,
        //    "opt-outs":0,"link_hits":0}
        // Note 'recipientCount' really is camelCase while every sibling key is
        // snake_case — do not "correct" it to recipient_count.
        return new self(
            sent: (int) ($stats['total'] ?? $stats['sent'] ?? 0),
            delivered: (int) ($stats['delivered'] ?? 0),
            pending: (int) ($stats['pending'] ?? 0),
            bounced: (int) ($stats['bounced'] ?? 0),
            responses: (int) ($stats['responses'] ?? 0),
            optouts: (int) ($stats['opt-outs'] ?? $stats['optouts'] ?? 0),
            hardBounced: (int) ($stats['hard_bounced'] ?? 0),
            softBounced: (int) ($stats['soft_bounced'] ?? 0),
            linkHits: (int) ($stats['link_hits'] ?? 0),
            recipientCount: (int) ($stats['recipientCount'] ?? 0),
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
