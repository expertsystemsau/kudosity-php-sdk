<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for send-sms response.
 */
final readonly class SmsData
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
}
