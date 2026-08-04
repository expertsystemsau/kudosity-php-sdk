<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Exceptions\TransmitSmsException;
use ExpertSystems\Kudosity\Requests\TransmitSmsRequest;
use ExpertSystems\Kudosity\TransmitSmsConnector;

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
        protected TransmitSmsConnector $connector,
    ) {}

    /**
     * Send a request and return the DTO.
     *
     * Uses Saloon's throw() method which leverages the connector's
     * hasRequestFailed() and getRequestException() methods for proper
     * error detection and custom exception handling.
     *
     * @param  TransmitSmsRequest  $request  The request to send
     * @return mixed The DTO created from the response
     *
     * @throws TransmitSmsException If the API returns an error
     *
     * @see https://docs.saloon.dev/the-basics/handling-failures
     */
    protected function sendAndDto(TransmitSmsRequest $request): mixed
    {
        $response = $this->connector->send($request);

        // throw() uses connector's hasRequestFailed() and getRequestException()
        $response->throw();

        return $response->dto();
    }
}
