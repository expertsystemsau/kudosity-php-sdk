<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

abstract class KudosityV1Request extends Request implements HasBody
{
    use HasFormBody;

    /**
     * The HTTP method for the request.
     * Kudosity API supports both GET and POST, but POST is preferred for sending data.
     */
    protected Method $method = Method::POST;

    /**
     * Define the endpoint for the request.
     * All endpoints must end with .json for JSON responses.
     */
    abstract public function resolveEndpoint(): string;

    /**
     * Helper method to ensure endpoint has .json suffix.
     */
    protected function formatEndpoint(string $endpoint): string
    {
        if (! str_ends_with($endpoint, '.json')) {
            $endpoint .= '.json';
        }

        return '/'.ltrim($endpoint, '/');
    }
}
