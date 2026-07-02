<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use Saloon\PaginationPlugin\Contracts\Paginatable;

/**
 * Get all virtual numbers (paginated).
 *
 * @see https://developer.transmitsms.com/#get-numbers
 */
class GetNumbersRequest extends TransmitSmsRequest implements Paginatable
{
    protected ?int $page = null;

    protected ?int $max = null;

    protected ?string $filter = null;

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-numbers');
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
     * Filter by keyword (number pattern).
     */
    public function filter(string $filter): self
    {
        $this->filter = $filter;

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

        if ($this->filter !== null) {
            $body['filter'] = $this->filter;
        }

        return $body;
    }
}
