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
        /**
         * Always 0 — the API's add-contacts-bulk-progress response has no
         * field that reports failure counts under this name. A failed import
         * is instead reflected across duplicates/skipped/optout, none of
         * which this DTO exposes yet (2.1.0 work). Kept for compatibility
         * rather than removed, since this is a published, readonly property.
         */
        public int $errors,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        // The vendored kudosity-contacts-lists skill documents the real
        // response: {"list_id":...,"status":"completed","importlength":2,
        // "completed":2,"duplicates":0,"skipped":0,"optout":0,"imported":2}.
        // total/processed previously read the absent 'total'/'processed'
        // keys and were always 0; the real keys are 'importlength' and
        // 'completed'.
        return new self(
            listId: (int) ($data['list_id'] ?? 0),
            status: (string) ($data['status'] ?? 'unknown'),
            total: (int) ($data['importlength'] ?? $data['total'] ?? 0),
            processed: (int) ($data['completed'] ?? $data['processed'] ?? 0),
            errors: (int) ($data['errors'] ?? 0),
        );
    }

    /**
     * Check if the bulk operation is complete.
     *
     * The API reports 'completed', not 'complete'.
     */
    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the bulk operation is still processing.
     *
     * The API reports 'in progress', not 'processing'.
     */
    public function isProcessing(): bool
    {
        return $this->status === 'in progress';
    }

    /**
     * Get progress percentage.
     */
    public function getProgressPercent(): float
    {
        return $this->total > 0 ? ($this->processed / $this->total) * 100 : 0.0;
    }
}
