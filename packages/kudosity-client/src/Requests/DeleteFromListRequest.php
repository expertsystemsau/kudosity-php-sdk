<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Delete a contact from a list.
 *
 * @see https://developer.transmitsms.com/#delete-from-list
 */
class DeleteFromListRequest extends KudosityV1Request
{
    public function __construct(
        protected int $listId,
        protected string $mobile,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('delete-from-list');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'list_id' => $this->listId,
            'msisdn' => $this->mobile,
        ];
    }
}
