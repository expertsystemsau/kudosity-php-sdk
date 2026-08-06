<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * An MMS notification, for the `kudosity-mms` channel.
 *
 * `POST /v2/mms` takes **one recipient and one media file**. Both limits are the
 * API's, and the request class enforces them — this builder does not duplicate
 * that validation, it only refuses to send nothing at all.
 *
 * V2-only: there is no V1 MMS send in this SDK, so there is no routing decision
 * here as there is for SMS.
 */
class KudosityMmsMessage
{
    protected ?string $to = null;

    protected ?string $from = null;

    protected ?string $subject = null;

    /** @var array<int, string> */
    protected array $contentUrls = [];

    protected ?string $messageRef = null;

    protected bool $trackLinks = false;

    public function __construct(
        protected ?string $content = null,
    ) {}

    public static function create(?string $content = null): self
    {
        return new self($content);
    }

    /**
     * The message text. Optional — an MMS may be media only.
     */
    public function content(string $content): self
    {
        $this->content = $content;

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
     * The subject line. The API caps this at 20 ASCII characters.
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * The media file's URL.
     *
     * Singular on purpose: `POST /v2/mms` accepts exactly one, and a method named
     * `media()` taking one argument makes that obvious where an array parameter
     * would invite passing two and failing at the API.
     */
    public function media(string $url): self
    {
        $this->contentUrls = [$url];

        return $this;
    }

    public function messageRef(string $messageRef): self
    {
        $this->messageRef = $messageRef;

        return $this;
    }

    public function trackLinks(bool $track = true): self
    {
        $this->trackLinks = $track;

        return $this;
    }

    public function getContent(): ?string
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /**
     * @return array<int, string>
     */
    public function getContentUrls(): array
    {
        return $this->contentUrls;
    }

    public function getMessageRef(): ?string
    {
        return $this->messageRef;
    }

    public function getTrackLinks(): bool
    {
        return $this->trackLinks;
    }

    /**
     * @throws ValidationException If no media file was set
     */
    public function assertSendable(): void
    {
        if ($this->contentUrls === []) {
            throw new ValidationException(
                message: 'An MMS needs a media file — call media() with an absolute URL.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }
}
