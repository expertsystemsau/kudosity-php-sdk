<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\ContactData;
use Saloon\Http\Response;

/**
 * Get a contact from a list.
 *
 * @see https://developer.transmitsms.com/#get-contact
 */
class GetContactRequest extends TransmitSmsRequest
{
    public function __construct(
        protected int $listId,
        protected string $mobile,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-contact');
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

    public function createDtoFromResponse(Response $response): ContactData
    {
        return ContactData::fromResponse($response->json());
    }
}
