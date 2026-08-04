<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for Link Hit callback payload.
 *
 * This DTO represents the data sent by TransmitSMS when a tracked
 * link in an SMS message is clicked.
 */
final readonly class LinkHitCallbackData
{
    public function __construct(
        public int $messageId,
        public string $mobile,
        public string $url,
        public string $clickedAt,
        public ?string $userAgent,
        public ?string $ipAddress,
    ) {}

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
            url: (string) ($data['url'] ?? $data['link'] ?? ''),
            clickedAt: (string) ($data['clicked_at'] ?? $data['datetime'] ?? date('Y-m-d H:i:s')),
            userAgent: $data['user_agent'] ?? null,
            ipAddress: $data['ip_address'] ?? $data['ip'] ?? null,
        );
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
            'url' => $this->url,
            'clicked_at' => $this->clickedAt,
            'user_agent' => $this->userAgent,
            'ip_address' => $this->ipAddress,
        ];
    }
}
