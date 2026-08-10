<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\MessageStatus;

/**
 * One per-message delivery record from `get-contact-sms-stats.json`.
 *
 * The endpoint does not return aggregate stats for a contact, despite its name.
 * It returns a paginated list of every message sent to that number, one row
 * each. Verified live 2026-08-10:
 *
 * ```json
 * {"page":{"count":4,"number":1},"total":32,
 *  "records":[{"message_id":1528493890,
 *              "datetime_send":"2025-12-05 15:24:57",
 *              "delivery_status":"delivered"}]}
 * ```
 *
 * Three fields, and only three — there is no cost, no recipient, no message
 * body. Anything an aggregate needs beyond a status tally has to come from a
 * different endpoint.
 */
final readonly class ContactSmsRecordData
{
    public function __construct(
        public int $messageId,
        public ?DateTimeImmutable $sentAt,
        /**
         * The raw status string as the API sent it, preserved verbatim.
         *
         * {@see self::status()} maps it onto {@see MessageStatus}, but the raw
         * value stays available because this endpoint's vocabulary has never
         * been documented upstream and a value the enum does not know must not
         * be silently lost.
         */
        public string $deliveryStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            messageId: (int) ($data['message_id'] ?? 0),
            sentAt: self::parseSentAt($data['datetime_send'] ?? null),
            deliveryStatus: (string) ($data['delivery_status'] ?? ''),
        );
    }

    /**
     * The status as an enum.
     *
     * A value the enum does not model comes back as {@see MessageStatus::Unknown}
     * rather than null — the enum carries an explicit case for exactly this, and
     * the string that actually arrived is still on {@see self::$deliveryStatus}.
     * So `Unknown` means "the API said something we do not have a case for",
     * which is why the raw value is kept alongside it.
     */
    public function status(): MessageStatus
    {
        return MessageStatus::fromApi($this->deliveryStatus);
    }

    /**
     * `datetime_send` arrives as `Y-m-d H:i:s` with no timezone marker.
     *
     * Parsed without forcing a timezone, so it is not silently relabelled as
     * UTC when the account reports local time — the V1 API is inconsistent
     * about this and guessing would produce a wrong instant rather than an
     * obviously missing one.
     */
    private static function parseSentAt(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
