<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\WebhookEventType;

/**
 * An inbound message: `SMS_INBOUND`, `MMS_INBOUND`, `WHATSAPP_INBOUND` or
 * `RCS_INBOUND`, all carrying their payload under `mo`.
 *
 * **The direction of `$sender` and `$recipient` is reversed here.** `$sender`
 * is the customer and `$recipient` is your own number — the opposite of an
 * outbound message. The webhook `filter`'s `sender` key matches against
 * `mo.recipient` for inbound events, which means filtering inbound by `sender`
 * filters by *your* number, not the customer's.
 *
 * **`$message` is not trimmed.** A captured reply is `"YES "`, trailing space
 * included. Real inbound text is untidy and this exposes what arrived; trim at
 * the point of comparison, where you can decide what "equal" means.
 *
 * **`$lastMessage` is best-effort.** Kudosity attaches the recent outbound it
 * believes this is a reply to, and omits it when nothing matches. When it is
 * null the message is unsolicited: it can be neither correlated nor
 * authenticated, so treat it as low-trust.
 */
final readonly class InboundEvent extends WebhookEvent
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $contentUrls  MMS only.
     */
    public function __construct(
        WebhookEventType $eventType,
        ?DateTimeImmutable $timestamp,
        ?string $webhookId,
        ?string $webhookName,
        array $raw,
        public ?string $type,
        public string $id,
        public ?string $message,
        public string $recipient,
        public string $sender,
        public ?string $routedVia,
        public ?SourceMessage $lastMessage,
        public ?string $subject = null,
        public array $contentUrls = [],
    ) {
        parent::__construct($eventType, $timestamp, $webhookId, $webhookName, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $mo = self::section($payload, 'mo');
        $routedVia = is_string($mo['routed_via'] ?? null) ? $mo['routed_via'] : null;

        $contentUrls = [];

        if (is_array($mo['content_urls'] ?? null)) {
            foreach ($mo['content_urls'] as $url) {
                if (is_string($url)) {
                    $contentUrls[] = $url;
                }
            }
        }

        return new self(
            ...self::commonFields($payload),
            type: is_string($mo['type'] ?? null) ? $mo['type'] : null,
            id: (string) ($mo['id'] ?? ''),
            message: is_string($mo['message'] ?? null) ? $mo['message'] : null,
            recipient: (string) ($mo['recipient'] ?? ''),
            sender: (string) ($mo['sender'] ?? ''),
            routedVia: $routedVia === '' ? null : $routedVia,
            lastMessage: is_array($mo['last_message'] ?? null)
                ? SourceMessage::fromArray($mo['last_message'])
                : null,
            subject: is_string($mo['subject'] ?? null) ? $mo['subject'] : null,
            contentUrls: $contentUrls,
        );
    }

    /**
     * The correlation key from the outbound message this is a reply to.
     *
     * Null when `last_message` is absent, which is the unsolicited-inbound
     * case. Route replies on this, **never** on the phone number: number
     * matching breaks the first time one contact is in two flows at once, and
     * again when a shared number delivered the original.
     */
    public function messageRef(): ?string
    {
        return $this->lastMessage?->messageRef;
    }

    /**
     * Whether Kudosity could tie this reply to a message you sent.
     *
     * False means unsolicited — no correlation and no authenticity signal at
     * all. Worth branching on before trusting the content.
     */
    public function isCorrelated(): bool
    {
        return $this->lastMessage !== null;
    }
}
