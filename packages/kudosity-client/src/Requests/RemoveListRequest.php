<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Delete a contact list.
 *
 * @see https://developer.transmitsms.com/#remove-list
 */
class RemoveListRequest extends KudosityV1Request
{
    public function __construct(
        protected int $listId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('remove-list');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'list_id' => $this->listId,
        ];
    }
}
