<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\NumberData;
use Saloon\Http\Response;

/**
 * Get a specific virtual number.
 *
 * @see https://developer.transmitsms.com/#get-number
 */
class GetNumberRequest extends KudosityV1Request
{
    public function __construct(
        protected string $number,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-number');
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

    public function createDtoFromResponse(Response $response): NumberData
    {
        return NumberData::fromResponse($response->json());
    }
}
