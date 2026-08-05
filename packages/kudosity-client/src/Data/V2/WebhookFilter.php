<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\WebhookEventType;

/**
 * Which events a webhook registration should receive.
 *
 * ## The filter semantics, which are not symmetrical
 *
 * Within one array the conditions are **OR**-ed; between different arrays they
 * are **AND**-ed. So `eventType: [SmsStatus, SmsInbound], status: [Failed]`
 * means "an SMS status or inbound event, and only if failed" — which for an
 * inbound event means the `status` condition can never match, and you receive
 * no inbound events at all.
 *
 * ## The key that reads a different field per event type
 *
 * `sender` and `messageRef` are matched against a different part of the payload
 * depending on the event:
 *
 * | Event | Matched against |
 * |---|---|
 * | status events | the `status` object |
 * | `LINK_HIT` | `link_hit.source_message` |
 * | `OPT_OUT` | `opt_out.source_message` |
 * | inbound events | **`sender` matches `mo.recipient`** — i.e. *your* number, not the customer's. `messageRef` and `campaignId` match `last_message`, when it is present. |
 *
 * The inbound row is the one that surprises people, and it follows from inbound
 * payloads having sender and recipient reversed. Filtering inbound events by
 * `sender` filters by the number that *received* the reply.
 *
 * Because `messageRef` matches `last_message` on inbound events, and
 * `last_message` is attached only on a best-effort basis, a `messageRef` filter
 * silently drops unsolicited inbound messages. That is usually what you want —
 * but it is a filter that loses data rather than one that narrows it.
 */
final readonly class WebhookFilter
{
    /**
     * @param  array<int, WebhookEventType|string>  $eventType
     * @param  array<int, string>  $sender
     * @param  array<int, MessageStatus|string>  $status
     * @param  array<int, string>  $messageRef
     * @param  array<int, string>  $campaignId
     */
    public function __construct(
        public array $eventType = [],
        public array $sender = [],
        public array $status = [],
        public array $messageRef = [],
        public array $campaignId = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventType: self::strings($data['event_type'] ?? null),
            sender: self::strings($data['sender'] ?? null),
            status: self::strings($data['status'] ?? null),
            messageRef: self::strings($data['message_ref'] ?? null),
            campaignId: self::strings($data['campaign_id'] ?? null),
        );
    }

    /**
     * The filter as the API expects it, omitting anything not set.
     *
     * Empty arrays are dropped rather than sent as `[]`: an empty filter array
     * is not the same request as an absent one, and "match nothing" is not what
     * a caller who left a parameter alone meant.
     *
     * @return array<string, array<int, string>>
     */
    public function toArray(): array
    {
        return array_filter([
            'event_type' => self::values($this->eventType),
            'sender' => self::values($this->sender),
            'status' => self::values($this->status),
            'message_ref' => self::values($this->messageRef),
            'campaign_id' => self::values($this->campaignId),
        ], static fn (array $v): bool => $v !== []);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * Accept enum cases as readily as strings.
     *
     * Enums are the point of having them — a stringly-typed filter would defeat
     * {@see WebhookEventType} — but a caller holding a value read back from the
     * API has a string, and refusing it would make round-tripping a filter
     * needlessly awkward.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function values(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            if ($value instanceof WebhookEventType || $value instanceof MessageStatus) {
                $out[] = $value->value;
            } elseif (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        // Appended rather than filtered in place, so this is already a list and
        // serialises as a JSON array rather than an object — the trap
        // TemplateContent's parameters had to be reindexed to avoid.
        return $out;
    }

    /**
     * @return array<int, string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return self::values($value);
    }
}
