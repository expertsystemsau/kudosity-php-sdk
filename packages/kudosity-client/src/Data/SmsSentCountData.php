<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * SMS sent count DTO.
 */
final readonly class SmsSentCountData
{
    public function __construct(
        public int $count,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            count: (int) ($data['count'] ?? 0),
        );
    }
}
