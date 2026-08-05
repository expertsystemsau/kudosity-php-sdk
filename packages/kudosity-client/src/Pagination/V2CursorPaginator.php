<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Pagination;

use ExpertSystems\Kudosity\Contracts\PaginatesResults;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\CursorPaginator;

/**
 * Cursor pagination for the V2 WhatsApp and RCS list endpoints.
 *
 * Takes `limit`, `cursor` and `direction`; reports `meta.pagination` with
 * `has_next` and `next_cursor`. Items sit at `data.messages`, so the request's
 * items key is a dot path.
 *
 * Saloon's own CursorPaginator sends `per_page`, which this API does not
 * understand, so applyPagination is replaced rather than extended.
 */
class V2CursorPaginator extends CursorPaginator
{
    /**
     * The API's own default when `limit` is not sent.
     */
    public const DEFAULT_LIMIT = 25;

    protected function applyPagination(Request $request): Request
    {
        // No cursor on the first request — there is nothing to continue from.
        if ($this->currentResponse instanceof Response) {
            $cursor = $this->nextCursorOrNull($this->currentResponse);

            if ($cursor !== null) {
                $request->query()->add('cursor', $cursor);
            }
        }

        if ($this->perPageLimit !== null) {
            $request->query()->add('limit', $this->perPageLimit);
        }

        if ($request instanceof PaginatesV2Cursor) {
            $request->query()->add('direction', $request->paginationDirection());
        }

        return $request;
    }

    /**
     * Satisfies the abstract base method but is never called: applyPagination()
     * is fully overridden above and reads the cursor via nextCursorOrNull()
     * directly instead of going through this.
     */
    protected function getNextCursor(Response $response): int|string
    {
        return $this->nextCursorOrNull($response) ?? '';
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
        if (($response->json($this->itemsKey($response->getRequest())) ?? []) === []) {
            return true;
        }

        if ($response->json('meta.pagination.has_next') !== true) {
            return true;
        }

        // has_next says there is more, but without a cursor we cannot ask for
        // it — stop rather than re-requesting page one forever.
        return $this->nextCursorOrNull($response) === null;
    }

    protected function nextCursorOrNull(Response $response): ?string
    {
        $cursor = $response->json('meta.pagination.next_cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    protected function itemsKey(Request $request): string
    {
        return $request instanceof PaginatesResults ? $request->paginationItemsKey() : 'data.messages';
    }
}
