<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Concerns\FiltersByDateRange;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;

/**
 * List sent RCS messages, filterable and cursor-paged.
 *
 * `GET /v2/rcs/messages` walks a cursor rather than page numbers — `limit`,
 * `cursor` and `direction` are added by {@see V2CursorPaginator}, and the
 * filters below are the only query parameters this class contributes itself.
 * Items come back at `data.messages` with cursor state under
 * `meta.pagination`, mirroring {@see ListWhatsAppRequest}.
 *
 * @see https://developers.kudosity.com/reference/get_v2-rcs-messages
 */
class ListRcsRequest extends KudosityV2Request implements PaginatesV2Cursor
{
    use FiltersByDateRange;

    protected Method $method = Method::GET;

    /**
     * @throws ValidationException If date_range is not a documented value, or is
     *                             custom_date without both dates
     */
    public function __construct(
        protected ?string $dateRange = null,
        protected ?string $startDate = null,
        protected ?string $endDate = null,
    ) {
        $this->validateDateRange($dateRange, $startDate, $endDate);
    }

    public function resolveEndpoint(): string
    {
        return '/v2/rcs/messages';
    }

    public function paginationItemsKey(): string
    {
        return 'data.messages';
    }

    public function paginationDirection(): string
    {
        return 'next';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return $this->dateRangeQuery($this->dateRange, $this->startDate, $this->endDate);
    }
}
