<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

use ExpertSystems\Kudosity\Contracts\SentMessage;
use ExpertSystems\Kudosity\Enums\MessageStatus;

/**
 * Data transfer object for send-sms response.
 */
final readonly class SmsData implements SentMessage
{
    public function __construct(
        public int $messageId,
        public string $sendAt,
        public int $recipients,
        public float $cost,
        public int $sms,
        public ?SmsListData $list = null,
    ) {}

    /**
     * Create from API response array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            messageId: (int) $data['message_id'],
            sendAt: $data['send_at'],
            recipients: (int) $data['recipients'],
            cost: (float) $data['cost'],
            sms: (int) $data['sms'],
            list: isset($data['list']) ? SmsListData::fromResponse($data['list']) : null,
        );
    }

    /**
     * The V1 `message_id`, as a string.
     *
     * String rather than int because {@see SentMessage} spans both APIs and V2's
     * ids are UUIDs — typing this as int would imply the two id spaces are
     * interchangeable.
     */
    public function id(): string
    {
        return (string) $this->messageId;
    }

    public function recipientCount(): int
    {
        return $this->recipients;
    }

    /**
     * Always null: the V1 send response carries no status field.
     *
     * Not `Pending` — inventing one would be indistinguishable from a status the
     * API actually sent. Read it back through `reporting()`.
     */
    public function status(): ?MessageStatus
    {
        return null;
    }
}
