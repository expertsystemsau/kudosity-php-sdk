<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Callbacks;

/**
 * Builds signed callback URLs with encoded context for TransmitSMS webhooks.
 *
 * The callback URL includes:
 * - h: Base64-encoded handler class name
 * - c: Base64-encoded JSON context data
 * - s: HMAC-SHA256 signature for verification
 *
 * Example URL:
 * https://app.com/webhooks/transmitsms/dlr?h=QXBwXEpvYnM=&c=eyJpZCI6MX0=&s=abc123
 */
class CallbackUrlBuilder
{
    /**
     * @param  string  $baseUrl  Base URL for webhooks (e.g., https://app.com/webhooks/transmitsms)
     * @param  string  $signingKey  Secret key for HMAC signing
     */
    public function __construct(
        protected string $baseUrl,
        protected string $signingKey,
    ) {}

    /**
     * Build a signed callback URL with encoded context.
     *
     * @param  CallbackType  $type  The callback type (dlr, reply, link_hits)
     * @param  string|null  $handler  Handler class name to invoke when callback is received
     * @param  array<string, mixed>  $context  Context data to pass to the handler
     * @return string The signed callback URL
     */
    public function build(
        CallbackType $type,
        ?string $handler = null,
        array $context = [],
    ): string {
        $url = rtrim($this->baseUrl, '/').'/'.$type->path();

        // If no handler specified, return base URL (events-only mode)
        if ($handler === null && empty($context)) {
            return $url;
        }

        $params = [];

        if ($handler !== null) {
            $params['h'] = $this->encode($handler);
        }

        if (! empty($context)) {
            $params['c'] = $this->encode(json_encode($context, JSON_THROW_ON_ERROR));
        }

        // Generate signature over handler + context
        $params['s'] = $this->sign(($params['h'] ?? '').($params['c'] ?? ''));

        return $url.'?'.http_build_query($params);
    }

    /**
     * Build a callback URL for DLR (Delivery Receipt) notifications.
     *
     * @param  string|null  $handler  Handler class name
     * @param  array<string, mixed>  $context  Context data
     */
    public function dlr(?string $handler = null, array $context = []): string
    {
        return $this->build(CallbackType::DLR, $handler, $context);
    }

    /**
     * Build a callback URL for Reply notifications.
     *
     * @param  string|null  $handler  Handler class name
     * @param  array<string, mixed>  $context  Context data
     */
    public function reply(?string $handler = null, array $context = []): string
    {
        return $this->build(CallbackType::REPLY, $handler, $context);
    }

    /**
     * Build a callback URL for Link Hit notifications.
     *
     * @param  string|null  $handler  Handler class name
     * @param  array<string, mixed>  $context  Context data
     */
    public function linkHits(?string $handler = null, array $context = []): string
    {
        return $this->build(CallbackType::LINK_HITS, $handler, $context);
    }

    /**
     * Generate an HMAC-SHA256 signature for the given data.
     */
    public function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->signingKey);
    }

    /**
     * Base64 URL-safe encode a string.
     */
    protected function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Get the base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Create a new instance with a different base URL.
     */
    public function withBaseUrl(string $baseUrl): self
    {
        return new self($baseUrl, $this->signingKey);
    }
}
