<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Pagination;

use ExpertSystems\Kudosity\Contracts\PaginatesResults;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\PagedPaginator;

/**
 * Page-number pagination for the V2 API.
 *
 * `GET /v2/sms` takes `page` (1-indexed) and `limit`, and returns its items
 * under `smses` alongside `total_records`.
 *
 * **`total_records` is a string** — `"3"`, not `3` — so it is cast before any
 * arithmetic. Adding to it unconverted quietly concatenates.
 */
class V2PagedPaginator extends PagedPaginator
{
    /**
     * The API's own default when `limit` is not sent.
     */
    public const DEFAULT_LIMIT = 100;

    protected function applyPagination(Request $request): Request
    {
        // Saloon counts pages from 0 internally; the API counts from 1.
        $request->query()->add('page', $this->currentPage + 1);

        if ($this->perPageLimit !== null) {
            $request->query()->add('limit', $this->perPageLimit);
        }

        return $request;
    }

    /**
     * @return array<int, mixed>
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json($this->itemsKey($request)) ?? [];
    }

    protected function isLastPage(Response $response): bool
    {
        $items = $response->json($this->itemsKey($response->getRequest())) ?? [];

        if ($items === []) {
            return true;
        }

        $limit = $this->perPageLimit ?? self::DEFAULT_LIMIT;
        $total = $response->json('total_records');

        if ($total !== null) {
            $pages = (int) ceil(((int) $total) / $limit);

            // By the time isLastPage() runs, next() has already incremented
            // currentPage — so currentPage (0-indexed, the page about to be
            // requested) equals the 1-indexed page number of the response
            // we're looking at right now. No further "+1" belongs here.
            return $this->currentPage >= max(1, $pages);
        }

        // No total to work from: a page shorter than the limit is the last one.
        return count($items) < $limit;
    }

    protected function itemsKey(Request $request): string
    {
        return $request instanceof PaginatesResults ? $request->paginationItemsKey() : 'smses';
    }
}
