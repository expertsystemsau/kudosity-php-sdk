<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use ExpertSystems\Kudosity\Enums\EnsureAction;

/**
 * The outcome of a webhook reconcile.
 *
 * `$webhook` is null **only** when `$action` is
 * {@see EnsureAction::Skipped} — a stored fingerprint matched, so no request was
 * made and there is no registration to return. Callers that always need the DTO
 * should pass no fingerprint store.
 *
 * `$duplicates` holds any further registrations sharing the same receiver
 * identity. They are reported rather than deleted: removing one is
 * unrecoverable, and nothing here can know which is load-bearing.
 */
final readonly class EnsureResult
{
    /**
     * @param  array<int, WebhookData>  $duplicates
     */
    public function __construct(
        public EnsureAction $action,
        public ?WebhookData $webhook = null,
        public array $duplicates = [],
    ) {}
}
