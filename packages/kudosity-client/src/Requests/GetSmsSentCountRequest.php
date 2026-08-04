<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use DateTimeInterface;
use ExpertSystems\Kudosity\Data\SmsSentCountData;
use Saloon\Http\Response;

/**
 * Get a count of SMS sent for the account within a date range.
 *
 * @see https://developer.transmitsms.com/#get-sms-sent-count
 */
class GetSmsSentCountRequest extends TransmitSmsRequest
{
    protected ?string $start = null;

    protected ?string $end = null;

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-sms-sent-count');
    }

    /**
     * Set the start date for the count.
     */
    public function from(string|DateTimeInterface $start): self
    {
        $this->start = $start instanceof DateTimeInterface
            ? $start->format('Y-m-d')
            : $start;

        return $this;
    }

    /**
     * Set the end date for the count.
     */
    public function to(string|DateTimeInterface $end): self
    {
        $this->end = $end instanceof DateTimeInterface
            ? $end->format('Y-m-d')
            : $end;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->start !== null) {
            $body['start'] = $this->start;
        }

        if ($this->end !== null) {
            $body['end'] = $this->end;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): SmsSentCountData
    {
        return SmsSentCountData::fromResponse($response->json());
    }
}
