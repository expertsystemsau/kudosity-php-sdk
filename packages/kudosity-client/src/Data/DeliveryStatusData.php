<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Delivery status for a specific recipient.
 *
 * Returned by get-sms-delivery-status endpoint.
 */
final readonly class DeliveryStatusData
{
    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public int $messageId,
        public string $senderId,
        public string $mobile,
        public string $sendAt,
        public ?string $datetime,
        public string $status,
        public string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $stats = $data['stats'] ?? $data;

        return new self(
            messageId: (int) $stats['message_id'],
            senderId: (string) $stats['sender_id'],
            mobile: (string) $stats['mobile'],
            sendAt: $stats['send_at'],
            datetime: $stats['datetime'] ?? null,
            status: $stats['status'],
            message: $stats['message'] ?? '',
        );
    }

    /**
     * Check if the message was delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if the message is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the message failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
