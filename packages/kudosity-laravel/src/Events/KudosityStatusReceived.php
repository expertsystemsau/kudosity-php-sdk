<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Events;

use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A V2 delivery-status webhook arrived.
 *
 * **Handle idempotently on `$status->id`.** Several status events fire per
 * message, they are not order-guaranteed, and deliveries are at-least-once — a
 * `SENT` redelivered 57 seconds after `DELIVERED` has been observed on a live
 * account. Use {@see StatusPrecedence::supersedes()}
 * before recording a change; a listener that writes unconditionally will corrupt
 * its own delivery reporting.
 */
class KudosityStatusReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $context  Any context carried on the signed receiver URL.
     */
    public function __construct(
        public readonly StatusEvent $status,
        public readonly array $context = [],
    ) {}
}
