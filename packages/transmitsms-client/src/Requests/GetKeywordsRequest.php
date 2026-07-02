<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use Saloon\PaginationPlugin\Contracts\Paginatable;

/**
 * Get all keywords (paginated).
 *
 * @see https://developer.transmitsms.com/#get-keywords
 */
class GetKeywordsRequest extends TransmitSmsRequest implements Paginatable
{
    protected ?int $page = null;

    protected ?int $max = null;

    protected ?string $number = null;

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-keywords');
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
     * Filter by virtual number.
     */
    public function number(string $number): self
    {
        $this->number = $number;

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

        if ($this->number !== null) {
            $body['number'] = $this->number;
        }

        return $body;
    }
}
