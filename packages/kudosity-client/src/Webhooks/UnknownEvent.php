<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

/**
 * An event type this SDK does not recognise.
 *
 * Returned rather than thrown, deliberately. A webhook endpoint does not choose
 * what it is sent: one registration can serve every channel, the documented
 * event list is expected to grow, and `filter.event_type` is matched by
 * Kudosity rather than enforced by us. A receiver that 500s on an event type
 * shipped last week looks — from Kudosity's side — like an endpoint that is
 * down, and the retry it triggers arrives at the same 500.
 *
 * {@see WebhookEvent::$raw} carries the payload verbatim, so an unrecognised
 * delivery can still be logged, queued or inspected. That is the whole point:
 * degrade to "I do not know what this is, here it is" rather than to a failure.
 *
 * {@see self::messageRef()} returns null rather than guessing at a path. Every
 * known event keeps the correlation key somewhere different, so there is no
 * defensible fallback — and a wrong ref is worse than none.
 */
final readonly class UnknownEvent extends WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(...self::commonFields($payload));
    }

    public function messageRef(): ?string
    {
        return null;
    }
}
