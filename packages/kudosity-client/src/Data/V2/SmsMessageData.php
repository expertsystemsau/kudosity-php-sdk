<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\ParsesV2Timestamps;
use ExpertSystems\Kudosity\Contracts\SentMessage;
use ExpertSystems\Kudosity\Enums\MessageStatus;

/**
 * A single SMS message, as returned by `POST /v2/sms` and `GET /v2/sms/{id}`.
 *
 * The send response omits `is_sandbox`, so it is not represented here at all
 * rather than modelled as an always-null field.
 */
final readonly class SmsMessageData implements SentMessage
{
    use ParsesV2Timestamps;

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
            createdAt: self::parseTimestamp($data['created_at'] ?? null),
            updatedAt: self::parseTimestamp($data['updated_at'] ?? null),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Always 1: `POST /v2/sms` takes exactly one recipient.
     *
     * Not `smsCount`, which counts message *segments* — a 200-character message
     * to one person is one recipient and two segments, and conflating them
     * over-reports reach.
     */
    public function recipientCount(): int
    {
        return 1;
    }

    /**
     * Narrower than {@see SentMessage::status()}'s `?MessageStatus`, deliberately.
     *
     * A V2 send response always carries a status, so this never returns null —
     * and return types are covariant, so saying so costs nothing and tells a
     * caller holding a concrete `SmsMessageData` that it need not null-check.
     * The nullable signature exists on the interface for V1's sake alone.
     */
    public function status(): MessageStatus
    {
        return $this->status;
    }
}
