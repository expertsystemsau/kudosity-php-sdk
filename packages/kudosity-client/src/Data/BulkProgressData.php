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
         * field that reports failure counts under this name. Read
         * {@see self::$skipped} instead, which is what the API actually
         * reports for rows it could not import. Kept for compatibility rather
         * than removed, since this is a published, readonly property.
         *
         * @deprecated 2.2.0 Use {@see self::$skipped}.
         */
        public int $errors,
        /**
         * Rows actually added to the list.
         */
        public int $imported = 0,
        /**
         * Rows that matched an existing contact. Only the first is kept.
         */
        public int $duplicates = 0,
        /**
         * Rows not imported: blank rows, invalid numbers, or a blank mobile
         * cell. This is the real "failed row" count.
         */
        public int $skipped = 0,
        /**
         * Rows belonging to an already opted-out contact. **Not imported.**
         */
        public int $optout = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        // total/processed previously read the absent 'total'/'processed'
        // keys and were always 0; the real keys are 'importlength' and
        // 'completed'.
        //
        // Confirmed live 2026-08-10 with a deliberately mixed 2-row import
        // (one valid number, one invalid) so the counts could not be confused
        // with each other:
        //   {"list_id":11205319,"status":"completed","importlength":2,
        //    "completed":2,"duplicates":0,"skipped":1,"optout":0,"imported":1}
        // importlength counts every row including invalid ones, completed
        // counts rows processed, and imported counts only rows added — so
        // importlength == completed != imported on any import with bad rows.
        return new self(
            listId: (int) ($data['list_id'] ?? 0),
            status: (string) ($data['status'] ?? 'unknown'),
            total: (int) ($data['importlength'] ?? $data['total'] ?? 0),
            processed: (int) ($data['completed'] ?? $data['processed'] ?? 0),
            errors: (int) ($data['errors'] ?? 0),
            imported: (int) ($data['imported'] ?? 0),
            duplicates: (int) ($data['duplicates'] ?? 0),
            skipped: (int) ($data['skipped'] ?? 0),
            optout: (int) ($data['optout'] ?? 0),
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
