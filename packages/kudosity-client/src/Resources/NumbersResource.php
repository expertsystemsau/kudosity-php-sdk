<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\FormattedNumberData;
use ExpertSystems\Kudosity\Data\LeaseResultData;
use ExpertSystems\Kudosity\Data\NumberData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use ExpertSystems\Kudosity\Requests\EditNumberOptionsRequest;
use ExpertSystems\Kudosity\Requests\FormatNumberRequest;
use ExpertSystems\Kudosity\Requests\GetNumberRequest;
use ExpertSystems\Kudosity\Requests\GetNumbersRequest;
use ExpertSystems\Kudosity\Requests\LeaseNumberRequest;

/**
 * Numbers resource for managing virtual mobile numbers.
 *
 * @see https://developers.kudosity.com
 */
class NumbersResource extends Resource
{
    /**
     * Lease a virtual mobile number.
     *
     * @param  string  $number  The number to lease
     *
     * @throws KudosityException
     */
    public function lease(string $number): LeaseResultData
    {
        $request = new LeaseNumberRequest($number);

        /** @var LeaseResultData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get all virtual numbers (paginated).
     */
    public function all(): V1PagedPaginator
    {
        return $this->connector->paginate(new GetNumbersRequest);
    }

    /**
     * Get all virtual numbers using a custom request.
     */
    public function allRequest(GetNumbersRequest $request): V1PagedPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get a specific virtual number.
     *
     * @param  string  $number  The number to get
     *
     * @throws KudosityException
     */
    public function get(string $number): NumberData
    {
        $request = new GetNumberRequest($number);

        /** @var NumberData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Edit options for a virtual number.
     *
     * Returns a fluent request builder for setting options.
     *
     * @param  string  $number  The number to edit
     */
    public function edit(string $number): EditNumberOptionsRequest
    {
        return new EditNumberOptionsRequest($number);
    }

    /**
     * Edit options using a custom request.
     *
     * @throws KudosityException
     */
    public function editRequest(EditNumberOptionsRequest $request): bool
    {
        $response = $this->connector->send($request);
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Set forward email for a number.
     *
     * @param  string  $number  The number to edit
     * @param  string  $email  Email to forward messages to
     *
     * @throws KudosityException
     */
    public function setForwardEmail(string $number, string $email): bool
    {
        $request = (new EditNumberOptionsRequest($number))->forwardEmail($email);

        return $this->editRequest($request);
    }

    /**
     * Set forward URL for a number.
     *
     * @param  string  $number  The number to edit
     * @param  string  $url  URL to forward messages to
     *
     * @throws KudosityException
     */
    public function setForwardUrl(string $number, string $url): bool
    {
        $request = (new EditNumberOptionsRequest($number))->forwardUrl($url);

        return $this->editRequest($request);
    }

    /**
     * Associate a list with a number.
     *
     * @param  string  $number  The number to edit
     * @param  int  $listId  The list ID
     *
     * @throws KudosityException
     */
    public function setList(string $number, int $listId): bool
    {
        $request = (new EditNumberOptionsRequest($number))->listId($listId);

        return $this->editRequest($request);
    }

    /**
     * Format a phone number for SMS delivery using the API.
     *
     * Converts local format numbers to international E.164 format.
     * If no country code is provided, uses the connector's default.
     *
     * @param  string  $number  The phone number to format
     * @param  string|null  $countryCode  2-letter ISO country code (e.g., 'AU', 'NZ', 'US')
     *
     * @throws KudosityException
     */
    public function formatNumber(string $number, ?string $countryCode = null): FormattedNumberData
    {
        $countryCode ??= $this->connector->getDefaultCountryCode();

        if ($countryCode === null) {
            throw new KudosityException(
                'Country code is required. Set it on the connector or pass it as a parameter.'
            );
        }

        $request = new FormatNumberRequest($number, $countryCode);

        /** @var FormattedNumberData */
        return $this->sendAndDto($request);
    }
}
