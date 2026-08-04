<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Edit a contact in a list.
 *
 * @see https://developers.kudosity.com
 */
class EditListMemberRequest extends KudosityV1Request
{
    protected ?string $firstName = null;

    protected ?string $lastName = null;

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
        return $this->formatEndpoint('edit-list-member');
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

        return array_merge($body, $this->customFields);
    }
}
