<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Requests\KudosityV1Request;

/**
 * Base resource class for grouping related API requests.
 *
 * Resources provide a logical grouping of related API endpoints,
 * similar to controllers in MVC architecture.
 *
 * @see https://docs.saloon.dev/digging-deeper/building-sdks
 */
abstract class Resource
{
    public function __construct(
        protected KudosityV1Connector $connector,
    ) {}

    /**
     * Send a request and return the DTO.
     *
     * Uses Saloon's throw() method which leverages the connector's
     * hasRequestFailed() and getRequestException() methods for proper
     * error detection and custom exception handling.
     *
     * @param  KudosityV1Request  $request  The request to send
     * @return mixed The DTO created from the response
     *
     * @throws KudosityException If the API returns an error
     *
     * @see https://docs.saloon.dev/the-basics/handling-failures
     */
    protected function sendAndDto(KudosityV1Request $request): mixed
    {
        $response = $this->connector->send($request);

        // throw() uses connector's hasRequestFailed() and getRequestException()
        $response->throw();

        return $response->dto();
    }
}
