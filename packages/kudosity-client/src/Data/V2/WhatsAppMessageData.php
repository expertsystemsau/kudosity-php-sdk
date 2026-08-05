<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use Throwable;

/**
 * A single WhatsApp message, as returned by `POST /v2/whatsapp/messages`,
 * `GET /v2/whatsapp/messages/{id}` and the list endpoint.
 *
 * Unlike {@see SmsMessageData} and {@see MmsMessageData}, the WhatsApp response
 * is wrapped in a `data` envelope — the request resolves that before calling
 * `fromArray()`, so this sees the inner object either way.
 *
 * `$status` is nullable because the documented send response carries no status
 * field at all; a non-nullable status would have to invent one for the reply to
 * a send.
 */
final readonly class WhatsAppMessageData
{
    /**
     * @param  array<string, mixed>  $content  The `content` object exactly as returned.
     *                                         The response echoes whichever variant was
     *                                         sent, so it is kept raw rather than parsed
     *                                         back into a {@see WhatsAppContent}.
     */
    public function __construct(
        public string $id,
        public ?string $messageRef,
        public ?string $campaignId,
        public ?string $sender,
        public string $recipient,
        public string $contentType,
        public array $content,
        public ?MessageStatus $status,
        public ?SmsFallback $smsFallback,
        public ?DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $content */
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];

        $status = $data['status'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            messageRef: is_string($data['message_ref'] ?? null) ? $data['message_ref'] : null,
            campaignId: is_string($data['campaign_id'] ?? null) ? $data['campaign_id'] : null,
            sender: is_string($data['sender'] ?? null) ? $data['sender'] : null,
            recipient: (string) ($data['recipient'] ?? ''),
            contentType: (string) ($data['content_type'] ?? ''),
            content: $content,
            // Absent stays absent: MessageStatus::fromApi() would answer
            // Unknown, which reads as "the API sent a status we do not
            // recognise" rather than "the API sent no status".
            status: is_string($status) && $status !== '' ? MessageStatus::fromApi($status) : null,
            smsFallback: self::parseFallback($data['sms_fallback'] ?? null),
            createdAt: self::parseDate($data['created_at'] ?? null),
        );
    }

    /**
     * Build a fallback only when the response really carries one.
     *
     * {@see SmsFallback} rejects an empty message, which is right for the
     * request-shaped object it primarily is — a fallback with no body is not a
     * fallback. A response is not ours to police, though, so rather than
     * loosening that invariant this returns null when the message is missing or
     * empty, instead of throwing part-way through reading a message back.
     */
    private static function parseFallback(mixed $value): ?SmsFallback
    {
        if (! is_array($value)) {
            return null;
        }

        $message = $value['message'] ?? null;

        if (! is_string($message) || $message === '') {
            return null;
        }

        return new SmsFallback(
            message: $message,
            sender: is_string($value['sender'] ?? null) ? $value['sender'] : null,
        );
    }

    /**
     * Parse a timestamp permissively.
     *
     * The API sends nine fractional digits, which
     * `DateTimeImmutable::createFromFormat(RFC3339_EXTENDED, ...)` cannot parse
     * — it expects exactly six. `new DateTimeImmutable()` accepts it because
     * PHP's own parser truncates fractional seconds.
     */
    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
