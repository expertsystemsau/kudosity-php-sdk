<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\ParsesV2Timestamps;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;

/**
 * A webhook registration, as returned by `POST`, `GET` and `PUT /v2/webhook`.
 *
 * Unlike the messaging endpoints, these responses are **flat** — no `data`
 * envelope. They also carry three fields the documentation never mentions,
 * observed on every live response: `is_sandbox`, `created_at` and `updated_at`.
 *
 * `$rateLimit` is echoed back as `0` when unset, which means *system default*
 * rather than "no requests allowed". {@see self::hasRateLimit()} exists so that
 * distinction does not have to be remembered at every call site.
 *
 * Note the timestamps arrive with a **varying** number of fractional digits —
 * nine on a create response, six on a read — which is why they go through
 * {@see ParsesV2Timestamps} rather than a fixed format.
 */
final readonly class WebhookData
{
    use ParsesV2Timestamps;

    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public WebhookFilter $filter,
        public int $rateLimit,
        public bool $isSandbox,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            filter: WebhookFilter::fromArray(is_array($data['filter'] ?? null) ? $data['filter'] : []),
            rateLimit: (int) ($data['rate_limit'] ?? 0),
            isSandbox: (bool) ($data['is_sandbox'] ?? false),
            createdAt: self::parseTimestamp($data['created_at'] ?? null),
            updatedAt: self::parseTimestamp($data['updated_at'] ?? null),
        );
    }

    /**
     * Whether an explicit rate limit applies, as opposed to the system default.
     */
    public function hasRateLimit(): bool
    {
        return $this->rateLimit > 0;
    }

    /**
     * Whether deliveries to this registration travel over TLS.
     *
     * Worth checking on a registration read back from the API: the platform
     * accepts an `http://` URL even though the documentation requires HTTPS, so
     * a plaintext registration can exist — created by another tool, or by this
     * SDK before the guard in {@see CreateWebhookRequest} existed.
     * Deliveries are unsigned, so a plaintext one is readable and forgeable in
     * transit.
     */
    public function isSecure(): bool
    {
        return str_starts_with(strtolower($this->url), 'https://');
    }
}
