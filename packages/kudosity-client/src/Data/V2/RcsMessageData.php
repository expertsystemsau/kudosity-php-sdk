<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use Throwable;

/**
 * A single RCS message, as returned by `POST /v2/rcs/messages`,
 * `GET /v2/rcs/messages/{id}` and the list endpoint.
 *
 * Like {@see WhatsAppMessageData}, the RCS response is wrapped in a `data`
 * envelope — the request resolves that before calling `fromArray()`, so this
 * sees the inner object either way.
 *
 * `$status` is nullable because the documented send response carries no
 * status field at all; a non-nullable status would have to invent one for the
 * reply to a send.
 */
final readonly class RcsMessageData
{
    /**
     * @param  array<string, mixed>  $content  The `content` object exactly as returned.
     *                                         Kept raw rather than parsed back into a
     *                                         request-side shape, since the response
     *                                         echoes whichever variant was sent.
     */
    public function __construct(
        public string $id,
        public ?string $messageRef,
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

        /** @var array<string, mixed>|null $fallback */
        $fallback = is_array($data['sms_fallback'] ?? null) ? $data['sms_fallback'] : null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            messageRef: is_string($data['message_ref'] ?? null) ? $data['message_ref'] : null,
            sender: is_string($data['sender'] ?? null) ? $data['sender'] : null,
            recipient: (string) ($data['recipient'] ?? ''),
            contentType: (string) ($data['content_type'] ?? ''),
            content: $content,
            // Absent stays absent: MessageStatus::fromApi() would answer
            // Unknown, which reads as "the API sent a status we do not
            // recognise" rather than "the API sent no status".
            status: is_string($status) && $status !== '' ? MessageStatus::fromApi($status) : null,
            // fromResponse(), not fromArray(): the read path returns null
            // rather than throwing on a fallback with no message. See
            // SmsFallback for why the invariant stays and what that
            // trade-off costs.
            smsFallback: $fallback !== null ? SmsFallback::fromResponse($fallback) : null,
            createdAt: self::parseDate($data['created_at'] ?? null),
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
