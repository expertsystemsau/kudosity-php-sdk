<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\ListData;
use Saloon\Http\Response;

/**
 * Create a new contact list.
 *
 * @see https://developer.transmitsms.com/#add-list
 */
class AddListRequest extends KudosityV1Request
{
    /**
     * @var array<string, string>
     */
    protected array $fields = [];

    public function __construct(
        protected string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('add-list');
    }

    /**
     * Add a custom field to the list.
     *
     * @param  int  $fieldNumber  Field number (1-10)
     * @param  string  $fieldName  Field name/label
     */
    public function field(int $fieldNumber, string $fieldName): self
    {
        if ($fieldNumber >= 1 && $fieldNumber <= 10) {
            $this->fields["field_{$fieldNumber}"] = $fieldName;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_merge(
            ['name' => $this->name],
            $this->fields,
        );
    }

    public function createDtoFromResponse(Response $response): ListData
    {
        return ListData::fromResponse($response->json());
    }
}
