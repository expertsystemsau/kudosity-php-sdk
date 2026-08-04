<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for Reply callback payload.
 *
 * This DTO represents the data sent by Kudosity when a reply
 * message is received from a recipient.
 */
final readonly class ReplyCallbackData
{
    public function __construct(
        public int $messageId,
        public string $mobile,
        public string $message,
        public string $receivedAt,
        public ?int $responseId,
        public ?string $longcode,
        public ?string $firstName,
        public ?string $lastName,
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
            mobile: (string) ($data['mobile'] ?? $data['msisdn'] ?? $data['from'] ?? ''),
            message: (string) ($data['message'] ?? $data['response'] ?? $data['body'] ?? ''),
            receivedAt: (string) ($data['received_at'] ?? $data['datetime'] ?? date('Y-m-d H:i:s')),
            responseId: isset($data['response_id']) ? (int) $data['response_id'] : (isset($data['id']) ? (int) $data['id'] : null),
            longcode: $data['longcode'] ?? $data['to'] ?? null,
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
        );
    }

    /**
     * Get the full name of the sender if available.
     */
    public function getFullName(): ?string
    {
        if ($this->firstName === null && $this->lastName === null) {
            return null;
        }

        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
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
            'message' => $this->message,
            'received_at' => $this->receivedAt,
            'response_id' => $this->responseId,
            'longcode' => $this->longcode,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
        ];
    }
}
