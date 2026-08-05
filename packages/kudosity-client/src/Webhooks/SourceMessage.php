<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

/**
 * The message-shaped object a webhook payload attaches to give an event its
 * context: `mo.last_message` on inbound events, `link_hit.source_message` on
 * link hits, `opt_out.source_message` on opt-outs.
 *
 * One class for all three because the shape is the same, and because
 * {@see WebhookEvent::messageRef()} reads its `message_ref` regardless of which
 * key it arrived under.
 *
 * `$message` is the text **as sent**, which for a tracked link means it carries
 * the *shortened* URL. A captured delivery holds
 * `"… Please tap: https://tapth.at/qK.LnvtM"` in here while
 * {@see LinkHitEvent::$url} holds the original destination — see
 * `tests/Fixtures/V2Webhooks/README.md`. Code looking for the original URL in
 * the message text will not find it.
 *
 * There is no `toArray()`: nothing in the SDK sends this shape.
 */
final readonly class SourceMessage
{
    /**
     * @param  array<int, string>  $contentUrls  MMS only; empty for every other channel.
     */
    public function __construct(
        public ?string $type,
        public string $id,
        public ?string $message,
        public ?string $messageRef,
        public string $recipient,
        public string $sender,
        public ?string $routedVia,
        public ?string $subject = null,
        public array $contentUrls = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $routedVia = is_string($data['routed_via'] ?? null) ? $data['routed_via'] : null;

        $contentUrls = [];

        if (is_array($data['content_urls'] ?? null)) {
            foreach ($data['content_urls'] as $url) {
                if (is_string($url)) {
                    $contentUrls[] = $url;
                }
            }
        }

        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            id: (string) ($data['id'] ?? ''),
            // Not coerced with (string): a payload that omits the text is
            // different from one carrying an empty string, and callers deciding
            // whether to show it need to tell those apart.
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            messageRef: is_string($data['message_ref'] ?? null) ? $data['message_ref'] : null,
            recipient: (string) ($data['recipient'] ?? ''),
            sender: (string) ($data['sender'] ?? ''),
            // Normalised the same way the V2 message DTOs do it: the live API
            // sends "" rather than omitting the key when no shared number was
            // involved, and "" is not a number.
            routedVia: $routedVia === '' ? null : $routedVia,
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : null,
            contentUrls: $contentUrls,
        );
    }

    /**
     * Whether a shared number delivered the original message.
     *
     * When true, the recipient did not reply to your sender, so pairing an
     * inbound message to an outbound one on `sender`/`recipient` breaks. Route
     * on `message_ref` instead.
     */
    public function wasRoutedViaSharedNumber(): bool
    {
        return $this->routedVia !== null;
    }
}
