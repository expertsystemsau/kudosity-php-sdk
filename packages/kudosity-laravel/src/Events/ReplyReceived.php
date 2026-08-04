<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Events;

use ExpertSystems\Kudosity\Data\ReplyCallbackData;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a Reply callback is received.
 *
 * This event is always fired regardless of whether a per-message handler
 * is configured, allowing for global logging or monitoring.
 */
class ReplyReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  ReplyCallbackData  $reply  The reply message data
     * @param  array<string, mixed>  $context  Context data passed when sending the message
     */
    public function __construct(
        public readonly ReplyCallbackData $reply,
        public readonly array $context = [],
    ) {}
}
