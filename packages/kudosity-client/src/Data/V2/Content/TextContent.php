<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2\Content;

use ExpertSystems\Kudosity\Contracts\WhatsAppContent;

/**
 * Free-form WhatsApp text — `content_type: "text"`.
 *
 * Only deliverable inside the 24-hour service window, which opens when the
 * recipient messages you and closes 24 hours later. Outside it, only a
 * pre-approved template delivers, and the send fails platform-side rather than
 * with a 400 — so an integration built on this shape "works in testing" and
 * fails in production. Use {@see TemplateContent} to start a conversation.
 */
final readonly class TextContent implements WhatsAppContent
{
    public function __construct(
        public string $message,
    ) {}

    public function contentType(): string
    {
        return 'text';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['text' => ['message' => $this->message]];
    }
}
