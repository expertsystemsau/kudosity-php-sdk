<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Events;

use ExpertSystems\Kudosity\Data\DlrCallbackData;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a DLR (Delivery Receipt) callback is received.
 *
 * This event is always fired regardless of whether a per-message handler
 * is configured, allowing for global logging or monitoring.
 */
class DlrReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  DlrCallbackData  $dlr  The delivery receipt data
     * @param  array<string, mixed>  $context  Context data passed when sending the message
     */
    public function __construct(
        public readonly DlrCallbackData $dlr,
        public readonly array $context = [],
    ) {}
}
