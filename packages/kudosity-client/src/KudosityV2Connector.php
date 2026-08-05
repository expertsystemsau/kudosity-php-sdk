<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity;

use ExpertSystems\Kudosity\Concerns\HasRetryPolicy;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\AcceptsJson;
use Throwable;

/**
 * Connector for the Kudosity V2 API (`api.transmitmessage.com`).
 *
 * V2 covers single-recipient SMS, MMS, WhatsApp, RCS, webhooks and sender
 * registrations. It authenticates with the API **key only** — the API secret
 * belongs to V1 and is deliberately absent from this class, so there is no
 * path by which it could be sent to the wrong host.
 *
 * @see https://developers.kudosity.com/reference/authentication
 */
class KudosityV2Connector extends Connector
{
    use AcceptsJson;
    use HasRetryPolicy;

    public const BASE_URL = 'https://api.transmitmessage.com';

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = self::BASE_URL,
        protected int $timeout = 30,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
        ];
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Determine whether the request failed.
     *
     * Unlike V1, which returns an `error` object even on success, V2 signals
     * failure purely by HTTP status — so Saloon's default 4xx/5xx handling is
     * exactly right and this returns null to defer to it. Notably `POST
     * /v2/webhook` answers 201, which is a success.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return null;
    }

    /**
     * Map a failed V2 response onto a typed exception.
     */
    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return KudosityException::fromV2Response($response);
    }
}
