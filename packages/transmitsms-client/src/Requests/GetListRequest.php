<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use ExpertSystems\TransmitSms\Contracts\PaginatesResults;
use ExpertSystems\TransmitSms\Data\ListData;
use Saloon\Http\Response;

/**
 * Get a specific contact list.
 *
 * When iterated via the paginator (e.g. `lists()->getContacts()`), it pages
 * through the list's members. When sent directly it returns the list itself.
 *
 * @see https://developer.transmitsms.com/#get-list
 */
class GetListRequest extends TransmitSmsRequest implements PaginatesResults
{
    protected ?int $page = null;

    protected ?int $max = null;

    public function __construct(
        protected int $listId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-list');
    }

    public function paginationItemsKey(): string
    {
        return 'members';
    }

    /**
     * Set the page number (for contacts pagination).
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
            'list_id' => $this->listId,
        ];

        if ($this->page !== null) {
            $body['page'] = $this->page;
        }

        if ($this->max !== null) {
            $body['max'] = $this->max;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): ListData
    {
        return ListData::fromResponse($response->json());
    }
}
