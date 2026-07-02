<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use DateTimeInterface;
use Saloon\PaginationPlugin\Contracts\Paginatable;

/**
 * Get list of all SMS sent by user (paginated).
 *
 * @see https://developer.transmitsms.com/#get-user-sms-sent
 */
class GetUserSmsSentRequest extends TransmitSmsRequest implements Paginatable
{
    protected ?int $page = null;

    protected ?int $max = null;

    protected ?string $msisdn = null;

    protected ?string $start = null;

    protected ?string $end = null;

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-user-sms-sent');
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
     * Filter by recipient mobile number.
     */
    public function msisdn(string $msisdn): self
    {
        $this->msisdn = $msisdn;

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
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->page !== null) {
            $body['page'] = $this->page;
        }

        if ($this->max !== null) {
            $body['max'] = $this->max;
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

        return $body;
    }
}
