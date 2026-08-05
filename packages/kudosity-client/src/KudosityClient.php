<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\KudosityV1Request;
use ExpertSystems\Kudosity\Resources\AccountResource;
use ExpertSystems\Kudosity\Resources\BulkSmsResource;
use ExpertSystems\Kudosity\Resources\EmailSmsResource;
use ExpertSystems\Kudosity\Resources\KeywordsResource;
use ExpertSystems\Kudosity\Resources\ListsResource;
use ExpertSystems\Kudosity\Resources\NumbersResource;
use ExpertSystems\Kudosity\Resources\ReportingResource;
use Saloon\Http\Response;

class KudosityClient
{
    protected KudosityV1Connector $connector;

    /**
     * Cached resource instances.
     *
     * Note: This client is NOT thread-safe. In async/parallel PHP environments
     * (e.g., Swoole, ReactPHP, parallel extension), each concurrent context
     * should use its own client instance to avoid race conditions with the
     * resource caching using the ??= operator.
     */
    protected ?AccountResource $accountResource = null;

    protected ?BulkSmsResource $bulkResource = null;

    protected ?ReportingResource $reportingResource = null;

    protected ?ListsResource $listsResource = null;

    protected ?NumbersResource $numbersResource = null;

    protected ?KeywordsResource $keywordsResource = null;

    protected ?EmailSmsResource $emailSmsResource = null;

    /**
     * Create a new Kudosity client instance.
     *
     * For most use cases, use the standard constructor with API credentials.
     * To create a client from an existing connector, use fromConnector().
     *
     * @param  string  $apiKey  Your Kudosity API key
     * @param  string  $apiSecret  Your Kudosity API secret
     * @param  string  $baseUrl  The base URL for the API
     * @param  int  $timeout  Request timeout in seconds
     */
    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $baseUrl = KudosityV1Connector::BASE_URL,
        int $timeout = 30,
    ) {
        $this->connector = new KudosityV1Connector(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            baseUrl: $baseUrl,
            timeout: $timeout,
        );
    }

    /**
     * Create client from an existing connector.
     *
     * This is useful when you need to share a connector between multiple
     * clients or when using a pre-configured connector from a service container.
     *
     * Note: The connector should be properly configured with valid credentials
     * before being passed to this method. No validation is performed on the
     * connector's configuration (API key, secret, etc.). Invalid or empty
     * credentials will result in authentication failures when making requests.
     *
     * @param  KudosityV1Connector  $connector  A pre-configured connector instance
     * @return self A new client using the provided connector
     */
    public static function fromConnector(KudosityV1Connector $connector): self
    {
        // Create a new instance using the connector's credentials
        // The connector stores these values, so we extract them for proper initialization
        $client = new self(
            apiKey: $connector->getApiKey(),
            apiSecret: $connector->getApiSecret(),
            baseUrl: $connector->resolveBaseUrl(),
            timeout: $connector->getTimeout(),
        );

        // Replace the newly created connector with the provided one
        // to preserve any custom configuration or middleware
        $client->connector = $connector;

        return $client;
    }

    /**
     * Get the underlying connector.
     */
    public function connector(): KudosityV1Connector
    {
        return $this->connector;
    }

    // =========================================================================
    // Resources
    // =========================================================================

    /**
     * Access account-related API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function account(): AccountResource
    {
        return $this->accountResource ??= new AccountResource($this->connector);
    }

    /**
     * V1 bulk SMS: multiple recipients, contact lists, scheduled sends, cancel.
     *
     * V2's `sms()` — arriving in the next release — takes exactly one recipient
     * and cannot schedule, so these sends stay on V1.
     */
    public function bulk(): BulkSmsResource
    {
        return $this->bulkResource ??= new BulkSmsResource($this->connector);
    }

    /**
     * Access reporting and statistics API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function reporting(): ReportingResource
    {
        return $this->reportingResource ??= new ReportingResource($this->connector);
    }

    /**
     * Access contact lists API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function lists(): ListsResource
    {
        return $this->listsResource ??= new ListsResource($this->connector);
    }

    /**
     * Access virtual numbers API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function numbers(): NumbersResource
    {
        return $this->numbersResource ??= new NumbersResource($this->connector);
    }

    /**
     * Access keywords API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function keywords(): KeywordsResource
    {
        return $this->keywordsResource ??= new KeywordsResource($this->connector);
    }

    /**
     * Access email SMS API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function emailSms(): EmailSmsResource
    {
        return $this->emailSmsResource ??= new EmailSmsResource($this->connector);
    }

    // =========================================================================
    // Low-Level Request Methods
    // =========================================================================

    /**
     * Send a request and return the response.
     *
     * Use this for advanced use cases where you need direct access to the response.
     * For most cases, prefer using the resource methods (e.g., $client->account()->getBalance()).
     *
     * @throws KudosityException
     */
    public function send(KudosityV1Request $request): Response
    {
        $response = $this->connector->send($request);

        $this->validateResponse($response);

        return $response;
    }

    /**
     * Send a request and return the JSON data as an array.
     *
     * Use this for advanced use cases where you need the raw JSON response.
     * For most cases, prefer using the resource methods which return typed DTOs.
     *
     * @return array<string, mixed>
     *
     * @throws KudosityException
     */
    public function sendAndGetJson(KudosityV1Request $request): array
    {
        return $this->send($request)->json();
    }

    // =========================================================================
    // Response Validation
    // =========================================================================

    /**
     * Validate the API response and throw exception if error.
     *
     * @throws KudosityException
     */
    protected function validateResponse(Response $response): void
    {
        // Check for HTTP errors (4xx, 5xx)
        if ($response->failed()) {
            throw KudosityException::fromV1Response($response);
        }

        // Check for API-level errors in the response body
        $data = $response->json();

        if (isset($data['error']) && ($data['error']['code'] ?? 'SUCCESS') !== 'SUCCESS') {
            throw KudosityException::fromV1Response($response);
        }
    }

    /**
     * Set a custom base URL.
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->connector->setBaseUrl($baseUrl);

        return $this;
    }
}
