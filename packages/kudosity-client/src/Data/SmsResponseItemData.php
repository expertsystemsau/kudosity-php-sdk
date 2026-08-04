<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for an individual SMS response/reply.
 */
final readonly class SmsResponseItemData
{
    public function __construct(
        public int $id,
        public int $messageId,
        public int $listId,
        public string $receivedAt,
        public ?string $firstName,
        public ?string $lastName,
        public string $msisdn,
        public string $response,
        public string $longcode,
        public ?string $originalMessage = null,
    ) {}

    /**
     * Create from API response array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            messageId: (int) $data['message_id'],
            listId: (int) $data['list_id'],
            receivedAt: $data['received_at'],
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            msisdn: (string) $data['msisdn'],
            response: $data['response'],
            longcode: (string) $data['longcode'],
            originalMessage: $data['original_message'] ?? null,
        );
    }

    /**
     * Get the full name of the responder if available.
     */
    public function getFullName(): ?string
    {
        if ($this->firstName === null && $this->lastName === null) {
            return null;
        }

        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }
}
