<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for DLR (Delivery Receipt) callback payload.
 *
 * This DTO represents the data sent by TransmitSMS when a delivery
 * receipt is received for a sent message.
 */
final readonly class DlrCallbackData
{
    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  string  $status  Status is normalized to lowercase
     */
    public function __construct(
        public int $messageId,
        public string $mobile,
        string $status,
        public ?string $datetime,
        public ?string $senderId,
        public ?string $errorCode,
        public ?string $errorDescription,
    ) {
        // Normalize status to lowercase for consistent comparison
        $this->status = strtolower($status);
    }

    /** @var string Normalized status (lowercase) */
    public string $status;

    /**
     * Create from callback request data.
     *
     * @param  array<string, mixed>  $data  The request query parameters or body
     */
    public static function fromRequest(array $data): self
    {
        return new self(
            messageId: (int) ($data['message_id'] ?? 0),
            mobile: (string) ($data['mobile'] ?? $data['msisdn'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_PENDING),
            datetime: $data['datetime'] ?? $data['delivery_time'] ?? null,
            senderId: $data['sender_id'] ?? $data['from'] ?? null,
            errorCode: $data['error_code'] ?? $data['error'] ?? null,
            errorDescription: $data['error_description'] ?? $data['error_msg'] ?? null,
        );
    }

    /**
     * Check if the message was delivered successfully.
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if the message delivery is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the message delivery failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'mobile' => $this->mobile,
            'status' => $this->status,
            'datetime' => $this->datetime,
            'sender_id' => $this->senderId,
            'error_code' => $this->errorCode,
            'error_description' => $this->errorDescription,
        ];
    }
}
