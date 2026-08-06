<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Data\V2\Content\CustomContent;
use ExpertSystems\Kudosity\Data\V2\Content\TemplateContent;
use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * A WhatsApp notification, for the `kudosity-whatsapp` channel.
 *
 * ## Free-form text only reaches people inside the 24-hour service window
 *
 * WhatsApp permits free-form text only within 24 hours of the customer's last
 * message to you. Outside that window a `text()` send is rejected or silently
 * undelivered, and **a template is the only way to initiate a conversation.**
 *
 * Nothing in this SDK can detect which side of the window you are on — that
 * depends on inbound history Kudosity does not expose here. So if the
 * notification might be the first contact, use {@see self::template()}.
 *
 * The content variants are mutually exclusive and the last one set wins, which is
 * why they share one property rather than three: `content_type` and `content` must
 * agree on the wire, and Phase 3 made that impossible to get wrong by making the
 * variant an object rather than two fields.
 */
class KudosityWhatsAppMessage
{
    protected ?WhatsAppContent $content = null;

    protected ?string $to = null;

    protected ?string $from = null;

    protected ?SmsFallback $fallback = null;

    protected ?string $messageRef = null;

    public static function create(): self
    {
        return new self;
    }

    /**
     * Free-form text. **Only delivers inside the 24-hour service window** — see
     * the class docblock.
     */
    public function text(string $message): self
    {
        $this->content = new TextContent($message);

        return $this;
    }

    /**
     * A pre-approved template, which is the only way to start a conversation.
     *
     * Parameters are **positional**, matching `{{1}}`, `{{2}}` in the approved
     * body, and must be strings — a non-string is rejected rather than stringified,
     * because a silently-cast value produces a message the template was never
     * approved for.
     *
     * @param  array<int, mixed>  $parameters
     *
     * @throws ValidationException If the template name or a parameter is invalid
     */
    public function template(string $name, array $parameters = [], ?string $locale = null): self
    {
        $this->content = new TemplateContent($name, $parameters, $locale);

        return $this;
    }

    /**
     * A raw content payload, for media headers, buttons and anything the simple
     * shapes cannot express.
     *
     * @param  array<string, mixed>  $payload
     */
    public function custom(array $payload): self
    {
        $this->content = new CustomContent($payload);

        return $this;
    }

    public function to(string $to): self
    {
        $this->to = $to;

        return $this;
    }

    public function from(string $from): self
    {
        $this->from = $from;

        return $this;
    }

    /**
     * Fall back to SMS if WhatsApp cannot deliver.
     *
     * The fallback body is a separate message, not a copy: it is sent over SMS, so
     * it cannot carry media or buttons.
     */
    public function smsFallback(string $message, ?string $sender = null): self
    {
        $this->fallback = new SmsFallback($message, $sender);

        return $this;
    }

    public function messageRef(string $messageRef): self
    {
        $this->messageRef = $messageRef;

        return $this;
    }

    public function getContent(): ?WhatsAppContent
    {
        return $this->content;
    }

    public function getTo(): ?string
    {
        return $this->to;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    public function getFallback(): ?SmsFallback
    {
        return $this->fallback;
    }

    public function getMessageRef(): ?string
    {
        return $this->messageRef;
    }

    /**
     * @throws ValidationException If no content variant was chosen
     */
    public function assertSendable(): void
    {
        if ($this->content === null) {
            throw new ValidationException(
                message: 'A WhatsApp message needs content — call text(), template() or custom(). '.
                    'Use template() if this might be the first message in the conversation, because '.
                    'free-form text only delivers inside the 24-hour service window.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }
}
