<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use ExpertSystems\TransmitSms\Contracts\PaginatesResults;
use ExpertSystems\TransmitSms\Data\SmsResponseItemData;
use Saloon\Http\Response;

/**
 * Get SMS responses/replies by message ID, keyword, or mobile.
 *
 * This is a paginated endpoint. Use with connector->paginate() for iteration.
 *
 * @see https://developer.transmitsms.com/#get-sms-responses
 */
class GetSmsResponsesRequest extends TransmitSmsRequest implements PaginatesResults
{
    protected ?int $messageId = null;

    protected ?int $keywordId = null;

    protected ?string $keyword = null;

    protected ?string $number = null;

    protected ?string $msisdn = null;

    protected ?string $start = null;

    protected ?string $end = null;

    protected ?bool $includeOriginal = null;

    /**
     * Create a request to get responses by message ID.
     */
    public static function forMessage(int $messageId): self
    {
        $request = new self;
        $request->messageId = $messageId;

        return $request;
    }

    /**
     * Create a request to get responses by keyword ID.
     */
    public static function forKeywordId(int $keywordId): self
    {
        $request = new self;
        $request->keywordId = $keywordId;

        return $request;
    }

    /**
     * Create a request to get responses by keyword name.
     *
     * @param  string  $keyword  The keyword name
     * @param  string  $number  The VMN number (required when using keyword)
     */
    public static function forKeyword(string $keyword, string $number): self
    {
        $request = new self;
        $request->keyword = $keyword;
        $request->number = $number;

        return $request;
    }

    /**
     * Filter by responder mobile number.
     */
    public function fromMobile(string $msisdn): self
    {
        $this->msisdn = $msisdn;

        return $this;
    }

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
     * Include the original message text in responses.
     */
    public function includeOriginal(bool $include = true): self
    {
        $this->includeOriginal = $include;

        return $this;
    }

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('/get-sms-responses');
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

        if ($this->messageId !== null) {
            $body['message_id'] = $this->messageId;
        }

        if ($this->keywordId !== null) {
            $body['keyword_id'] = $this->keywordId;
        }

        if ($this->keyword !== null) {
            $body['keyword'] = $this->keyword;
        }

        if ($this->number !== null) {
            $body['number'] = $this->number;
        }

        if ($this->msisdn !== null) {
            $body['msisdn'] = $this->msisdn;
        }

        if ($this->start !== null) {
            $body['start'] = $this->start;
        }

        if ($this->end !== null) {
            $body['end'] = $this->end;
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
