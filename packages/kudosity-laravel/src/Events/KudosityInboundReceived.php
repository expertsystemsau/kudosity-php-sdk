<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Events;

use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A V2 inbound message webhook arrived.
 *
 * `$inbound->sender` is the **customer** and `$inbound->recipient` is your own
 * number — the reverse of an outbound. Route replies on
 * `$inbound->messageRef()`, never on the phone number: number matching breaks
 * the first time one contact is in two flows at once, and again when a shared
 * number delivered the original.
 *
 * When `$inbound->isCorrelated()` is false the message is unsolicited — no ref,
 * no correlation, and no authenticity signal at all. Treat it as low-trust.
 */
class KudosityInboundReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $context  Any context carried on the signed receiver URL.
     */
    public function __construct(
        public readonly InboundEvent $inbound,
        public readonly array $context = [],
    ) {}
}
