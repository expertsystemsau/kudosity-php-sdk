<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

use DateTimeImmutable;
use Saloon\Http\Response;
use Throwable;

/**
 * Thrown when API rate limit is exceeded.
 *
 * Error code: OVER_LIMIT
 * HTTP status: 429
 *
 * Default rate limit is 15 calls per second.
 *
 * This exception exposes rate limit metadata from response headers
 * to enable smarter retry strategies.
 */
class RateLimitException extends KudosityException
{
    /**
     * Number of requests remaining in the current rate limit window.
     */
    protected ?int $rateLimitRemaining = null;

    /**
     * Total number of requests allowed per rate limit window.
     */
    protected ?int $rateLimitLimit = null;

    /**
     * Unix timestamp when the rate limit window resets.
     */
    protected ?int $rateLimitReset = null;

    /**
     * Number of seconds to wait before retrying (from Retry-After header).
     */
    protected ?int $retryAfter = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?string $errorCode = null,
        ?Response $response = null,
        ?int $rateLimitRemaining = null,
        ?int $rateLimitLimit = null,
        ?int $rateLimitReset = null,
        ?int $retryAfter = null
    ) {
        parent::__construct($message, $code, $previous, $errorCode, $response);
        $this->rateLimitRemaining = $rateLimitRemaining;
        $this->rateLimitLimit = $rateLimitLimit;
        $this->rateLimitReset = $rateLimitReset;
        $this->retryAfter = $retryAfter;
    }

    /**
     * Create a RateLimitException from a Saloon response with rate limit metadata.
     *
     * Extracts rate limit metadata from common rate limit headers:
     * - X-RateLimit-Remaining: Requests remaining in current window
     * - X-RateLimit-Limit: Total requests allowed per window
     * - X-RateLimit-Reset: Unix timestamp when window resets
     * - Retry-After: Seconds to wait before retrying
     *
     * Note: This method has a different signature than the parent's fromResponse()
     * because it requires additional context (message, errorCode) that the parent
     * extracts from the response before delegating here.
     */
    public static function fromResponseWithMetadata(Response $response, string $message, ?string $errorCode = null): self
    {
        $headers = $response->headers()->all();

        // Extract rate limit headers (case-insensitive)
        $rateLimitRemaining = self::extractIntHeader($headers, 'X-RateLimit-Remaining');
        $rateLimitLimit = self::extractIntHeader($headers, 'X-RateLimit-Limit');
        $rateLimitReset = self::extractIntHeader($headers, 'X-RateLimit-Reset');
        $retryAfter = self::extractRetryAfter($headers);

        return new self(
            message: $message,
            code: $response->status(),
            errorCode: $errorCode,
            response: $response,
            rateLimitRemaining: $rateLimitRemaining,
            rateLimitLimit: $rateLimitLimit,
            rateLimitReset: $rateLimitReset,
            retryAfter: $retryAfter
        );
    }

    /**
     * Extract an integer value from headers (case-insensitive).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    protected static function extractIntHeader(array $headers, string $name): ?int
    {
        // Headers are case-insensitive, try common variations
        $variations = [
            $name,
            strtolower($name),
            strtoupper($name),
        ];

        foreach ($variations as $headerName) {
            if (isset($headers[$headerName][0]) && is_numeric($headers[$headerName][0])) {
                return (int) $headers[$headerName][0];
            }
        }

        return null;
    }

    /**
     * Extract the Retry-After header value.
     *
     * The Retry-After header can be either:
     * - An integer representing seconds
     * - An HTTP-date representing when to retry
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    protected static function extractRetryAfter(array $headers): ?int
    {
        $variations = ['Retry-After', 'retry-after', 'RETRY-AFTER'];

        foreach ($variations as $headerName) {
            if (! isset($headers[$headerName][0])) {
                continue;
            }

            $value = $headers[$headerName][0];

            // Skip empty values (isset already excludes null)
            if ($value === '') {
                continue;
            }

            // If it's a number, it's seconds
            if (is_numeric($value)) {
                return (int) $value;
            }

            // Try to parse as HTTP-date using strtotime for broader format support
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                $diff = $timestamp - time();

                return max(0, $diff);
            }
        }

        return null;
    }

    /**
     * Get the number of requests remaining in the current rate limit window.
     */
    public function getRateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }

    /**
     * Get the total number of requests allowed per rate limit window.
     */
    public function getRateLimitLimit(): ?int
    {
        return $this->rateLimitLimit;
    }

    /**
     * Get the Unix timestamp when the rate limit window resets.
     */
    public function getRateLimitReset(): ?int
    {
        return $this->rateLimitReset;
    }

    /**
     * Get the reset time as a DateTimeImmutable object.
     */
    public function getResetTime(): ?DateTimeImmutable
    {
        if ($this->rateLimitReset === null) {
            return null;
        }

        return new DateTimeImmutable('@'.$this->rateLimitReset);
    }

    /**
     * Get the number of seconds to wait before retrying.
     *
     * This returns the Retry-After header value if available,
     * or calculates it from the reset timestamp.
     */
    public function getRetryAfter(): ?int
    {
        if ($this->retryAfter !== null) {
            return $this->retryAfter;
        }

        if ($this->rateLimitReset !== null) {
            $diff = $this->rateLimitReset - time();

            return max(0, $diff);
        }

        return null;
    }

    /**
     * Get recommended wait time in seconds before retrying.
     *
     * Returns the retry-after value if available, otherwise returns
     * a default of 1 second (based on Kudosity's 15 calls/second limit).
     */
    public function getRecommendedWaitSeconds(): int
    {
        return $this->getRetryAfter() ?? 1;
    }

    /**
     * Check if rate limit metadata is available.
     */
    public function hasRateLimitMetadata(): bool
    {
        return $this->rateLimitRemaining !== null
            || $this->rateLimitLimit !== null
            || $this->rateLimitReset !== null
            || $this->retryAfter !== null;
    }
}
