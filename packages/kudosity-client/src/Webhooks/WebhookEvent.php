<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\ParsesV2Timestamps;
use ExpertSystems\Kudosity\Enums\WebhookEventType;

/**
 * A webhook delivery, resolved into a typed event.
 *
 * Ten event types, four payload shapes. {@see self::fromArray()} dispatches on
 * `event_type` into {@see StatusEvent}, {@see InboundEvent},
 * {@see LinkHitEvent} or {@see OptOutEvent}, and {@see UnknownEvent} for
 * anything Kudosity adds later.
 *
 * **These are not `Data\V2\*` DTOs.** Nothing here is built from a response to
 * a request the SDK made — they are built from a request body an application
 * *received*, over a channel with no authentication. That is a different trust
 * level, and it is why they live in their own namespace.
 *
 * ## The correlation key
 *
 * `message_ref` is how a delivery is tied back to whatever it belongs to — an
 * order, a booking, a conversation. It arrives at a **different path per event
 * type**:
 *
 * | Event | Path |
 * |---|---|
 * | status events | `status.message_ref` |
 * | inbound events | `mo.last_message.message_ref` |
 * | `LINK_HIT` | `link_hit.source_message.message_ref` |
 * | `OPT_OUT` | `opt_out.source_message.message_ref` |
 *
 * {@see self::messageRef()} is the one accessor that hides that. A consumer
 * forced to know which path applies is a consumer who will get one of them
 * wrong.
 *
 * ## Two things a receiver must not assume
 *
 * **Deliveries are unsigned.** The complete observed header set is
 * `accept-encoding`, `content-length`, `content-type`, `host`, `sentry-trace`,
 * `traceparent`, `user-agent: Go-http-client/2.0` — no HMAC, no signature, no
 * auth header of any kind. Constructing one of these proves the payload parsed,
 * never that Kudosity sent it. See {@see SignedMessageRef} for the mitigation.
 *
 * **Deliveries are at-least-once, and out of order.** A captured run had the
 * `SENT` event redelivered 60 seconds later, carrying its original timestamp,
 * arriving 57 seconds *after* `DELIVERED`, byte-identical to the original.
 * Handling must be idempotent on `status.id`; see {@see StatusPrecedence}.
 */
abstract readonly class WebhookEvent
{
    use ParsesV2Timestamps;

    /**
     * @param  array<string, mixed>  $raw  The payload exactly as delivered.
     */
    public function __construct(
        public WebhookEventType $eventType,
        public ?DateTimeImmutable $timestamp,
        public ?string $webhookId,
        public ?string $webhookName,
        public array $raw,
    ) {}

    /**
     * Resolve a delivered payload into a typed event.
     *
     * Never throws on an unrecognised `event_type` — it produces an
     * {@see UnknownEvent} carrying the raw payload. A webhook endpoint does not
     * choose what it is sent, and a 500 on an event type Kudosity shipped last
     * week is a worse failure than an unhandled-but-logged payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $type = WebhookEventType::fromApi(
            is_string($payload['event_type'] ?? null) ? $payload['event_type'] : null,
        );

        return match (true) {
            $type->isStatus() => StatusEvent::fromPayload($payload),
            $type->isInbound() => InboundEvent::fromPayload($payload),
            $type === WebhookEventType::LinkHit => LinkHitEvent::fromPayload($payload),
            $type === WebhookEventType::OptOut => OptOutEvent::fromPayload($payload),
            default => UnknownEvent::fromPayload($payload),
        };
    }

    /**
     * The correlation key, wherever this event type happens to keep it.
     *
     * Null is a real answer, not a parse failure: on an inbound event the key
     * lives under `last_message`, which Kudosity attaches on a best-effort
     * basis and omits when no recent outbound matches. An inbound message with
     * no `message_ref` is unsolicited — it can be neither correlated nor
     * authenticated, and should be treated as low-trust.
     */
    abstract public function messageRef(): ?string;

    /**
     * The fields every event carries, ready to spread into a constructor.
     *
     * `webhook_id` and `webhook_name` are undocumented upstream but present on
     * every live delivery; they name which registration fired, which is what
     * makes per-environment webhooks debuggable.
     *
     * @param  array<string, mixed>  $payload
     * @return array{eventType: WebhookEventType, timestamp: ?DateTimeImmutable, webhookId: ?string, webhookName: ?string, raw: array<string, mixed>}
     */
    protected static function commonFields(array $payload): array
    {
        return [
            'eventType' => WebhookEventType::fromApi(
                is_string($payload['event_type'] ?? null) ? $payload['event_type'] : null,
            ),
            'timestamp' => self::parseTimestamp($payload['timestamp'] ?? null),
            'webhookId' => is_string($payload['webhook_id'] ?? null) ? $payload['webhook_id'] : null,
            'webhookName' => is_string($payload['webhook_name'] ?? null) ? $payload['webhook_name'] : null,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function section(array $payload, string $key): array
    {
        return is_array($payload[$key] ?? null) ? $payload[$key] : [];
    }
}
