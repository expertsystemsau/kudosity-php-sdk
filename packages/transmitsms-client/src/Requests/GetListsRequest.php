<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use Saloon\PaginationPlugin\Contracts\Paginatable;

/**
 * Get all contact lists (paginated).
 *
 * @see https://developer.transmitsms.com/#get-lists
 */
class GetListsRequest extends TransmitSmsRequest implements Paginatable
{
    protected ?int $page = null;

    protected ?int $max = null;

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-lists');
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
        $body = [];

        if ($this->page !== null) {
            $body['page'] = $this->page;
        }

        if ($this->max !== null) {
            $body['max'] = $this->max;
        }

        return $body;
    }
}
