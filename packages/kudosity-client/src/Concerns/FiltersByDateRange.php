<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * The `date_range` allow-list and its `custom_date` window coupling, for the V2
 * list endpoints that filter by date.
 *
 * One rule shared by several endpoints rather than a copy per endpoint: the
 * coupling is enforced in both directions (see {@see validateDateRange()}), and
 * a second divergent copy of a bidirectional rule is how one endpoint quietly
 * stops rejecting what the others reject.
 *
 * The using class keeps its own promoted constructor properties and passes them
 * in — the trait declares no state, so it composes with any constructor shape.
 */
trait FiltersByDateRange
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

    /**
     * @throws ValidationException If date_range is not a documented value, or the
     *                             dates and custom_date are not paired
     */
    protected function validateDateRange(?string $dateRange, ?string $startDate, ?string $endDate): void
    {
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

        // And the reverse. The docs couple the two dates to custom_date, so
        // dates without it are meaningless — and the API ignores an unsupported
        // query parameter silently, leaving the caller believing their results
        // are date-filtered when they are not. Silent wrong results are worse
        // than a rejected call, which is the same reasoning that removed the
        // speculative date filters from ListSmsV2Request.
        if ($dateRange !== self::CUSTOM_DATE_RANGE && ($startDate !== null || $endDate !== null)) {
            throw new ValidationException(
                message: sprintf(
                    'start_date and end_date are only meaningful alongside date_range "%s".',
                    self::CUSTOM_DATE_RANGE,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    /**
     * The date filter as query parameters, omitting whatever was not given.
     *
     * @return array<string, mixed>
     */
    protected function dateRangeQuery(?string $dateRange, ?string $startDate, ?string $endDate): array
    {
        $query = [];

        if ($dateRange !== null) {
            $query['date_range'] = $dateRange;
        }

        if ($startDate !== null) {
            $query['start_date'] = $startDate;
        }

        if ($endDate !== null) {
            $query['end_date'] = $endDate;
        }

        return $query;
    }
}
