<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use DateTimeInterface;
use ExpertSystems\TransmitSms\Contracts\PaginatesResults;

/**
 * Get list of SMS sent for a message (paginated).
 *
 * @see https://developer.transmitsms.com/#get-sms-sent
 */
class GetSmsSentRequest extends TransmitSmsRequest implements PaginatesResults
{
    protected ?int $page = null;

    protected ?int $max = null;

    protected ?string $optout = null;

    protected ?string $start = null;

    protected ?string $end = null;

    protected ?string $status = null;

    public function __construct(
        protected int $messageId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-sms-sent');
    }

    public function paginationItemsKey(): string
    {
        return 'recipients';
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
     * Filter by opt-out status.
     */
    public function optout(string $optout): self
    {
        $this->optout = $optout;

        return $this;
    }

    /**
     * Set the start date filter.
     */
    public function from(string|DateTimeInterface $start): self
    {
        $this->start = $start instanceof DateTimeInterface
            ? $start->format('Y-m-d')
            : $start;

        return $this;
    }

    /**
     * Set the end date filter.
     */
    public function to(string|DateTimeInterface $end): self
    {
        $this->end = $end instanceof DateTimeInterface
            ? $end->format('Y-m-d')
            : $end;

        return $this;
    }

    /**
     * Filter by delivery status.
     */
    public function status(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'message_id' => $this->messageId,
        ];

        if ($this->page !== null) {
            $body['page'] = $this->page;
        }

        if ($this->max !== null) {
            $body['max'] = $this->max;
        }

        if ($this->optout !== null) {
            $body['optout'] = $this->optout;
        }

        if ($this->start !== null) {
            $body['start'] = $this->start;
        }

        if ($this->end !== null) {
            $body['end'] = $this->end;
        }

        if ($this->status !== null) {
            $body['status'] = $this->status;
        }

        return $body;
    }
}
