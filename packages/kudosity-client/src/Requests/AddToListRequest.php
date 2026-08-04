<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\ContactData;
use Saloon\Http\Response;

/**
 * Add a contact to a list.
 *
 * @see https://developer.transmitsms.com/#add-to-list
 */
class AddToListRequest extends KudosityV1Request
{
    protected ?string $firstName = null;

    protected ?string $lastName = null;

    protected ?string $countryCode = null;

    /**
     * @var array<string, string>
     */
    protected array $customFields = [];

    public function __construct(
        protected int $listId,
        protected string $mobile,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('add-to-list');
    }

    /**
     * Set the contact's first name.
     */
    public function firstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    /**
     * Set the contact's last name.
     */
    public function lastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    /**
     * Set the country code for number formatting.
     */
    public function countryCode(string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    /**
     * Set a custom field value.
     *
     * @param  int  $fieldNumber  Field number (1-10)
     * @param  string  $value  Field value
     */
    public function field(int $fieldNumber, string $value): self
    {
        if ($fieldNumber >= 1 && $fieldNumber <= 10) {
            $this->customFields["field_{$fieldNumber}"] = $value;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'list_id' => $this->listId,
            'msisdn' => $this->mobile,
        ];

        if ($this->firstName !== null) {
            $body['firstname'] = $this->firstName;
        }

        if ($this->lastName !== null) {
            $body['lastname'] = $this->lastName;
        }

        if ($this->countryCode !== null) {
            $body['countrycode'] = $this->countryCode;
        }

        return array_merge($body, $this->customFields);
    }

    public function createDtoFromResponse(Response $response): ContactData
    {
        return ContactData::fromResponse($response->json());
    }
}
