<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\WebhookEventType;

/**
 * A delivery-status event: `SMS_STATUS`, `MMS_STATUS`, `WHATSAPP_STATUS` or
 * `RCS_STATUS`, all carrying their payload under `status`.
 *
 * Three things worth knowing before acting on one:
 *
 * - **`$id` is the key to record against, and it is stable.** Every status
 *   event for a message carries the same `status.id`. Multiple events fire per
 *   message, order is not guaranteed, and deliveries are at-least-once — see
 *   {@see StatusPrecedence}, which is what stops a late `SENT` overwriting a
 *   recorded `DELIVERED`.
 * - **`ACCEPTED` is not `DELIVERED`.** {@see MessageStatus::isDelivered()}
 *   excludes it deliberately; treating it as success is the documented cause of
 *   over-reported delivery rates.
 * - **`$description` is undocumented** but real: `MMS_STATUS` carries carrier
 *   detail here, e.g. `"Sent to Vodafone (response status details: Success)"`.
 *   Absent on the other channels.
 */
final readonly class StatusEvent extends WebhookEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        WebhookEventType $eventType,
        ?DateTimeImmutable $timestamp,
        ?string $webhookId,
        ?string $webhookName,
        array $raw,
        public ?string $type,
        public string $id,
        public ?string $messageRef,
        public string $recipient,
        public string $sender,
        public ?string $routedVia,
        public MessageStatus $status,
        public ?string $description,
    ) {
        parent::__construct($eventType, $timestamp, $webhookId, $webhookName, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $status = self::section($payload, 'status');
        $routedVia = is_string($status['routed_via'] ?? null) ? $status['routed_via'] : null;

        return new self(
            ...self::commonFields($payload),
            type: is_string($status['type'] ?? null) ? $status['type'] : null,
            id: (string) ($status['id'] ?? ''),
            messageRef: is_string($status['message_ref'] ?? null) ? $status['message_ref'] : null,
            recipient: (string) ($status['recipient'] ?? ''),
            sender: (string) ($status['sender'] ?? ''),
            routedVia: $routedVia === '' ? null : $routedVia,
            // Webhooks send UPPERCASE (DELIVERED) where the send endpoints
            // answer lowercase (delivered). fromApi() is case-insensitive for
            // exactly this reason.
            status: MessageStatus::fromApi(is_string($status['status'] ?? null) ? $status['status'] : null),
            description: is_string($status['description'] ?? null) ? $status['description'] : null,
        );
    }

    public function messageRef(): ?string
    {
        return $this->messageRef;
    }
}
