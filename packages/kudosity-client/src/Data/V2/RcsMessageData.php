<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\ParsesV2Timestamps;
use ExpertSystems\Kudosity\Contracts\SentMessage;
use ExpertSystems\Kudosity\Enums\MessageStatus;

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
final readonly class RcsMessageData implements SentMessage
{
    use ParsesV2Timestamps;

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
            createdAt: self::parseTimestamp($data['created_at'] ?? null),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Always 1: `POST /v2/rcs` takes exactly one recipient.
     */
    public function recipientCount(): int
    {
        return 1;
    }

    /**
     * The status at send time.
     *
     * Nullable here because the API omits a status on some reads;
     * {@see SentMessage::status()} allows exactly that.
     */
    public function status(): ?MessageStatus
    {
        return $this->status;
    }
}
