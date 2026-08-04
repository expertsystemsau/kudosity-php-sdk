<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\BalanceData;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Get the account balance.
 *
 * Returns the current account balance and currency.
 *
 * @see https://developer.transmitsms.com/#get-balance
 */
class GetBalanceRequest extends KudosityV1Request
{
    /**
     * The HTTP method for this request.
     * Get balance is a read-only operation, so we use GET.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('/get-balance');
    }

    /**
     * Create a DTO from the response.
     *
     * @see https://docs.saloon.dev/digging-deeper/data-transfer-objects
     */
    public function createDtoFromResponse(Response $response): BalanceData
    {
        return BalanceData::fromResponse($response->json());
    }
}
