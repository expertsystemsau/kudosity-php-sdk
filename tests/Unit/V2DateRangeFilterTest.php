<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\V2\ListRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWhatsAppRequest;

/**
 * The `date_range` allow-list and the `custom_date` window coupling, asserted
 * once against every request that uses Concerns\FiltersByDateRange.
 *
 * Parameterised over the request classes rather than written per channel: the
 * rule has one implementation now, but each class still has to reach it, so a
 * class that drops the trait or stops calling validateDateRange() fails here.
 * Endpoint-specific query wiring (WhatsApp's campaign_id, each endpoint's path)
 * stays in that channel's own spec file.
 *
 * @return array<string, array<int, class-string>>
 */
function dateRangeFilteredRequests(): array
{
    return [
        'WhatsApp' => [ListWhatsAppRequest::class],
        'RCS' => [ListRcsRequest::class],
    ];
}

it('exposes the documented allow-list on the class, not only inside the shared rule', function (string $class) {
    // The constants are public API — a consumer switching on them must not have
    // to know the rule moved into a trait. Trait constants resolve through the
    // using class, and this is what proves it for each class.
    expect($class::DATE_RANGES)->toBe(['last_week', 'last_thirty', 'last_month', 'all', 'custom_date'])
        ->and($class::CUSTOM_DATE_RANGE)->toBe('custom_date');
})->with(dateRangeFilteredRequests());

it('accepts every documented date_range value that needs no window', function (string $class, string $dateRange) {
    // An allow-list assertion, not a deny-list: all five documented values must
    // be accepted — the fifth, custom_date, immediately below because it also
    // needs its dates — and the unlisted value must not be.
    expect(new $class(dateRange: $dateRange))->toBeInstanceOf($class);
})->with(dateRangeFilteredRequests())->with(['last_week', 'last_thirty', 'last_month', 'all']);

it('accepts custom_date when both dates are supplied', function (string $class) {
    expect(new $class(dateRange: 'custom_date', startDate: '2026-07-01', endDate: '2026-07-31'))
        ->toBeInstanceOf($class);
})->with(dateRangeFilteredRequests());

it('rejects a date_range outside the documented set', function (string $class) {
    // The asserted fragment belongs to the allow-list rule alone — the pairing
    // rule below phrases itself differently — and 'yesterday' cannot trigger
    // the pairing rule anyway.
    new $class(dateRange: 'yesterday');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'date_range must be one of');

it('rejects custom_date with neither date, because the API answers a generic 400', function (string $class) {
    // date_range itself is valid here, so the pairing rule is the only one that
    // can fire. The asserted fragment is unique to that rule's message —
    // 'custom_date' alone would also match the allow-list message, which lists
    // every accepted value.
    new $class(dateRange: 'custom_date');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'both required');

it('rejects custom_date with only start_date', function (string $class) {
    new $class(dateRange: 'custom_date', startDate: '2026-07-01');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'both required');

it('rejects custom_date with only end_date', function (string $class) {
    new $class(dateRange: 'custom_date', endDate: '2026-07-31');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'both required');

it('rejects start_date with no date_range, because the API would ignore it silently', function (string $class) {
    // The reverse direction. An unsupported query parameter is dropped without
    // complaint, so the caller believes their results are date-filtered when
    // they are not — the same silent-wrong hazard that removed the speculative
    // date filters from ListSmsV2Request.
    new $class(startDate: '2026-07-01');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'only meaningful');

it('rejects end_date with no date_range', function (string $class) {
    new $class(endDate: '2026-07-31');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'only meaningful');

it('rejects both dates with no date_range', function (string $class) {
    new $class(startDate: '2026-07-01', endDate: '2026-07-31');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'only meaningful');

it('rejects dates alongside a date_range other than custom_date', function (string $class) {
    // date_range is valid and the dates are paired, so neither of the other two
    // rules can fire — only the coupling one.
    new $class(dateRange: 'last_week', startDate: '2026-07-01', endDate: '2026-07-31');
})->with(dateRangeFilteredRequests())->throws(ValidationException::class, 'only meaningful');

it('carries the field-level error codes the rules are documented to raise', function (string $class) {
    // errorCode, not only the exception type: three separate rules all throw
    // ValidationException, so a test asserting the type alone stays green when
    // the wrong rule fires. FIELD_EMPTY is the half-specified window;
    // FIELD_INVALID is both the allow-list and the reverse coupling.
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

    expect($codes)->toBe([
        'allow-list' => 'FIELD_INVALID',
        'half-window' => 'FIELD_EMPTY',
        'reverse' => 'FIELD_INVALID',
    ]);
})->with(dateRangeFilteredRequests());
