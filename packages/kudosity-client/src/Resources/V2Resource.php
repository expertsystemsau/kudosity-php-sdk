<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\PaginationPlugin\Paginator;

/**
 * Base for the V2 channel resources.
 *
 * Distinct from {@see Resource}, the V1 base, in three ways: it holds the V2
 * connector, it exposes pagination (V2 has two schemes and V1's resources
 * reach the paginator through the connector directly), and its failures come
 * back as RFC 9457 problem details rather than V1 error codes.
 */
abstract class V2Resource
{
    public function __construct(
        protected KudosityV2Connector $connector,
    ) {}

    /**
     * Send a request and return its DTO, throwing a typed exception on failure.
     *
     * `throw()` routes through the connector's `getRequestException()`, which
     * maps the response onto `ValidationException`, `AuthenticationException`,
     * `NotFoundException`, `RateLimitException` or `ServerException`.
     *
     * @throws KudosityException
     */
    protected function sendAndDto(KudosityV2Request $request): mixed
    {
        $response = $this->connector->send($request);

        $response->throw();

        return $response->dto();
    }

    /**
     * Build the paginator the request declares.
     *
     * @throws KudosityException If the request declares no pagination scheme
     */
    protected function paginate(KudosityV2Request $request): Paginator
    {
        return $this->connector->paginate($request);
    }
}
