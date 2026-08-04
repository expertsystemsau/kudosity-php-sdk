<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Bulk add contacts result DTO.
 *
 * Returned by add-contacts-bulk endpoint.
 */
final readonly class BulkAddResultData
{
    public function __construct(
        public int $listId,
        public ?string $jobId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            listId: (int) ($data['list_id'] ?? 0),
            jobId: $data['job_id'] ?? null,
        );
    }
}
