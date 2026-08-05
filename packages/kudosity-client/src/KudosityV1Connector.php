<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity;

use ExpertSystems\Kudosity\Concerns\HasRetryPolicy;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use Saloon\Http\Auth\BasicAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\Traits\Plugins\AcceptsJson;

class KudosityV1Connector extends Connector implements HasPagination
{
    use AcceptsJson;
    use HasRetryPolicy;

    public const BASE_URL = 'https://api.transmitsms.com';

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
        protected string $baseUrl = self::BASE_URL,
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
     *
     * V1 needs both halves of the credential. A client built for V2 only has
     * no secret, so say so plainly rather than letting the API answer 401.
     *
     * @throws KudosityException
     */
    protected function defaultAuth(): BasicAuthenticator
    {
        if ($this->apiSecret === '') {
            throw new KudosityException(
                'The Kudosity V1 API requires both an API key and an API secret. '
                .'Set KUDOSITY_API_SECRET (Developers → API Settings in the dashboard). '
                .'The V2 API needs only the key.'
            );
        }

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
    public function paginate(Request $request): V1PagedPaginator
    {
        return new V1PagedPaginator($this, $request);
    }

    /**
     * Determine if the request has failed.
     *
     * Kudosity API returns an `error` object even on success with `code: SUCCESS`.
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
     * Returns a KudosityException with error details from the API response.
     * This is called by Saloon when throw() is invoked on a failed response.
     *
     * @see https://docs.saloon.dev/the-basics/handling-failures#custom-exceptions
     */
    public function getRequestException(Response $response, ?\Throwable $senderException): ?\Throwable
    {
        return KudosityException::fromV1Response($response);
    }
}
