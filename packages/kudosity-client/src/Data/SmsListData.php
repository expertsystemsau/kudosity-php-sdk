<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for SMS list information.
 *
 * Returned when sending SMS to a list.
 */
final readonly class SmsListData
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    /**
     * Create from API response array.
     *
     * @param  array{id: int, name: string}  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: $data['name'],
        );
    }
}
