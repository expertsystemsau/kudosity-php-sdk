<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

use ExpertSystems\Kudosity\Resources\WebhooksResource;

/**
 * What {@see WebhooksResource::ensure()} did.
 *
 * `Unchanged` and `Skipped` are both "nothing was written", and the difference
 * matters: `Unchanged` read the account and confirmed the registration is
 * correct, while `Skipped` trusted a stored fingerprint and never asked. Only
 * `Skipped` comes back without a DTO.
 */
enum EnsureAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
}
