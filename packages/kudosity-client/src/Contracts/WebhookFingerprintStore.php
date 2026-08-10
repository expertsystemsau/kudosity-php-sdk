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
 * A store only lets {@see WebhooksResource::ensure()} skip the
 * list request. It records that **you** already reconciled this desired state —
 * it says nothing about what the account currently holds. If registrations can
 * change outside your own deploy — a dashboard edit, another environment, a
 * colleague — pass no store: a store is for consumers whose only writer is
 * their own deploy pipeline. A *differing* or corrupt entry still costs only
 * one `GET` and can never produce a wrong write.
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
