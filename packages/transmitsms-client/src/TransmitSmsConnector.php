<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms;

use ExpertSystems\TransmitSms\Exceptions\RateLimitException;
use ExpertSystems\TransmitSms\Pagination\TransmitSmsPaginator;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Auth\BasicAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\Traits\Plugins\AcceptsJson;

class TransmitSmsConnector extends Connector implements HasPagination
{
    use AcceptsJson;

    public const BASE_URL_SMS = 'https://api.transmitsms.com';

    public const BASE_URL_MMS = 'https://api.transmitmessage.com';

    /**
     * Default sender ID (VMN, short code, or alphanumeric).
     */
    protected ?string $defaultFrom = null;

    /**
     * Default country code for formatting local numbers.
     */
    protected ?string $defaultCountryCode = null;

    public function __construct(
        protected string $apiKey,
        protected string $apiSecret,
        protected string $baseUrl = self::BASE_URL_SMS,
        protected int $timeout = 30,
    ) {}

    /**
     * Define the base URL of the API.
     */
    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Define default headers.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
    }

    /**
     * Define default config for the HTTP client.
     *
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
        ];
    }

    /**
     * Define the default authentication.
     */
    protected function defaultAuth(): BasicAuthenticator
    {
        return new BasicAuthenticator($this->apiKey, $this->apiSecret);
    }

    /**
     * Get the API key.
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Get the API secret.
     */
    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    /**
     * Get the base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Set the base URL.
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    /**
     * Use the SMS base URL.
     */
    public function useSmsUrl(): self
    {
        return $this->setBaseUrl(self::BASE_URL_SMS);
    }

    /**
     * Use the MMS base URL.
     */
    public function useMmsUrl(): self
    {
        return $this->setBaseUrl(self::BASE_URL_MMS);
    }

    /**
     * Get the timeout.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set the timeout.
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Get the default sender ID.
     *
     * This is used as the 'from' value when sending SMS if not overridden.
     */
    public function getDefaultFrom(): ?string
    {
        return $this->defaultFrom;
    }

    /**
     * Set the default sender ID.
     *
     * Can be:
     * - A virtual mobile number (VMN) in international format
     * - A short code
     * - An alphanumeric sender (max 11 chars, no spaces)
     *
     * @param  string|null  $from  The default sender ID
     */
    public function setDefaultFrom(?string $from): self
    {
        $this->defaultFrom = $from;

        return $this;
    }

    /**
     * Get the default country code.
     */
    public function getDefaultCountryCode(): ?string
    {
        return $this->defaultCountryCode;
    }

    /**
     * Set the default country code for formatting local numbers.
     *
     * When set, local numbers will be automatically formatted to
     * international E.164 format using this country code.
     *
     * @param  string|null  $countryCode  2-letter ISO 3166 country code (e.g., 'AU', 'NZ', 'US')
     */
    public function setDefaultCountryCode(?string $countryCode): self
    {
        $this->defaultCountryCode = $countryCode;

        return $this;
    }

    /**
     * Create a paginator for the given request.
     *
     * @see https://docs.saloon.dev/installable-plugins/pagination
     */
    public function paginate(Request $request): TransmitSmsPaginator
    {
        return new TransmitSmsPaginator($this, $request);
    }

    // =========================================================================
    // Retry Configuration
    // =========================================================================

    /**
     * Configure automatic retry behavior for transient failures.
     *
     * Uses Saloon's built-in retry functionality to automatically retry
     * failed requests. This is particularly useful for handling:
     * - Rate limit errors (HTTP 429)
     * - Network timeouts
     * - Server errors (HTTP 5xx)
     *
     * Example:
     * ```php
     * $connector->withRetry(
     *     tries: 3,
     *     intervalMs: 1000,
     *     useExponentialBackoff: true
     * );
     * ```
     *
     * @param  int  $tries  Maximum number of retry attempts (including the initial request)
     * @param  int  $intervalMs  Initial interval between retries in milliseconds (default: 1000ms)
     * @param  bool  $useExponentialBackoff  Whether to double the interval after each retry (default: true)
     * @param  bool  $throwOnMaxTries  Whether to throw an exception after all retries are exhausted (default: true)
     * @return $this
     *
     * @see https://docs.saloon.dev/digging-deeper/retrying-requests
     */
    public function withRetry(
        int $tries = 3,
        int $intervalMs = 1000,
        bool $useExponentialBackoff = true,
        bool $throwOnMaxTries = true
    ): self {
        $this->tries = $tries;
        $this->retryInterval = $intervalMs;
        $this->useExponentialBackoff = $useExponentialBackoff;
        $this->throwOnMaxTries = $throwOnMaxTries;

        return $this;
    }

    /**
     * Disable automatic retries.
     *
     * @return $this
     */
    public function withoutRetry(): self
    {
        $this->tries = null;
        $this->retryInterval = null;
        $this->useExponentialBackoff = null;
        $this->throwOnMaxTries = null;

        return $this;
    }

    /**
     * Determine if the request has failed.
     *
     * TransmitSMS API returns an `error` object even on success with `code: SUCCESS`.
     * This method ensures that SUCCESS responses are not treated as failures,
     * which allows Saloon's dtoOrFail() to work correctly.
     *
     * @param  Response  $response  The response to check
     * @return bool|null True if failed, false if success, null for default Saloon behavior
     *
     * @see https://docs.saloon.dev/the-basics/handling-failures#customising-when-saloon-thinks-a-request-has-failed
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        // Let Saloon handle HTTP errors (4xx, 5xx)
        if ($response->status() >= 400) {
            return null;
        }

        // Check API-level error codes
        $data = $response->json();

        // Guard against non-array responses (null, scalar, etc.)
        // PHPDoc says array but json() can return null if decoding fails
        /** @phpstan-ignore function.alreadyNarrowedType */
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['error']) && is_array($data['error'])) {
            $errorCode = $data['error']['code'] ?? null;

            // SUCCESS is not a failure
            if ($errorCode === 'SUCCESS') {
                return false;
            }

            // Any other known error code is a failure
            if (is_string($errorCode)) {
                return true;
            }

            // Unknown error structure - let Saloon decide
            return null;
        }

        // No error field - let Saloon use default behavior
        return null;
    }

    /**
     * Get the request exception for a failed request.
     *
     * Returns a TransmitSmsException with error details from the API response.
     * This is called by Saloon when throw() is invoked on a failed response.
     *
     * @see https://docs.saloon.dev/the-basics/handling-failures#custom-exceptions
     */
    public function getRequestException(Response $response, ?\Throwable $senderException): ?\Throwable
    {
        return Exceptions\TransmitSmsException::fromResponse($response);
    }

    /**
     * Handle retry logic for failed requests.
     *
     * This method is called by Saloon to determine if a request should be retried.
     * By default, it retries on:
     * - Rate limit errors (HTTP 429 / OVER_LIMIT)
     * - Server errors (HTTP 5xx)
     * - Network/connection failures
     *
     * For rate limit errors, if the RateLimitException contains retry timing
     * information from the API headers, you can access it to implement
     * smarter retry strategies in custom implementations.
     *
     * @param  FatalRequestException|RequestException  $exception  The exception that caused the failure
     * @param  Request  $request  The request that failed (can be modified before retry)
     * @return bool Whether to retry the request
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        // Always retry on connection/network failures
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        $response = $exception->getResponse();
        $status = $response->status();

        // Retry on rate limit errors
        if ($status === 429) {
            return true;
        }

        // Retry on server errors (5xx)
        if ($status >= 500 && $status < 600) {
            return true;
        }

        // Don't retry on client errors (4xx except 429)
        return false;
    }
}
