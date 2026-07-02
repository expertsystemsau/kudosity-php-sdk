<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use ExpertSystems\TransmitSms\Contracts\PaginatesResults;
use ExpertSystems\TransmitSms\Data\SmsResponseItemData;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Get all SMS responses/replies by time frame.
 *
 * This is a paginated endpoint. Use with connector->paginate() for iteration.
 *
 * @see https://developer.transmitsms.com/#get-user-sms-responses
 */
class GetUserSmsResponsesRequest extends TransmitSmsRequest implements PaginatesResults
{
    protected Method $method = Method::GET;

    protected ?string $start = null;

    protected ?string $end = null;

    protected ?string $keywords = null;

    protected ?bool $includeOriginal = null;

    /**
     * Set the start date for the query.
     *
     * @param  string  $start  ISO8601 format: YYYY-MM-DD HH:MM:SS (UTC)
     */
    public function startDate(string $start): self
    {
        $this->start = $start;

        return $this;
    }

    /**
     * Set the end date for the query.
     *
     * @param  string  $end  ISO8601 format: YYYY-MM-DD HH:MM:SS (UTC)
     */
    public function endDate(string $end): self
    {
        $this->end = $end;

        return $this;
    }

    /**
     * Only include keyword responses.
     */
    public function onlyKeywordResponses(): self
    {
        $this->keywords = 'only';

        return $this;
    }

    /**
     * Omit keyword responses.
     */
    public function omitKeywordResponses(): self
    {
        $this->keywords = 'omit';

        return $this;
    }

    /**
     * Include the original message text in responses.
     */
    public function includeOriginal(bool $include = true): self
    {
        $this->includeOriginal = $include;

        return $this;
    }

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('/get-user-sms-responses');
    }

    public function paginationItemsKey(): string
    {
        return 'responses';
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

        if ($this->keywords !== null) {
            $body['keywords'] = $this->keywords;
        }

        if ($this->includeOriginal !== null) {
            $body['include_original'] = $this->includeOriginal ? 'true' : 'false';
        }

        return $body;
    }

    /**
     * Create a DTO from a single response item.
     *
     * Note: For paginated results, use the paginator's items() method.
     */
    public function createDtoFromResponse(Response $response): SmsResponseItemData
    {
        $data = $response->json();
        $responses = $data['responses'] ?? [];

        return SmsResponseItemData::fromResponse($responses[0] ?? []);
    }
}
