<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Contact list DTO.
 *
 * Returned by get-list, add-list endpoints.
 */
final readonly class ListData
{
    /**
     * @param  array<string, string>  $fields  Custom fields (field_1 => name, etc.)
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $members,
        public array $fields = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $list = $data['list'] ?? $data;

        // Extract custom fields
        $fields = [];
        for ($i = 1; $i <= 10; $i++) {
            $key = "field_{$i}";
            if (isset($list[$key]) && $list[$key] !== '') {
                $fields[$key] = (string) $list[$key];
            }
        }

        return new self(
            id: (int) ($list['id'] ?? $list['list_id'] ?? 0),
            name: (string) ($list['name'] ?? ''),
            members: (int) ($list['members'] ?? 0),
            fields: $fields,
        );
    }
}
