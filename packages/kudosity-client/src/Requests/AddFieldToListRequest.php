<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Add a custom field to a list.
 *
 * @see https://developer.transmitsms.com/#add-field-to-list
 */
class AddFieldToListRequest extends TransmitSmsRequest
{
    public function __construct(
        protected int $listId,
        protected int $fieldNumber,
        protected string $fieldName,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('add-field-to-list');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'list_id' => $this->listId,
            "field_{$this->fieldNumber}" => $this->fieldName,
        ];
    }
}
