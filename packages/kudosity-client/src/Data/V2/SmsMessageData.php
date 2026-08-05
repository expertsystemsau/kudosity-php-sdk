<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use Throwable;

/**
 * A single SMS message, as returned by `POST /v2/sms` and `GET /v2/sms/{id}`.
 *
 * The send response omits `is_sandbox`, so it is not represented here at all
 * rather than modelled as an always-null field.
 */
final readonly class SmsMessageData
{
    public function __construct(
        public string $id,
        public string $recipient,
        public ?string $recipientCountry,
        public string $sender,
        public ?string $senderCountry,
        public ?string $messageRef,
        public string $message,
        public MessageStatus $status,
        public int $smsCount,
        public bool $isGsm,
        public ?string $routedVia,
        public bool $trackLinks,
        public ?string $direction,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $routedVia = is_string($data['routed_via'] ?? null) ? $data['routed_via'] : null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            recipient: (string) ($data['recipient'] ?? ''),
            recipientCountry: is_string($data['recipient_country'] ?? null) ? $data['recipient_country'] : null,
            sender: (string) ($data['sender'] ?? ''),
            senderCountry: is_string($data['sender_country'] ?? null) ? $data['sender_country'] : null,
            messageRef: is_string($data['message_ref'] ?? null) ? $data['message_ref'] : null,
            message: (string) ($data['message'] ?? ''),
            status: MessageStatus::fromApi(is_string($data['status'] ?? null) ? $data['status'] : null),
            smsCount: (int) ($data['sms_count'] ?? 0),
            isGsm: (bool) ($data['is_gsm'] ?? false),
            routedVia: $routedVia === '' ? null : $routedVia,
            trackLinks: (bool) ($data['track_links'] ?? false),
            direction: is_string($data['direction'] ?? null) ? $data['direction'] : null,
            createdAt: self::parseDate($data['created_at'] ?? null),
            updatedAt: self::parseDate($data['updated_at'] ?? null),
        );
    }

    /**
     * Parse a timestamp permissively.
     *
     * The API sends `2022-03-28T06:12:52.450674000Z` — nine fractional
     * digits, which `DateTimeImmutable::createFromFormat(RFC3339_EXTENDED,
     * ...)` cannot parse (it expects exactly six). `new DateTimeImmutable()`
     * accepts it because PHP's parser truncates fractional seconds itself.
     */
    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
