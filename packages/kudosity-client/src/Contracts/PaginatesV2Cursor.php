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
     * Which way to walk the cursor: `next` (default) or `prev`.
     */
    public function paginationDirection(): string;
}
