<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Callbacks;

use ExpertSystems\Kudosity\Exceptions\InvalidSignatureException;

/**
 * Parses and verifies signed callback URLs from TransmitSMS webhooks.
 *
 * Validates the HMAC signature to ensure the callback URL hasn't been tampered with.
 */
class CallbackUrlParser
{
    /**
     * @param  string  $signingKey  Secret key for HMAC verification (must match the key used to build the URL)
     */
    public function __construct(
        protected string $signingKey,
    ) {}

    /**
     * Parse and verify a callback URL's query parameters.
     *
     * @param  array<string, string>  $queryParams  The query parameters from the incoming request
     * @return array{handler: string|null, context: array<string, mixed>}
     *
     * @throws InvalidSignatureException If the signature is missing or invalid
     */
    public function parse(array $queryParams): array
    {
        $handler = $queryParams['h'] ?? null;
        $context = $queryParams['c'] ?? null;
        $signature = $queryParams['s'] ?? null;

        // If no handler or context, no signature verification needed (events-only mode)
        if ($handler === null && $context === null) {
            return [
                'handler' => null,
                'context' => [],
            ];
        }

        // Signature is required if handler or context is present
        if ($signature === null) {
            throw new InvalidSignatureException('Missing callback signature');
        }

        // Verify signature
        if (! $this->verify($handler ?? '', $context ?? '', $signature)) {
            throw new InvalidSignatureException('Invalid callback signature');
        }

        $decodedContext = [];
        if ($context !== null) {
            $decodedContextString = $this->decode($context);
            if ($decodedContextString !== '') {
                try {
                    $decodedContext = json_decode($decodedContextString, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new InvalidSignatureException('Invalid callback context: malformed JSON');
                }
            }
        }

        return [
            'handler' => $handler !== null ? $this->decode($handler) : null,
            'context' => $decodedContext,
        ];
    }

    /**
     * Verify that the signature is valid for the given handler and context.
     *
     * @param  string  $handler  Base64-encoded handler string
     * @param  string  $context  Base64-encoded context string
     * @param  string  $signature  The HMAC signature to verify
     */
    public function verify(string $handler, string $context, string $signature): bool
    {
        $expectedSignature = hash_hmac('sha256', $handler.$context, $this->signingKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Base64 URL-safe decode a string.
     */
    protected function decode(string $data): string
    {
        // Add padding if needed
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }
}
