<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\WebhookEventType;

/**
 * A tracked link was fetched — `LINK_HIT`, payload under `link_hit`.
 *
 * **A link hit is not evidence a human clicked.** In a captured run the first
 * hit arrived in the *same second* as the `DELIVERED` status, roughly two
 * seconds after the send — nobody reads an SMS and taps a link that fast. It
 * was an automated fetch, consistent with a messaging app generating a link
 * preview. The human tap was the *second* hit, sixteen seconds later.
 *
 * So treating a `LINK_HIT` as engagement over-reports it, in the same shape as
 * treating `ACCEPTED` as `DELIVERED`. Anything user-facing should say "link
 * fetched" rather than "customer clicked", and infer intent only after
 * discounting the fetch that lands alongside delivery.
 *
 * **`$hits` is cumulative for the tracked link, not a unique-recipient count.**
 * It counts repeat fetches, machine and human alike, which is why two fixtures
 * exist for one send: `hits: 1` and `hits: 2`.
 *
 * **`$url` is the original destination; the shortened link lives in
 * `$sourceMessage->message`.** Code looking for the original URL in the message
 * text will not find it — see `tests/Fixtures/V2Webhooks/README.md`.
 */
final readonly class LinkHitEvent extends WebhookEvent
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
        public int $hits,
        public ?string $url,
        public ?SourceMessage $sourceMessage,
    ) {
        parent::__construct($eventType, $timestamp, $webhookId, $webhookName, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $hit = self::section($payload, 'link_hit');

        return new self(
            ...self::commonFields($payload),
            hits: (int) ($hit['hits'] ?? 0),
            url: is_string($hit['url'] ?? null) ? $hit['url'] : null,
            sourceMessage: is_array($hit['source_message'] ?? null)
                ? SourceMessage::fromArray($hit['source_message'])
                : null,
        );
    }

    public function messageRef(): ?string
    {
        return $this->sourceMessage?->messageRef;
    }

    /**
     * Whether this is the first recorded fetch of the link.
     *
     * Deliberately not named `isFirstClick()`: see the class docblock. The
     * first fetch is the one most likely to be a machine.
     */
    public function isFirstHit(): bool
    {
        return $this->hits === 1;
    }
}
