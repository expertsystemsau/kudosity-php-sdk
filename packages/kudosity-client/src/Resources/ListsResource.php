<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\BulkAddResultData;
use ExpertSystems\Kudosity\Data\BulkProgressData;
use ExpertSystems\Kudosity\Data\ContactData;
use ExpertSystems\Kudosity\Data\ListData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use ExpertSystems\Kudosity\Requests\AddContactsBulkProgressRequest;
use ExpertSystems\Kudosity\Requests\AddContactsBulkRequest;
use ExpertSystems\Kudosity\Requests\AddFieldToListRequest;
use ExpertSystems\Kudosity\Requests\AddListRequest;
use ExpertSystems\Kudosity\Requests\AddToListRequest;
use ExpertSystems\Kudosity\Requests\DeleteFromListRequest;
use ExpertSystems\Kudosity\Requests\EditListMemberRequest;
use ExpertSystems\Kudosity\Requests\GetContactRequest;
use ExpertSystems\Kudosity\Requests\GetListRequest;
use ExpertSystems\Kudosity\Requests\GetListsRequest;
use ExpertSystems\Kudosity\Requests\OptoutListMemberRequest;
use ExpertSystems\Kudosity\Requests\RemoveListRequest;

/**
 * Lists resource for managing contact lists.
 *
 * @see https://developers.kudosity.com
 */
class ListsResource extends Resource
{
    /**
     * Create a new contact list.
     *
     * @param  string  $name  The list name
     *
     * @throws KudosityException
     */
    public function create(string $name): ListData
    {
        $request = new AddListRequest($name);

        /** @var ListData */
        return $this->sendAndDto($request);
    }

    /**
     * Create a new contact list using a custom request.
     *
     * Use this to set custom fields on the list.
     *
     * @throws KudosityException
     */
    public function createRequest(AddListRequest $request): ListData
    {
        /** @var ListData */
        return $this->sendAndDto($request);
    }

    /**
     * Get all contact lists (paginated).
     */
    public function all(): V1PagedPaginator
    {
        return $this->connector->paginate(new GetListsRequest);
    }

    /**
     * Get all contact lists using a custom request.
     */
    public function allRequest(GetListsRequest $request): V1PagedPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get a specific contact list.
     *
     * @param  int  $listId  The list ID
     *
     * @throws KudosityException
     */
    public function get(int $listId): ListData
    {
        $request = new GetListRequest($listId);

        /** @var ListData */
        return $this->sendAndDto($request);
    }

    /**
     * Get a list with contacts (paginated).
     *
     * @param  int  $listId  The list ID
     */
    public function getContacts(int $listId): V1PagedPaginator
    {
        $request = new GetListRequest($listId);

        return $this->connector->paginate($request);
    }

