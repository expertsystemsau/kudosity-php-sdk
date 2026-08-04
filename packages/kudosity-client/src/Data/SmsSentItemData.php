<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Individual sent SMS item DTO.
 *
 * Returned in get-sms-sent and get-user-sms-sent responses.
 */
final readonly class SmsSentItemData
{
    public function __construct(
        public int $messageId,
        public string $mobile,
        public string $sendAt,
        public ?string $datetime,
        public string $status,
        public string $message,
        public float $cost,
        public ?SmsListData $list = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            messageId: (int) $data['message_id'],
            mobile: (string) $data['mobile'],
            sendAt: $data['send_at'],
            datetime: $data['datetime'] ?? null,
            status: $data['status'] ?? 'pending',
            message: $data['message'] ?? '',
            cost: (float) ($data['cost'] ?? 0),
            list: isset($data['list']) ? SmsListData::fromResponse($data['list']) : null,
        );
    }

    /**
     * Check if the message was delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    /**
     * Check if the message is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the message bounced.
     */
    public function isBounced(): bool
    {
        return in_array($this->status, ['soft-bounce', 'hard-bounce', 'bounced'], true);
    }
}
