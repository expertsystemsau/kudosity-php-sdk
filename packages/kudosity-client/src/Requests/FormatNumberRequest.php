<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\FormattedNumberData;
use Saloon\Http\Response;

/**
 * Format a phone number for SMS delivery.
 *
 * Converts local format numbers to international E.164 format.
 *
 * @see https://developer.transmitsms.com/#format-number
 */
class FormatNumberRequest extends KudosityV1Request
{
    /**
     * Create a new FormatNumberRequest.
     *
     * @param  string  $msisdn  The phone number to format
     * @param  string  $countryCode  2-letter ISO 3166 country code (e.g., 'AU', 'NZ', 'US')
     */
    public function __construct(
        protected string $msisdn,
        protected string $countryCode,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('/format-number');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'msisdn' => $this->msisdn,
            'countrycode' => $this->countryCode,
        ];
    }

    public function createDtoFromResponse(Response $response): FormattedNumberData
    {
        return FormattedNumberData::fromResponse($response->json());
    }
}
