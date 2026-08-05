<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;

/**
 * Marks a V2 request as using cursor pagination.
 *
 * The WhatsApp and RCS list endpoints take `limit`, `cursor` and `direction`,
 * and report `meta.pagination`. Their items sit at `data.messages`, so the
 * inherited `paginationItemsKey()` returns a dot path.
 *
 * @see V2CursorPaginator
 */
interface PaginatesV2Cursor extends PaginatesResults
{
    /**
     * Which way to walk the cursor.
     *
     * Only `next` is implemented today: {@see V2CursorPaginator} reads
     * `has_next`/`next_cursor` exclusively and has no handling for
     * `has_prev`/`prev_cursor`, so a request answering `prev` here would not
     * actually page backwards. Both cursor endpoints (WhatsApp, RCS) answer
     * `next`, which is what pins this to the one direction that works. `prev`
     * would need `has_prev`/`prev_cursor` support added to
     * {@see V2CursorPaginator} before a request could honestly return it.
     */
    public function paginationDirection(): string;
}
