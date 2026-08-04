<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Message/campaign information DTO.
 *
 * Returned by get-sms endpoint.
 */
final readonly class MessageData
{
    public function __construct(
        public int $messageId,
        public string $sendAt,
        public int $recipients,
        public float $cost,
        public int $sms,
        public string $message,
        public ?DeliveryStatsData $deliveryStats = null,
        public ?SmsListData $list = null,
    ) {}

    /**
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
            message: $data['message'] ?? '',
            deliveryStats: isset($data['delivery_stats']) ? DeliveryStatsData::fromResponse($data['delivery_stats']) : null,
            list: isset($data['list']) ? SmsListData::fromResponse($data['list']) : null,
        );
    }
}
