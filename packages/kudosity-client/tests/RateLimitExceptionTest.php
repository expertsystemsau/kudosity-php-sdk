<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/RateLimitExceptionTest.php.
 *
 * The parent constructor call inside RateLimitException's own constructor
 * also runs KudosityException's constructor body, but that class has its own
 * direct test file (ExceptionTest.php), so it is not repeated in the covers
 * list here.
 */
#[CoversClass(RateLimitException::class)]
final class RateLimitExceptionTest extends TestCase
{
    // -----------------------------------------------------------------
    // rate limit metadata
    // -----------------------------------------------------------------

    public function test_stores_rate_limit_metadata_from_constructor(): void
    {
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: 'OVER_LIMIT',
            response: null,
            rateLimitRemaining: 0,
            rateLimitLimit: 15,
            rateLimitReset: 1700000000,
            retryAfter: 5
        );

        $this->assertSame(0, $exception->getRateLimitRemaining());
        $this->assertSame(15, $exception->getRateLimitLimit());
        $this->assertSame(1700000000, $exception->getRateLimitReset());
        $this->assertSame(5, $exception->getRetryAfter());
        $this->assertTrue($exception->hasRateLimitMetadata());
    }

    public function test_allows_null_metadata_values(): void
    {
        $exception = new RateLimitException('Rate limit exceeded');

        $this->assertNull($exception->getRateLimitRemaining());
        $this->assertNull($exception->getRateLimitLimit());
        $this->assertNull($exception->getRateLimitReset());
        $this->assertNull($exception->getRetryAfter());
        $this->assertFalse($exception->hasRateLimitMetadata());
    }

    public function test_calculates_retry_after_from_reset_timestamp_when_no_explicit_retry_after(): void
    {
        // Use a known future timestamp relative to current time
        $now = time();
        $futureTimestamp = $now + 60; // 60 seconds from now
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: 'OVER_LIMIT',
            response: null,
            rateLimitReset: $futureTimestamp
        );

        $retryAfter = $exception->getRetryAfter();
        // Allow for slight timing variations (test might run a second or two)
        $this->assertGreaterThanOrEqual(58, $retryAfter);
        $this->assertLessThanOrEqual(62, $retryAfter);
    }

    public function test_returns_explicit_retry_after_over_calculated_value(): void
    {
        $futureTimestamp = time() + 60;
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: 'OVER_LIMIT',
            response: null,
            rateLimitReset: $futureTimestamp,
            retryAfter: 10
        );

        $this->assertSame(10, $exception->getRetryAfter());
    }

    public function test_provides_recommended_wait_seconds_with_default_fallback(): void
    {
        $exception = new RateLimitException('Rate limit exceeded');

        $this->assertSame(1, $exception->getRecommendedWaitSeconds());
    }

    public function test_provides_recommended_wait_seconds_from_retry_after(): void
    {
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: 'OVER_LIMIT',
            response: null,
            retryAfter: 5
        );

        $this->assertSame(5, $exception->getRecommendedWaitSeconds());
    }

    public function test_converts_reset_timestamp_to_date_time_immutable(): void
    {
        $timestamp = 1700000000;
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: 'OVER_LIMIT',
            response: null,
            rateLimitReset: $timestamp
        );

        $resetTime = $exception->getResetTime();
        $this->assertInstanceOf(DateTimeImmutable::class, $resetTime);
        $this->assertSame($timestamp, $resetTime->getTimestamp());
    }

    public function test_returns_null_for_reset_time_when_no_reset_timestamp(): void
    {
        $exception = new RateLimitException('Rate limit exceeded');

        $this->assertNull($exception->getResetTime());
    }

    public function test_extends_kudosity_exception(): void
    {
        $exception = new RateLimitException('Rate limit exceeded');

        $this->assertInstanceOf(KudosityException::class, $exception);
    }

    // -----------------------------------------------------------------
    // hasRateLimitMetadata
    // -----------------------------------------------------------------

    public function test_has_rate_limit_metadata_returns_true_when_only_remaining_is_set(): void
    {
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: null,
            response: null,
            rateLimitRemaining: 0
        );

        $this->assertTrue($exception->hasRateLimitMetadata());
    }

    public function test_has_rate_limit_metadata_returns_true_when_only_limit_is_set(): void
    {
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: null,
            response: null,
            rateLimitRemaining: null,
            rateLimitLimit: 15
        );

        $this->assertTrue($exception->hasRateLimitMetadata());
    }

    public function test_has_rate_limit_metadata_returns_true_when_only_reset_is_set(): void
    {
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: null,
            response: null,
            rateLimitRemaining: null,
            rateLimitLimit: null,
            rateLimitReset: 1700000000
        );

        $this->assertTrue($exception->hasRateLimitMetadata());
    }

    public function test_has_rate_limit_metadata_returns_true_when_only_retry_after_is_set(): void
    {
        $exception = new RateLimitException(
            message: 'Rate limit exceeded',
            code: 429,
            previous: null,
            errorCode: null,
            response: null,
            rateLimitRemaining: null,
            rateLimitLimit: null,
            rateLimitReset: null,
            retryAfter: 5
        );

        $this->assertTrue($exception->hasRateLimitMetadata());
    }
}
