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
        // get-message-report.json's items are keyed id/msisdn/sent_at, not
        // message_id/mobile/send_at — confirmed live 2026-08-07. The
        // non-nullable $sendAt made the missing key a fatal TypeError rather
        // than a silently-wrong default, so both keys are checked here.
        return new self(
            messageId: (int) ($data['id'] ?? $data['message_id'] ?? 0),
            mobile: (string) ($data['msisdn'] ?? $data['mobile'] ?? ''),
            sendAt: (string) ($data['sent_at'] ?? $data['send_at'] ?? ''),
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
