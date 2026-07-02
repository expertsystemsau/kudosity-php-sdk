<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Pagination;

use ExpertSystems\TransmitSms\Contracts\PaginatesResults;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\PagedPaginator;

/**
 * Custom paginator for TransmitSMS API responses.
 *
 * The TransmitSMS API uses page-based pagination with:
 * - page: Current page number (1-indexed)
 * - max: Items per page
 * - Response contains: page.count (total pages), page.number (current page), total, <items>[]
 *
 * **Important: Per-endpoint item keys**
 * The envelope key holding the items differs per endpoint (`numbers`, `lists`,
 * `keywords`, `recipients`, `messages`, `members`, `responses`). The key is
 * declared by each request via {@see PaginatesResults} and resolved here, so
 * iteration returns the right items instead of silently yielding nothing.
 *
 * **Important: Index Conversion**
 * Saloon's PagedPaginator uses 0-indexed pages internally (starting at 0),
 * but the TransmitSMS API uses 1-indexed pages (starting at 1).
 * This class handles the conversion automatically in applyPagination().
 *
 * @see https://docs.saloon.dev/installable-plugins/pagination/paged-pagination
 */
class TransmitSmsPaginator extends PagedPaginator
{
    /**
     * Fallback key used when a request does not declare its own items key.
     */
    protected string $itemsKey = 'responses';

    /**
     * Check if this is the last page.
     */
    protected function isLastPage(Response $response): bool
    {
        $data = $response->json();

        // If no items in response, we're done
        if (empty($data[$this->resolveItemsKey($response->getRequest())])) {
            return true;
        }

        // TransmitSMS API returns:
        // - page.number: current page number (1-indexed)
        // - page.count: total number of pages
        $pageNumber = $data['page']['number'] ?? 1;
        $totalPages = $data['page']['count'] ?? 1;

        return $pageNumber >= $totalPages;
    }

    /**
     * Get the items from the page.
     *
     * @return array<int, mixed>
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json($this->resolveItemsKey($request)) ?? [];
    }

    /**
     * Resolve the response key holding the items for the given request.
     *
     * Prefers the key declared by the request (via {@see PaginatesResults}) and
     * falls back to a key set with {@see setItemsKey()} or the default.
     */
    protected function resolveItemsKey(Request $request): string
    {
        if ($request instanceof PaginatesResults) {
            return $request->paginationItemsKey();
        }

        return $this->itemsKey;
    }

    /**
     * Apply pagination parameters to the request.
     *
     * Note: PagedPaginator uses 0-indexed pages internally, but TransmitSMS API
     * uses 1-indexed pages. We add 1 to convert between the two systems.
     */
    protected function applyPagination(Request $request): Request
    {
        // API uses 1-indexed pages, PagedPaginator internally uses 0-indexed
        $request->query()->add('page', $this->currentPage + 1);

        if ($this->perPageLimit !== null) {
            $request->query()->add('max', $this->perPageLimit);
        }

        return $request;
    }

    /**
     * Set the items key for responses that use different keys.
     */
    public function setItemsKey(string $key): self
    {
        $this->itemsKey = $key;

        return $this;
    }
}
