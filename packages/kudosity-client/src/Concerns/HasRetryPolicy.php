<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;

/**
 * Shared retry configuration for the V1 and V2 connectors.
 *
 * Both APIs fail the same transient ways — 429 rate limits, 5xx, dropped
 * connections — so the configuration lives here rather than in either
 * connector.
 *
 * `handleRetry()` below is written to retry a 429 or 5xx as well as a
 * dropped connection, but neither connector's HTTP failures currently reach
 * it in practice: Saloon only calls it for `FatalRequestException` (a
 * connection failure) or `RequestException` (Saloon's own HTTP-failure
 * exception), and both connectors override `getRequestException()` to
 * throw a `KudosityException` instead — outside that hierarchy — so an
 * HTTP failure response escapes the retry loop on the first attempt. Only
 * dropped connections retry today. This predates the V2 connector and is
 * pre-existing behaviour, not something this trait introduced.
 *
 * @see https://docs.saloon.dev/digging-deeper/retrying-requests
 */
trait HasRetryPolicy
{
    /**
     * Configure automatic retry behavior for transient failures.
     *
     * @param  int  $tries  Maximum attempts, including the initial request
     * @param  int  $intervalMs  Initial interval between retries, in milliseconds
     * @param  bool  $useExponentialBackoff  Double the interval after each retry
     * @param  bool  $throwOnMaxTries  Throw once all retries are exhausted
     */
    public function withRetry(
        int $tries = 3,
        int $intervalMs = 1000,
        bool $useExponentialBackoff = true,
        bool $throwOnMaxTries = true
    ): static {
        $this->tries = $tries;
        $this->retryInterval = $intervalMs;
        $this->useExponentialBackoff = $useExponentialBackoff;
        $this->throwOnMaxTries = $throwOnMaxTries;

        return $this;
    }

    /**
     * Disable automatic retries.
     */
    public function withoutRetry(): static
    {
        $this->tries = null;
        $this->retryInterval = null;
        $this->useExponentialBackoff = null;
        $this->throwOnMaxTries = null;

        return $this;
    }

    /**
     * Decide whether a failed request should be retried.
     *
     * Written to retry connection failures, 429s and 5xx, and never other
     * 4xx — a validation error will fail identically however many times it
     * is sent. In practice, only the connection-failure branch is ever
     * reached today; see this trait's docblock for why the 429/5xx branches
     * below are currently unreachable dead code.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        $status = $exception->getResponse()->status();

        if ($status === 429) {
            return true;
        }

        return $status >= 500 && $status < 600;
    }
}
