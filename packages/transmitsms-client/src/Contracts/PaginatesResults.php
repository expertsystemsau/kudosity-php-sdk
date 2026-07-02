<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Contracts;

use ExpertSystems\TransmitSms\Pagination\TransmitSmsPaginator;
use Saloon\PaginationPlugin\Contracts\Paginatable;

/**
 * Marks a request as paginatable and declares which response key holds its items.
 *
 * The TransmitSMS API uses a different envelope key per endpoint (e.g. `numbers`,
 * `lists`, `keywords`, `recipients`, `messages`, `members`, `responses`), so the
 * paginator cannot assume a single key. Each paginatable request declares its own
 * key here and {@see TransmitSmsPaginator} reads it when extracting page items.
 */
interface PaginatesResults extends Paginatable
{
    /**
     * The response key that holds the array of items for this endpoint.
     */
    public function paginationItemsKey(): string;
}
