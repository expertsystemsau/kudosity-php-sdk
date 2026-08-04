<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use DateTimeInterface;
use ExpertSystems\Kudosity\Data\MessageReportData;
use Saloon\Http\Response;

/**
 * Get message report for a date range.
 *
 * @see https://developer.transmitsms.com/#get-message-report
 */
class GetMessageReportRequest extends TransmitSmsRequest
{
    protected string $start;

    protected string $end;

    protected ?string $type = null;

    protected ?int $listId = null;

    protected ?string $number = null;

    protected ?int $page = null;

    protected ?int $max = null;

    public function __construct(
        string|DateTimeInterface $start,
        string|DateTimeInterface $end,
    ) {
        $this->start = $start instanceof DateTimeInterface
            ? $start->format('Y-m-d')
            : $start;

        $this->end = $end instanceof DateTimeInterface
            ? $end->format('Y-m-d')
            : $end;
    }

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-message-report');
    }

    /**
     * Set the report type filter.
     *
     * @param  string  $type  'all', 'campaign', 'single'
     */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Filter by list ID.
     */
    public function listId(int $listId): self
    {
        $this->listId = $listId;

        return $this;
    }

    /**
     * Filter by sender number.
     */
    public function number(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    /**
     * Set the page number.
     */
    public function page(int $page): self
    {
        $this->page = $page;

        return $this;
    }

    /**
     * Set the maximum results per page.
     */
    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'start' => $this->start,
            'end' => $this->end,
        ];

        if ($this->type !== null) {
            $body['type'] = $this->type;
        }

        if ($this->listId !== null) {
            $body['list_id'] = $this->listId;
        }

        if ($this->number !== null) {
            $body['number'] = $this->number;
        }

        if ($this->page !== null) {
            $body['page'] = $this->page;
        }

        if ($this->max !== null) {
            $body['max'] = $this->max;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): MessageReportData
    {
        return MessageReportData::fromResponse($response->json());
    }
}
