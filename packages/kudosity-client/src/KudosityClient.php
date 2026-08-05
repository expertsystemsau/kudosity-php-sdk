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
use ExpertSystems\Kudosity\Resources\MmsResource;
use ExpertSystems\Kudosity\Resources\NumbersResource;
use ExpertSystems\Kudosity\Resources\RcsResource;
use ExpertSystems\Kudosity\Resources\ReportingResource;
use ExpertSystems\Kudosity\Resources\SmsV2Resource;
use ExpertSystems\Kudosity\Resources\WhatsAppResource;
use Saloon\Http\Response;

class KudosityClient
{
    protected KudosityV1Connector $v1Connector;

    protected KudosityV2Connector $v2Connector;

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

    protected ?SmsV2Resource $smsResource = null;

    protected ?MmsResource $mmsResource = null;

    protected ?WhatsAppResource $whatsAppResource = null;

    protected ?RcsResource $rcsResource = null;

    /**
     * Create a new Kudosity client.
     *
     * Kudosity runs two APIs under one account. V2 (`api.transmitmessage.com`)
     * authenticates with the key alone and covers single-recipient SMS, MMS,
     * WhatsApp, RCS, webhooks and senders. V1 (`api.transmitsms.com`) needs the
     * key and secret and covers contact lists, bulk and scheduled sends,
     * reporting and balance. Omit the secret if you only need V2.
     *
     * @param  string  $apiKey  Your Kudosity API key — used by both APIs
     * @param  string  $apiSecret  Your Kudosity API secret — V1 only
     * @param  string|null  $v1BaseUrl  Override the V1 host
     * @param  string|null  $v2BaseUrl  Override the V2 host
     * @param  int  $timeout  Request timeout in seconds, applied to both
     */
    public function __construct(
        string $apiKey,
        string $apiSecret = '',
        ?string $v1BaseUrl = null,
        ?string $v2BaseUrl = null,
        int $timeout = 30,
    ) {
        $this->v1Connector = new KudosityV1Connector(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            baseUrl: $v1BaseUrl ?? KudosityV1Connector::BASE_URL,
            timeout: $timeout,
        );

        $this->v2Connector = new KudosityV2Connector(
            apiKey: $apiKey,
            baseUrl: $v2BaseUrl ?? KudosityV2Connector::BASE_URL,
            timeout: $timeout,
        );
    }

    /**
     * Build from pre-configured connectors, for a container or a shared setup.
     *
     * A connector you do not supply is constructed from the other's API key,
     * which both APIs share. The derived connector does NOT inherit the
     * supplied one's base URL or timeout — they are different hosts, so
     * there is nothing sensible to copy across.
     */
    public static function fromConnectors(
        ?KudosityV1Connector $v1 = null,
        ?KudosityV2Connector $v2 = null,
    ): self {
        // Written this way rather than `$v1?->getApiKey() ?? $v2->getApiKey()`
        // so PHPStan can see that $v2 is non-null on the branch that uses it.
        if ($v1 !== null) {
            $apiKey = $v1->getApiKey();
        } elseif ($v2 !== null) {
            $apiKey = $v2->getApiKey();
        } else {
            throw new KudosityException('Provide at least one connector.');
        }

        $client = new self($apiKey);

        $client->v1Connector = $v1 ?? $client->v1Connector;
        $client->v2Connector = $v2 ?? $client->v2Connector;

        return $client;
    }

    /**
     * Build from a V1 connector alone. The V2 connector is derived from its key.
     */
    public static function fromConnector(KudosityV1Connector $connector): self
    {
        return self::fromConnectors(v1: $connector);
    }

    /**
     * The V1 connector (`api.transmitsms.com`, key + secret).
     */
    public function v1(): KudosityV1Connector
    {
        return $this->v1Connector;
    }

    /**
     * The V2 connector (`api.transmitmessage.com`, key only).
     */
    public function v2(): KudosityV2Connector
    {
        return $this->v2Connector;
    }

    /**
     * The V1 connector. Kept for callers that predate the two-connector split.
     */
    public function connector(): KudosityV1Connector
    {
        return $this->v1();
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
        return $this->accountResource ??= new AccountResource($this->v1Connector);
    }

