<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use ExpertSystems\Kudosity\Resources\SendersResource;
use Saloon\Enums\Method;

/**
 * List the account's sender registrations.
 *
 * Confirmed live. Two things about the shape are worth knowing, because neither
 * matches the endpoints already in this SDK:
 *
 * ```json
 * {"data":{"registrations":[]},
 *  "meta":{"pagination":{"limit":25,"page":1,"total_count":0,"type":"page"}}}
 * ```
 *
 * - Items are **`data`-wrapped and nested**, at `data.registrations`.
 * - It is **page-based, but reports its total as `meta.pagination.total_count`**
 *   — an int, in the place the cursor paginator looks, under a different name
 *   from SMS's `total_records`. {@see V2PagedPaginator} reads both, and prefers
 *   the response's own reported `limit`, because this endpoint defaults to 25
 *   where SMS defaults to 100.
 *
 * No `createDtoFromResponse()`, deliberately: the paginator reads items straight
 * off the JSON at `data.registrations` and never calls `.dto()`, so a DTO factory
 * here would be unreachable — the same reasoning that left one off
 * `ListWhatsAppRequest`. {@see SendersResource::registrations()}
 * maps the rows into DTOs itself.
 */
class ListSenderRegistrationsRequest extends KudosityV2Request implements PaginatesV2Pages
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/senders/registrations';
    }

    public function paginationItemsKey(): string
    {
        return 'data.registrations';
    }
}
