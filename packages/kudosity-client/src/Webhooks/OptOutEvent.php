<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\OptOutSource;
use ExpertSystems\Kudosity\Enums\WebhookEventType;

/**
 * A recipient opted out — `OPT_OUT`, payload under `opt_out`.
 *
 * `$source` says how: a STOP reply ({@see OptOutSource::SmsInbound}) or the
 * opt-out link ({@see OptOutSource::LinkHit}). **Both are binding**, and an
 * {@see OptOutSource::Unknown} source is still an opt-out — the enum exists for
 * reporting and audit, not for deciding whether to keep sending.
 */
final readonly class OptOutEvent extends WebhookEvent
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
        public OptOutSource $source,
        public ?SourceMessage $sourceMessage,
    ) {
        parent::__construct($eventType, $timestamp, $webhookId, $webhookName, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $optOut = self::section($payload, 'opt_out');

        return new self(
            ...self::commonFields($payload),
            source: OptOutSource::fromApi(is_string($optOut['source'] ?? null) ? $optOut['source'] : null),
            sourceMessage: is_array($optOut['source_message'] ?? null)
                ? SourceMessage::fromArray($optOut['source_message'])
                : null,
        );
    }

    public function messageRef(): ?string
    {
        return $this->sourceMessage?->messageRef;
    }

    /**
     * The number that opted out.
     *
     * On an opt-out the `source_message` is the outbound they were reacting to,
     * so the person is its `recipient` — not its `sender`, which is you.
     */
    public function optedOutNumber(): ?string
    {
        return $this->sourceMessage?->recipient;
    }
}
