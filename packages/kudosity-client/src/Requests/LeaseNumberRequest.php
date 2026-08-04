<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\LeaseResultData;
use Saloon\Http\Response;

/**
 * Lease a virtual mobile number.
 *
 * @see https://developer.transmitsms.com/#lease-number
 */
class LeaseNumberRequest extends KudosityV1Request
{
    public function __construct(
        protected string $number,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('lease-number');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'number' => $this->number,
        ];
    }

    public function createDtoFromResponse(Response $response): LeaseResultData
    {
        return LeaseResultData::fromResponse($response->json());
    }
}
