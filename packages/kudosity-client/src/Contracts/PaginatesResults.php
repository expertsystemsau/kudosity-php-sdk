<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use Saloon\PaginationPlugin\Contracts\Paginatable;

/**
 * Marks a request as paginatable and declares which response key holds its items.
 *
 * The Kudosity API uses a different envelope key per endpoint (e.g. `numbers`,
 * `lists`, `keywords`, `recipients`, `messages`, `members`, `responses`), so the
 * paginator cannot assume a single key. Each paginatable request declares its own
 * key here and {@see V1PagedPaginator} reads it when extracting page items.
 */
interface PaginatesResults extends Paginatable
{
    /**
     * The response key that holds the array of items for this endpoint.
     */
    public function paginationItemsKey(): string;
}
