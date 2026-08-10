<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Message report DTO.
 *
 * Returned by get-message-report endpoint.
 */
final readonly class MessageReportData
{
    /**
     * @param  SmsSentItemData[]  $messages
     */
    public function __construct(
        public int $totalCount,
        public int $page,
        public int $pageCount,
        public array $messages,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $messages = [];
        $messageList = $data['messages'] ?? $data['list'] ?? [];

        foreach ($messageList as $item) {
            $messages[] = SmsSentItemData::fromResponse($item);
        }

        // Live get-message-report.json has no 'total_count' key — the real
        // key is 'messages_total' ('sms_total' duplicates it in every
        // observed response). Falling back to count($messages) silently
        // substitutes the current page's item count for the account-wide
        // total, which is what this DTO did before this fix.
        return new self(
            totalCount: (int) ($data['messages_total'] ?? $data['sms_total'] ?? $data['total_count'] ?? count($messages)),
            page: (int) ($data['page']['number'] ?? 1),
            pageCount: (int) ($data['page']['count'] ?? 1),
            messages: $messages,
        );
    }

    /**
     * Check if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->page < $this->pageCount;
    }
}
