<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use Throwable;

/**
 * A single MMS message, as returned by `POST /v2/mms` and `GET /v2/mms/{id}`.
 *
 * Unlike {@see SmsMessageData}, MMS returns a single `country` field rather
 * than a `recipient_country`/`sender_country` pair.
 */
final readonly class MmsMessageData
{
    public function __construct(
        public string $id,
        public string $recipient,
        public string $sender,
        public ?string $country,
        public ?string $subject,
        public ?string $message,
        public ?string $messageRef,
        /** @var array<int, string> */
        public array $contentUrls,
        public MessageStatus $status,
        public bool $trackLinks,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $contentUrls = is_array($data['content_urls'] ?? null) ? $data['content_urls'] : [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            recipient: (string) ($data['recipient'] ?? ''),
            sender: (string) ($data['sender'] ?? ''),
            country: is_string($data['country'] ?? null) ? $data['country'] : null,
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : null,
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            messageRef: is_string($data['message_ref'] ?? null) ? $data['message_ref'] : null,
            contentUrls: array_values(array_filter($contentUrls, 'is_string')),
            status: MessageStatus::fromApi(is_string($data['status'] ?? null) ? $data['status'] : null),
            trackLinks: (bool) ($data['track_links'] ?? false),
            createdAt: self::parseDate($data['created_at'] ?? null),
            updatedAt: self::parseDate($data['updated_at'] ?? null),
        );
    }

    /**
     * Parse a timestamp permissively.
     *
     * The API sends `2022-03-29T04:42:01.631708761Z` — nine fractional
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
