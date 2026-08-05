<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use DateTimeImmutable;
use Throwable;

/**
 * Permissive parsing for the timestamps V2 sends.
 *
 * The API sends `2022-03-28T06:12:52.450674000Z` — **nine** fractional digits,
 * which `DateTimeImmutable::createFromFormat(RFC3339_EXTENDED, ...)` cannot
 * parse, because that format expects exactly six. `new DateTimeImmutable()`
 * accepts it, because PHP's own parser truncates fractional seconds.
 *
 * Null rather than an exception on garbage: these are read paths over data we
 * do not control, and a malformed date on one field should not cost the caller
 * the whole response. Every V2 DTO that carries a timestamp uses this — it was
 * copied into four of them before it was extracted, and Phase 4 added more.
 */
trait ParsesV2Timestamps
{
    protected static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
