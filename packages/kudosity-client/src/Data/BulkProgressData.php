<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Bulk add progress DTO.
 *
 * Returned by add-contacts-bulk-progress endpoint.
 */
final readonly class BulkProgressData
{
    public function __construct(
        public int $listId,
        public string $status,
        public int $total,
        public int $processed,
        public int $errors,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            listId: (int) ($data['list_id'] ?? 0),
            status: (string) ($data['status'] ?? 'unknown'),
            total: (int) ($data['total'] ?? 0),
            processed: (int) ($data['processed'] ?? 0),
            errors: (int) ($data['errors'] ?? 0),
        );
    }

    /**
     * Check if the bulk operation is complete.
     */
    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    /**
     * Check if the bulk operation is still processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Get progress percentage.
     */
    public function getProgressPercent(): float
    {
        return $this->total > 0 ? ($this->processed / $this->total) * 100 : 0.0;
    }
}