    /**
     * V1 bulk SMS: multiple recipients, contact lists, scheduled sends, cancel.
     *
     * V2's `sms()` takes exactly one recipient and cannot schedule, so these
     * sends stay on V1.
     */
    public function bulk(): BulkSmsResource
    {
        return $this->bulkResource ??= new BulkSmsResource($this->v1Connector);
    }

    /**
     * Access reporting and statistics API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function reporting(): ReportingResource
    {
        return $this->reportingResource ??= new ReportingResource($this->v1Connector);
    }

    /**
     * Access contact lists API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function lists(): ListsResource
    {
        return $this->listsResource ??= new ListsResource($this->v1Connector);
    }

    /**
     * Access virtual numbers API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function numbers(): NumbersResource
    {
        return $this->numbersResource ??= new NumbersResource($this->v1Connector);
    }

    /**
     * Access keywords API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function keywords(): KeywordsResource
    {
        return $this->keywordsResource ??= new KeywordsResource($this->v1Connector);
    }

    /**
     * Access email SMS API operations.
     *
     * @see https://developers.kudosity.com
     */
    public function emailSms(): EmailSmsResource
    {
        return $this->emailSmsResource ??= new EmailSmsResource($this->v1Connector);
    }

    /**
     * V2 single-recipient SMS: `POST /v2/sms`.
     *
     * Takes exactly one recipient and cannot schedule a future send. For
     * multiple recipients, a contact list, or a scheduled send, use
     * `$client->bulk()` instead — that is V1's send surface, and it is the
     * one a 1.x consumer's `sms()` call actually meant.
     *
     * @see https://developers.kudosity.com
     */
    public function sms(): SmsV2Resource
    {
        return $this->smsResource ??= new SmsV2Resource($this->v2Connector);
    }

    /**
     * V2 single-recipient MMS: `POST /v2/mms`.
     *
     * @see https://developers.kudosity.com
     */
    public function mms(): MmsResource
    {
        return $this->mmsResource ??= new MmsResource($this->v2Connector);
    }

    /**
     * V2 WhatsApp messaging: templates, free-form text and custom content.
     *
     * @see https://developers.kudosity.com
     */
    public function whatsapp(): WhatsAppResource
    {
        return $this->whatsAppResource ??= new WhatsAppResource($this->v2Connector);
    }

    /**
     * V2 RCS messaging, with capability checks and SMS fallback.
     *
     * @see https://developers.kudosity.com
     */
    public function rcs(): RcsResource
    {
        return $this->rcsResource ??= new RcsResource($this->v2Connector);
    }

    // =========================================================================
    // Low-Level Request Methods
    // =========================================================================

    /**
     * Send a request and return the response.
     *
     * V1 only — takes a `KudosityV1Request` and validates the response using
     * V1's `error.code` envelope. Use this for advanced use cases where you
     * need direct access to the response. For most cases, prefer using the
     * resource methods (e.g., $client->account()->getBalance()).
     *
     * @throws KudosityException
     */
    public function send(KudosityV1Request $request): Response
    {
        $response = $this->v1Connector->send($request);

        $this->validateResponse($response);

        return $response;
    }

    /**
     * Send a request and return the JSON data as an array.
     *
     * V1 only — see send(). Use this for advanced use cases where you need
     * the raw JSON response. For most cases, prefer using the resource
     * methods which return typed DTOs.
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
     * V1 only — uses V1's `error.code` envelope convention. V2 signals
     * failure purely by HTTP status and is handled by
     * `KudosityV2Connector::getRequestException()` instead.
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
     * Set a custom base URL for the V1 API (`api.transmitsms.com`).
     *
     * Kept as an alias of setV1BaseUrl() for callers written before the
     * two-connector split. On a two-API client, an unqualified "base URL"
     * is ambiguous — prefer setV1BaseUrl() or v2()->setBaseUrl() directly.
     */
    public function setBaseUrl(string $baseUrl): self
    {
        return $this->setV1BaseUrl($baseUrl);
    }

    /**
     * Set a custom base URL for the V1 API (`api.transmitsms.com`).
     */
    public function setV1BaseUrl(string $baseUrl): self
    {
        $this->v1Connector->setBaseUrl($baseUrl);

        return $this;
    }
}
