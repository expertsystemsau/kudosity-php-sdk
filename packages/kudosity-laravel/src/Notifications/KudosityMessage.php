<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

class KudosityMessage
{
    protected string $content;

    protected ?string $to = null;

    protected ?int $listId = null;

    protected ?string $from = null;

    protected ?string $sendAt = null;

    protected ?int $validity = null;

    protected ?string $countryCode = null;

    protected bool $formatNumbers = false;

    protected ?string $repliesToEmail = null;

    protected ?string $trackedLinkUrl = null;

    protected ?string $dlrCallback = null;

    protected ?string $replyCallback = null;

    protected ?string $linkHitsCallback = null;

    /**
     * Handler class for DLR callbacks.
     *
     * @var class-string|null
     */
    protected ?string $dlrHandler = null;

    /**
     * Context data for DLR handler.
     *
     * @var array<string, mixed>
     */
    protected array $dlrContext = [];

    /**
     * Handler class for Reply callbacks.
     *
     * @var class-string|null
     */
    protected ?string $replyHandler = null;

    /**
     * Context data for Reply handler.
     *
     * @var array<string, mixed>
     */
    protected array $replyContext = [];

    /**
     * Handler class for Link Hit callbacks.
     *
     * @var class-string|null
     */
    protected ?string $linkHitHandler = null;

    /**
     * Context data for Link Hit handler.
     *
     * @var array<string, mixed>
     */
    protected array $linkHitContext = [];

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    /**
     * Create a new message instance.
     */
    public static function create(string $content = ''): self
    {
        return new self($content);
    }

