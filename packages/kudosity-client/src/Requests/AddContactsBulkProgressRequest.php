<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\BulkProgressData;
use Saloon\Http\Response;

/**
 * Check progress of a bulk contact add operation.
 *
 * @see https://developer.transmitsms.com/#add-contacts-bulk-progress
 */
class AddContactsBulkProgressRequest extends KudosityV1Request
{
    public function __construct(
        protected int $listId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('add-contacts-bulk-progress');
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

    public function createDtoFromResponse(Response $response): BulkProgressData
    {
        return BulkProgressData::fromResponse($response->json());
    }
}
