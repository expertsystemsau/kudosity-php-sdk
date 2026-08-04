<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Keyword DTO.
 *
 * Returned by get-keywords, add-keyword endpoints.
 *
 * Default Value Handling:
 * - id: Defaults to 0 if missing (should not happen in valid API responses)
 * - keyword, number: Empty string if not provided (API should always provide these)
 * - status: Defaults to 'active' (client assumption; API typically provides this)
 * - Optional fields (forwardUrl, forwardEmail, listId, welcomeMessage): Null if not configured
 */
final readonly class KeywordData
{
    /**
     * @param  int  $id  The keyword ID
     * @param  string  $keyword  The keyword text
     * @param  string  $number  The virtual number associated with this keyword
     * @param  string  $status  Keyword status: 'active', 'inactive' (defaults to 'active')
     * @param  string|null  $forwardUrl  URL to forward incoming messages to
     * @param  string|null  $forwardEmail  Email to forward incoming messages to
     * @param  int|null  $listId  ID of list to add senders to
     * @param  string|null  $welcomeMessage  Auto-reply message when keyword is received
     */
    public function __construct(
        public int $id,
        public string $keyword,
        public string $number,
        public string $status,
        public ?string $forwardUrl = null,
        public ?string $forwardEmail = null,
        public ?int $listId = null,
        public ?string $welcomeMessage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $keyword = $data['keyword'] ?? $data;

        return new self(
            id: (int) ($keyword['id'] ?? $keyword['keyword_id'] ?? 0),
            keyword: (string) ($keyword['keyword'] ?? $keyword['word'] ?? ''),
            number: (string) ($keyword['number'] ?? ''),
            status: (string) ($keyword['status'] ?? 'active'),
            forwardUrl: $keyword['forward_url'] ?? null,
            forwardEmail: $keyword['forward_email'] ?? null,
            listId: isset($keyword['list_id']) ? (int) $keyword['list_id'] : null,
            welcomeMessage: $keyword['welcome_message'] ?? null,
        );
    }

    /**
     * Check if the keyword is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
