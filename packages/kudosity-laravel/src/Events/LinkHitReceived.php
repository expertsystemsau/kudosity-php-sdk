<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Events;

use ExpertSystems\Kudosity\Data\LinkHitCallbackData;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a Link Hit callback is received.
 *
 * This event is always fired regardless of whether a per-message handler
 * is configured, allowing for global logging or monitoring.
 */
class LinkHitReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  LinkHitCallbackData  $linkHit  The link hit data
     * @param  array<string, mixed>  $context  Context data passed when sending the message
     */
    public function __construct(
        public readonly LinkHitCallbackData $linkHit,
        public readonly array $context = [],
    ) {}
}
