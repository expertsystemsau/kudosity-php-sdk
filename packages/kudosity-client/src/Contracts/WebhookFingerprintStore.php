<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Resources\WebhooksResource;

/**
 * Somewhere to remember that a webhook registration was already reconciled.
 *
 * Two methods, and no dependency on a caching library: wrapping a PSR-16 cache
 * is a handful of lines in a consumer's own code, which is cheaper than adding
 * a dependency to a package that has two.
 *
 * **Never authoritative.** A store only lets
 * {@see WebhooksResource::ensure()} skip the
 * list request. A missing, stale or corrupt entry costs one `GET` and can never
 * produce a wrong registration.
 */
interface WebhookFingerprintStore
{
    /**
     * The stored fingerprint for a receiver identity, or null when unknown.
     */
    public function get(string $key): ?string;

    /**
     * Record the fingerprint for a receiver identity.
     */
    public function put(string $key, string $fingerprint): void;
}
