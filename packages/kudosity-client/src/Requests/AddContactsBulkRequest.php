<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\BulkAddResultData;
use Saloon\Http\Response;

/**
 * Bulk add contacts to a list from a CSV file URL.
 *
 * @see https://developer.transmitsms.com/#add-contacts-bulk
 */
class AddContactsBulkRequest extends TransmitSmsRequest
{
    protected ?int $listId = null;

    protected ?string $name = null;

    /**
     * @var array<string, string>
     */
    protected array $fields = [];

    public function __construct(
        protected string $fileUrl,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('add-contacts-bulk');
    }

    /**
     * Add to an existing list by ID.
     */
    public function listId(int $listId): self
    {
        $this->listId = $listId;

        return $this;
    }

    /**
     * Create a new list with this name.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Define a custom field mapping.
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
        $body = [
            'file_url' => $this->fileUrl,
        ];

        if ($this->listId !== null) {
            $body['list_id'] = $this->listId;
        }

        if ($this->name !== null) {
            $body['name'] = $this->name;
        }

        return array_merge($body, $this->fields);
    }

    public function createDtoFromResponse(Response $response): BulkAddResultData
    {
        return BulkAddResultData::fromResponse($response->json());
    }
}