    /**
     * Set the message content.
     */
    public function content(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Set the recipient phone number.
     */
    public function to(string $to): self
    {
        $this->to = $to;

        return $this;
    }

    /**
     * Send to a Kudosity contact list instead of an individual recipient.
     *
     * When set, the notifiable's resolved phone number is ignored and the
     * message is sent to every contact on the list.
     */
    public function toList(int $listId): self
    {
        $this->listId = $listId;

        return $this;
    }

    /**
     * Set the sender ID.
     */
    public function from(string $from): self
    {
        $this->from = $from;

        return $this;
    }

    /**
     * Schedule the message to be sent at a specific time.
     */
    public function sendAt(string $sendAt): self
    {
        $this->sendAt = $sendAt;

        return $this;
    }

    /**
     * Set the message validity period in minutes.
     */
    public function validity(int $validity): self
    {
        $this->validity = $validity;

        return $this;
    }

    /**
     * Set the country code for the recipient number.
     */
    public function countryCode(string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    /**
     * Enable local formatting of phone numbers to E.164 before sending.
     *
     * Requires a country code to be set (via countryCode() or the connector
     * default). When enabled, numbers are normalised client-side rather than
     * relying on the API's countrycode parameter.
     */
    public function formatNumbers(bool $format = true): self
    {
        $this->formatNumbers = $format;

        return $this;
    }

    /**
     * Set the email address to receive replies.
     */
    public function repliesToEmail(string $repliesToEmail): self
    {
        $this->repliesToEmail = $repliesToEmail;

        return $this;
    }

    /**
     * Set the URL to track link clicks.
     */
    public function trackedLinkUrl(string $trackedLinkUrl): self
    {
        $this->trackedLinkUrl = $trackedLinkUrl;

        return $this;
    }

    /**
     * Set the delivery receipt callback URL.
     *
     * Note: If you use onDlr() to register a handler, the URL will be
     * automatically generated and this value will be ignored.
     */
    public function dlrCallback(string $dlrCallback): self
    {
        $this->dlrCallback = $dlrCallback;

        return $this;
    }

    /**
     * Set the reply callback URL.
     *
     * Note: If you use onReply() to register a handler, the URL will be
     * automatically generated and this value will be ignored.
     */
    public function replyCallback(string $replyCallback): self
    {
        $this->replyCallback = $replyCallback;

        return $this;
    }

    /**
     * Set the link hits callback URL.
     *
     * Note: If you use onLinkHit() to register a handler, the URL will be
     * automatically generated and this value will be ignored.
     */
    public function linkHitsCallback(string $linkHitsCallback): self
    {
        $this->linkHitsCallback = $linkHitsCallback;

        return $this;
    }

    /**
     * Register a job to handle DLR (Delivery Receipt) callbacks.
     *
     * When the delivery receipt is received from Kudosity, the specified
     * job will be dispatched with the DLR data and context.
     *
     * Example:
     * ```php
     * KudosityMessage::create('Your order has shipped!')
     *     ->onDlr(UpdateOrderStatusJob::class, ['order_id' => 123]);
     * ```
     *
     * @param  class-string  $handler  Job class implementing HandlesDlrCallback
     * @param  array<string, mixed>  $context  Context data to pass to the job
     */
    public function onDlr(string $handler, array $context = []): self
    {
        $this->dlrHandler = $handler;
        $this->dlrContext = $context;

        return $this;
    }

    /**
     * Register a job to handle Reply callbacks.
     *
     * When a reply is received from the recipient, the specified job will
     * be dispatched with the reply data and context.
     *
     * Example:
     * ```php
     * KudosityMessage::create('Reply YES to confirm')
     *     ->onReply(ProcessReplyJob::class, ['order_id' => 123]);
     * ```
     *
     * @param  class-string  $handler  Job class implementing HandlesReplyCallback
     * @param  array<string, mixed>  $context  Context data to pass to the job
     */
    public function onReply(string $handler, array $context = []): self
    {
        $this->replyHandler = $handler;
        $this->replyContext = $context;

        return $this;
    }

    /**
     * Register a job to handle Link Hit callbacks.
     *
     * When the recipient clicks a tracked link in the message, the specified
     * job will be dispatched with the link hit data and context.
     *
     * Example:
     * ```php
     * KudosityMessage::create('Check out our sale: [tracked-link]')
     *     ->trackedLinkUrl('https://example.com/sale')
     *     ->onLinkHit(TrackClickJob::class, ['campaign_id' => 456]);
     * ```
     *
     * @param  class-string  $handler  Job class implementing HandlesLinkHitCallback
     * @param  array<string, mixed>  $context  Context data to pass to the job
     */
    public function onLinkHit(string $handler, array $context = []): self
    {
        $this->linkHitHandler = $handler;
        $this->linkHitContext = $context;

        return $this;
    }

    /**
     * Get the message content.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get the recipient phone number.
     */
    public function getTo(): ?string
    {
        return $this->to;
    }

    /**
     * Get the recipient list ID.
     */
    public function getListId(): ?int
    {
        return $this->listId;
    }

    /**
     * Get the sender ID.
     */
    public function getFrom(): ?string
    {
        return $this->from;
    }

    /**
     * Get the scheduled send time.
     */
    public function getSendAt(): ?string
    {
        return $this->sendAt;
    }

    /**
     * Get the message validity period in minutes.
     */
    public function getValidity(): ?int
    {
        return $this->validity;
    }

    /**
     * Get the country code.
     */
    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    /**
     * Whether local E.164 number formatting is enabled.
     */
    public function getFormatNumbers(): bool
    {
        return $this->formatNumbers;
    }

    /**
     * Get the email address to receive replies.
     */
    public function getRepliesToEmail(): ?string
    {
        return $this->repliesToEmail;
    }

    /**
     * Get the tracked link URL.
     */
    public function getTrackedLinkUrl(): ?string
    {
        return $this->trackedLinkUrl;
    }

    /**
     * Get the delivery receipt callback URL.
     */
    public function getDlrCallback(): ?string
    {
        return $this->dlrCallback;
    }

    /**
     * Get the reply callback URL.
     */
    public function getReplyCallback(): ?string
    {
        return $this->replyCallback;
    }

    /**
     * Get the link hits callback URL.
     */
    public function getLinkHitsCallback(): ?string
    {
        return $this->linkHitsCallback;
    }

    /**
     * Get the DLR handler class.
     *
     * @return class-string|null
     */
    public function getDlrHandler(): ?string
    {
        return $this->dlrHandler;
    }

    /**
     * Get the DLR handler context.
     *
     * @return array<string, mixed>
     */
    public function getDlrContext(): array
    {
        return $this->dlrContext;
    }

    /**
     * Get the Reply handler class.
     *
     * @return class-string|null
     */
    public function getReplyHandler(): ?string
    {
        return $this->replyHandler;
    }

    /**
     * Get the Reply handler context.
     *
     * @return array<string, mixed>
     */
    public function getReplyContext(): array
    {
        return $this->replyContext;
    }

    /**
     * Get the Link Hit handler class.
     *
     * @return class-string|null
     */
    public function getLinkHitHandler(): ?string
    {
        return $this->linkHitHandler;
    }

    /**
     * Get the Link Hit handler context.
     *
     * @return array<string, mixed>
     */
    public function getLinkHitContext(): array
    {
        return $this->linkHitContext;
    }

    /**
     * Check if any callback handlers are configured.
     */
    public function hasCallbackHandlers(): bool
    {
        return $this->dlrHandler !== null
            || $this->replyHandler !== null
            || $this->linkHitHandler !== null;
    }
}
