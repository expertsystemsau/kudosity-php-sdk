<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Events;

use ExpertSystems\Kudosity\Webhooks\OptOutEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A recipient opted out.
 *
 * `$optOut->source` says how — a STOP reply or the opt-out link — and **both are
 * binding**. An unrecognised source is still an opt-out. `$optOut->optedOutNumber()`
 * is the person, taken from the source message's recipient rather than its sender,
 * which is you.
 */
class KudosityOptOutReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $context  Any context carried on the signed receiver URL.
     */
    public function __construct(
        public readonly OptOutEvent $optOut,
        public readonly array $context = [],
    ) {}
}
