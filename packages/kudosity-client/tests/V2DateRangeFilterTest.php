<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Concerns\FiltersByDateRange;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\V2\ListRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWhatsAppRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/V2DateRangeFilterTest.php.
 *
 * The `date_range` allow-list and the `custom_date` window coupling, asserted
 * once against every request that uses Concerns\FiltersByDateRange.
 *
 * Parameterised over the request classes rather than written per channel: the
 * rule has one implementation now, but each class still has to reach it, so a
 * class that drops the trait or stops calling validateDateRange() fails here.
 * Endpoint-specific query wiring (WhatsApp's campaign_id, each endpoint's path)
 * stays in that channel's own spec file (RequestShapeTest).
 *
 * No counterpart of any kind existed in the client suite before this file.
 */
#[CoversTrait(FiltersByDateRange::class)]
#[CoversClass(ListRcsRequest::class)]
#[CoversClass(ListWhatsAppRequest::class)]
final class V2DateRangeFilterTest extends TestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function dateRangeFilteredRequests(): array
    {
        return [
            'WhatsApp' => [ListWhatsAppRequest::class],
            'RCS' => [ListRcsRequest::class],
        ];
    }

    /**
     * The cartesian product of every filtered request class against every
     * date_range value that needs no window — the fifth documented value,
     * custom_date, is covered separately because it also needs its dates.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function dateRangeValuesNeedingNoWindow(): array
    {
        $cases = [];

        foreach (self::dateRangeFilteredRequests() as $label => [$class]) {
            foreach (['last_week', 'last_thirty', 'last_month', 'all'] as $dateRange) {
                $cases["{$label} {$dateRange}"] = [$class, $dateRange];
            }
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_exposes_the_documented_allow_list_on_the_class_not_only_inside_the_shared_rule(string $class): void
    {
        // The constants are public API — a consumer switching on them must not
        // have to know the rule moved into a trait. Trait constants resolve
        // through the using class, and this is what proves it for each class.
        $this->assertSame(['last_week', 'last_thirty', 'last_month', 'all', 'custom_date'], $class::DATE_RANGES);
        $this->assertSame('custom_date', $class::CUSTOM_DATE_RANGE);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeValuesNeedingNoWindow')]
    public function test_accepts_every_documented_date_range_value_that_needs_no_window(string $class, string $dateRange): void
    {
        // An allow-list assertion, not a deny-list: all five documented values
        // must be accepted — the fifth, custom_date, in the next test because
        // it also needs its dates — and the unlisted value must not be.
        $this->assertInstanceOf($class, new $class(dateRange: $dateRange));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_accepts_custom_date_when_both_dates_are_supplied(string $class): void
    {
        $this->assertInstanceOf(
            $class,
            new $class(dateRange: 'custom_date', startDate: '2026-07-01', endDate: '2026-07-31'),
        );
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_a_date_range_outside_the_documented_set(string $class): void
    {
        // The asserted fragment belongs to the allow-list rule alone — the
        // pairing rule below phrases itself differently — and 'yesterday'
        // cannot trigger the pairing rule anyway.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('date_range must be one of');

        new $class(dateRange: 'yesterday');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_custom_date_with_neither_date_because_the_api_answers_a_generic_400(string $class): void
    {
        // date_range itself is valid here, so the pairing rule is the only one
        // that can fire. The asserted fragment is unique to that rule's
        // message — 'custom_date' alone would also match the allow-list
        // message, which lists every accepted value.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('both required');

        new $class(dateRange: 'custom_date');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_custom_date_with_only_start_date(string $class): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('both required');

        new $class(dateRange: 'custom_date', startDate: '2026-07-01');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_custom_date_with_only_end_date(string $class): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('both required');

        new $class(dateRange: 'custom_date', endDate: '2026-07-31');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_start_date_with_no_date_range_because_the_api_would_ignore_it_silently(string $class): void
    {
        // The reverse direction. An unsupported query parameter is dropped
        // without complaint, so the caller believes their results are
        // date-filtered when they are not — the same silent-wrong hazard that
        // removed the speculative date filters from ListSmsV2Request.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('only meaningful');

        new $class(startDate: '2026-07-01');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_end_date_with_no_date_range(string $class): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('only meaningful');

        new $class(endDate: '2026-07-31');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_both_dates_with_no_date_range(string $class): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('only meaningful');

        new $class(startDate: '2026-07-01', endDate: '2026-07-31');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_rejects_dates_alongside_a_date_range_other_than_custom_date(string $class): void
    {
        // date_range is valid and the dates are paired, so neither of the
        // other two rules can fire — only the coupling one.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('only meaningful');

        new $class(dateRange: 'last_week', startDate: '2026-07-01', endDate: '2026-07-31');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('dateRangeFilteredRequests')]
    public function test_carries_the_field_level_error_codes_the_rules_are_documented_to_raise(string $class): void
    {
        // errorCode, not only the exception type: three separate rules all
        // throw ValidationException, so a test asserting the type alone stays
        // green when the wrong rule fires. FIELD_EMPTY is the half-specified
        // window; FIELD_INVALID is both the allow-list and the reverse
        // coupling.
        $codes = [];

        foreach ([
            'allow-list' => fn () => new $class(dateRange: 'yesterday'),
            'half-window' => fn () => new $class(dateRange: 'custom_date', startDate: '2026-07-01'),
            'reverse' => fn () => new $class(startDate: '2026-07-01'),
        ] as $case => $construct) {
            try {
                $construct();
            } catch (ValidationException $e) {
                $codes[$case] = $e->getErrorCode();
            }
        }

        $this->assertSame([
            'allow-list' => 'FIELD_INVALID',
            'half-window' => 'FIELD_EMPTY',
            'reverse' => 'FIELD_INVALID',
        ], $codes);
    }
}
