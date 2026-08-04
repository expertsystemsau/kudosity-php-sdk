<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\TransmitSmsException;

describe('RateLimitException', function () {
    describe('rate limit metadata', function () {
        it('stores rate limit metadata from constructor', function () {
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

            expect($exception->getRateLimitRemaining())->toBe(0);
            expect($exception->getRateLimitLimit())->toBe(15);
            expect($exception->getRateLimitReset())->toBe(1700000000);
            expect($exception->getRetryAfter())->toBe(5);
            expect($exception->hasRateLimitMetadata())->toBeTrue();
        });

        it('allows null metadata values', function () {
            $exception = new RateLimitException('Rate limit exceeded');

            expect($exception->getRateLimitRemaining())->toBeNull();
            expect($exception->getRateLimitLimit())->toBeNull();
            expect($exception->getRateLimitReset())->toBeNull();
            expect($exception->getRetryAfter())->toBeNull();
            expect($exception->hasRateLimitMetadata())->toBeFalse();
        });

        it('calculates retry-after from reset timestamp when no explicit retry-after', function () {
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
            expect($retryAfter)->toBeGreaterThanOrEqual(58);
            expect($retryAfter)->toBeLessThanOrEqual(62);
        });

        it('returns explicit retry-after over calculated value', function () {
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

            expect($exception->getRetryAfter())->toBe(10);
        });

        it('provides recommended wait seconds with default fallback', function () {
            $exception = new RateLimitException('Rate limit exceeded');

            expect($exception->getRecommendedWaitSeconds())->toBe(1);
        });

        it('provides recommended wait seconds from retry-after', function () {
            $exception = new RateLimitException(
                message: 'Rate limit exceeded',
                code: 429,
                previous: null,
                errorCode: 'OVER_LIMIT',
                response: null,
                retryAfter: 5
            );

            expect($exception->getRecommendedWaitSeconds())->toBe(5);
        });

        it('converts reset timestamp to DateTimeImmutable', function () {
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
            expect($resetTime)->toBeInstanceOf(DateTimeImmutable::class);
            expect($resetTime->getTimestamp())->toBe($timestamp);
        });

        it('returns null for reset time when no reset timestamp', function () {
            $exception = new RateLimitException('Rate limit exceeded');

            expect($exception->getResetTime())->toBeNull();
        });

        it('extends TransmitSmsException', function () {
            $exception = new RateLimitException('Rate limit exceeded');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
        });
    });

    describe('hasRateLimitMetadata', function () {
        it('returns true when only remaining is set', function () {
            $exception = new RateLimitException(
                message: 'Rate limit exceeded',
                code: 429,
                previous: null,
                errorCode: null,
                response: null,
                rateLimitRemaining: 0
            );

            expect($exception->hasRateLimitMetadata())->toBeTrue();
        });

        it('returns true when only limit is set', function () {
            $exception = new RateLimitException(
                message: 'Rate limit exceeded',
                code: 429,
                previous: null,
                errorCode: null,
                response: null,
                rateLimitRemaining: null,
                rateLimitLimit: 15
            );

            expect($exception->hasRateLimitMetadata())->toBeTrue();
        });

        it('returns true when only reset is set', function () {
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

            expect($exception->hasRateLimitMetadata())->toBeTrue();
        });

        it('returns true when only retryAfter is set', function () {
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

            expect($exception->hasRateLimitMetadata())->toBeTrue();
        });
    });
});