    /**
     * Delete a contact list.
     *
     * @param  int  $listId  The list ID
     *
     * @throws KudosityException
     */
    public function delete(int $listId): bool
    {
        $response = $this->connector->send(new RemoveListRequest($listId));
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Add a custom field to a list.
     *
     * @param  int  $listId  The list ID
     * @param  int  $fieldNumber  Field number (1-10)
     * @param  string  $fieldName  Field name/label
     *
     * @throws KudosityException
     */
    public function addField(int $listId, int $fieldNumber, string $fieldName): bool
    {
        $response = $this->connector->send(new AddFieldToListRequest($listId, $fieldNumber, $fieldName));
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    // =========================================================================
    // Contact Management
    // =========================================================================

    /**
     * Add a contact to a list.
     *
     * @param  int  $listId  The list ID
     * @param  string  $mobile  The mobile number
     * @param  string|null  $firstName  Contact first name
     * @param  string|null  $lastName  Contact last name
     *
     * @throws KudosityException
     */
    public function addContact(
        int $listId,
        string $mobile,
        ?string $firstName = null,
        ?string $lastName = null,
    ): ContactData {
        $request = new AddToListRequest($listId, $mobile);

        // Apply default country code
        $countryCode = $this->connector->getDefaultCountryCode();
        if ($countryCode !== null) {
            $request->countryCode($countryCode);
        }

        if ($firstName !== null) {
            $request->firstName($firstName);
        }

        if ($lastName !== null) {
            $request->lastName($lastName);
        }

        /** @var ContactData */
        return $this->sendAndDto($request);
    }

    /**
     * Add a contact using a custom request.
     *
     * Use this to set custom fields on the contact.
     *
     * @throws KudosityException
     */
    public function addContactRequest(AddToListRequest $request): ContactData
    {
        /** @var ContactData */
        return $this->sendAndDto($request);
    }

    /**
     * Get a contact from a list.
     *
     * @param  int  $listId  The list ID
     * @param  string  $mobile  The mobile number
     *
     * @throws KudosityException
     */
    public function getContact(int $listId, string $mobile): ContactData
    {
        $request = new GetContactRequest($listId, $mobile);

        /** @var ContactData */
        return $this->sendAndDto($request);
    }

    /**
     * Update a contact in a list.
     *
     * @param  int  $listId  The list ID
     * @param  string  $mobile  The mobile number
     * @param  string|null  $firstName  New first name (optional)
     * @param  string|null  $lastName  New last name (optional)
     *
     * @throws KudosityException
     */
    public function updateContact(
        int $listId,
        string $mobile,
        ?string $firstName = null,
        ?string $lastName = null,
    ): bool {
        $request = new EditListMemberRequest($listId, $mobile);

        if ($firstName !== null) {
            $request->firstName($firstName);
        }

        if ($lastName !== null) {
            $request->lastName($lastName);
        }

        $response = $this->connector->send($request);
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Update a contact using a custom request.
     *
     * Use this to update custom fields on the contact.
     *
     * @throws KudosityException
     */
    public function updateContactRequest(EditListMemberRequest $request): bool
    {
        $response = $this->connector->send($request);
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Delete a contact from a list.
     *
     * @param  int  $listId  The list ID
     * @param  string  $mobile  The mobile number
     *
     * @throws KudosityException
     */
    public function deleteContact(int $listId, string $mobile): bool
    {
        $response = $this->connector->send(new DeleteFromListRequest($listId, $mobile));
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Opt out a contact from a list.
     *
     * @param  int  $listId  The list ID
     * @param  string  $mobile  The mobile number
     *
     * @throws KudosityException
     */
    public function optoutContact(int $listId, string $mobile): bool
    {
        $response = $this->connector->send(new OptoutListMemberRequest($listId, $mobile));
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    // =========================================================================
    // Bulk Operations
    // =========================================================================

    /**
     * Bulk add contacts from a CSV file URL.
     *
     * @param  string  $fileUrl  URL to the CSV file
     * @param  int|null  $listId  Existing list ID (optional)
     * @param  string|null  $name  New list name (if not using listId)
     *
     * @throws KudosityException
     */
    public function bulkAdd(string $fileUrl, ?int $listId = null, ?string $name = null): BulkAddResultData
    {
        $request = new AddContactsBulkRequest($fileUrl);

        if ($listId !== null) {
            $request->listId($listId);
        }

        if ($name !== null) {
            $request->name($name);
        }

        /** @var BulkAddResultData */
        return $this->sendAndDto($request);
    }

    /**
     * Bulk add contacts using a custom request.
     *
     * @throws KudosityException
     */
    public function bulkAddRequest(AddContactsBulkRequest $request): BulkAddResultData
    {
        /** @var BulkAddResultData */
        return $this->sendAndDto($request);
    }

    /**
     * Check progress of a bulk add operation.
     *
     * @param  int  $listId  The list ID
     *
     * @throws KudosityException
     */
    public function bulkAddProgress(int $listId): BulkProgressData
    {
        $request = new AddContactsBulkProgressRequest($listId);

        /** @var BulkProgressData */
        return $this->sendAndDto($request);
    }
}
