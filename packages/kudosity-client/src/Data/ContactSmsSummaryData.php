<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Aggregate delivery stats for one contact, counted from the per-message
 * records `get-contact-sms-stats.json` actually returns.
 *
 * **Why this exists rather than {@see ContactSmsStatsData}.** That DTO models a
 * `{mobile, stats:{sent,delivered,pending,bounced,responses,optouts}}` shape the
 * endpoint has never returned. Two of its six counts — `responses` and
 * `optouts` — cannot be derived from the records at all: a record carries only
 * `message_id`, `datetime_send` and `delivery_status`. Populating them with 0
 * would hand a caller a number indistinguishable from a real zero, which is the
 * failure this codebase keeps paying for. So this class reports exactly what the
 * records support and nothing more.
 *
 * {@see self::$byStatus} is the raw tally, keyed on the API's own status
 * strings. It is deliberately not an enum-keyed map: this endpoint's status
 * vocabulary is undocumented upstream, so a value the enum does not model must
 * still be countable rather than dropped.
 */
final readonly class ContactSmsSummaryData
{
    /**
     * @param  array<string, int>  $byStatus  Count per raw API status string,
     *                                        highest first.
     */
    public function __construct(
        public string $mobile,
        /**
         * Records counted. Equals the API's reported `total` when every page
         * was read; see {@see self::$complete}.
         */
        public int $total,
        public array $byStatus,
        /**
         * False when aggregation stopped before consuming every page — the
         * counts are then a lower bound, not a total.
         */
        public bool $complete = true,
    ) {}

    /**
     * Count for one raw status string. Unknown status → 0.
     */
    public function countOf(string $status): int
    {
        return $this->byStatus[$status] ?? 0;
    }

    public function delivered(): int
    {
        return $this->countOf('delivered');
    }

    public function pending(): int
    {
        return $this->countOf('pending');
    }

    public function bounced(): int
    {
        return $this->countOf('bounced') + $this->countOf('hard-bounced') + $this->countOf('soft-bounced');
    }

    /**
     * Delivered as a percentage of records counted.
     */
    public function getDeliveryRate(): float
    {
        return $this->total > 0 ? ($this->delivered() / $this->total) * 100 : 0.0;
    }
}
