<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Events;

use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A tracked link was fetched.
 *
 * **Not evidence a human clicked.** The first hit on a link routinely arrives in
 * the same second as `DELIVERED` — a messaging app generating a preview — and
 * `$linkHit->hits` counts machine fetches as well as taps. Anything user-facing
 * should say "link fetched" rather than "customer clicked".
 */
class KudosityLinkHitReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $context  Any context carried on the signed receiver URL.
     */
    public function __construct(
        public readonly LinkHitEvent $linkHit,
        public readonly array $context = [],
    ) {}
}
