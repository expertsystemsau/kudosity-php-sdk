<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;

/**
 * List sent WhatsApp messages, filterable and cursor-paged.
 *
 * `GET /v2/whatsapp/messages` walks a cursor rather than page numbers — `limit`,
 * `cursor` and `direction` are added by {@see V2CursorPaginator}, and the
 * filters below are the only query parameters this class contributes itself.
 * Items come back at `data.messages` with cursor state under
 * `meta.pagination`.
 *
 * @see https://developers.kudosity.com/reference/get_v2-whatsapp-messages
 */
class ListWhatsAppRequest extends KudosityV2Request implements PaginatesV2Cursor
{
    /**
     * The documented `date_range` values.
     *
     * @var array<int, string>
     */
    public const DATE_RANGES = ['last_week', 'last_thirty', 'last_month', 'all', 'custom_date'];

    /**
     * The one `date_range` value that requires an explicit window.
     */
    public const CUSTOM_DATE_RANGE = 'custom_date';

    protected Method $method = Method::GET;

    /**
     * @throws ValidationException If date_range is not a documented value, or is
     *                             custom_date without both dates
     */
    public function __construct(
        protected ?string $dateRange = null,
        protected ?string $startDate = null,
        protected ?string $endDate = null,
        protected ?string $campaignId = null,
    ) {
        if ($dateRange !== null && ! in_array($dateRange, self::DATE_RANGES, true)) {
            throw new ValidationException(
                message: sprintf(
                    'date_range must be one of %s; "%s" given.',
                    implode(', ', self::DATE_RANGES),
                    $dateRange,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        // Checked client-side because the API's answer to a half-specified
        // window is a generic 400 that does not say which field is missing.
        if ($dateRange === self::CUSTOM_DATE_RANGE && ($startDate === null || $endDate === null)) {
            throw new ValidationException(
                message: 'start_date and end_date are both required when date_range is custom_date.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/whatsapp/messages';
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
        $query = [];

        if ($this->dateRange !== null) {
            $query['date_range'] = $this->dateRange;
        }

        if ($this->startDate !== null) {
            $query['start_date'] = $this->startDate;
        }

        if ($this->endDate !== null) {
            $query['end_date'] = $this->endDate;
        }

        if ($this->campaignId !== null) {
            $query['campaign_id'] = $this->campaignId;
        }

        return $query;
    }
}
