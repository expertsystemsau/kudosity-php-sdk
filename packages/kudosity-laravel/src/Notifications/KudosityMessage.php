<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Exceptions\ValidationException;

class KudosityMessage
{
    /**
     * An explicit override of the routing decision, when the caller has made one.
     */
    protected ?ApiVersion $forcedVersion = null;

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

    // =========================================================================
    // API version routing
    // =========================================================================

    /**
     * The options V2 cannot express, mapped to the builder method that sets them.
     *
     * Order is the order they are reported in, which is why it is a list rather
     * than derived from the property names: a developer reading "why did this go
     * to V1" wants the most likely culprit first.
     *
     * @return array<string, bool>
     */
    protected function v1OnlyOptions(): array
    {
        return [
            'toList()' => $this->listId !== null,
            'sendAt()' => $this->sendAt !== null,
            'validity()' => $this->validity !== null,
            'repliesToEmail()' => $this->repliesToEmail !== null,
            'dlrCallback()' => $this->dlrCallback !== null,
            'replyCallback()' => $this->replyCallback !== null,
            'linkHitsCallback()' => $this->linkHitsCallback !== null,
            // The handler forms matter as much as the raw URLs, and are easier to
            // miss: onDlr() is the idiomatic way to use this package, and it ends
            // up as a dlr_callback on the request. A message using it that routed
            // to V2 would send fine and never call the handler.
            'onDlr()' => $this->dlrHandler !== null,
            'onReply()' => $this->replyHandler !== null,
            'onLinkHit()' => $this->linkHitHandler !== null,
            'multiple recipients in to()' => $this->hasMultipleRecipients(),
        ];
    }

    /**
     * Every reason this message cannot be sent over V2.
     *
     * Empty when it can. Exposed for diagnostics — "which option pushed this to
     * V1" is a question worth being able to answer without reading the source.
     *
     * @return array<int, string>
     */
    public function v1Reasons(): array
    {
        return array_keys(array_filter($this->v1OnlyOptions()));
    }

    /**
     * Which API this message will be sent over.
     *
     * V2 by default; V1 when the message uses something V2 cannot do. Pure — safe
     * to call in a log line or a test without sending anything.
     *
     * @throws ValidationException If forceV2() was called on a message using a
     *                             V1-only option
     */
    public function apiVersion(): ApiVersion
    {
        $reasons = $this->v1Reasons();

        if ($this->forcedVersion === ApiVersion::V2 && $reasons !== []) {
            // Throwing rather than dropping the options. Silently ignoring a
            // sendAt() turns a scheduled send into an immediate one — a wrong
            // send rather than a failed one, and the kind nobody notices until a
            // customer is woken at 3am.
            throw new ValidationException(
                message: sprintf(
                    'forceV2() cannot be honoured: %s %s no V2 equivalent. Remove the option, or drop '.
                    'forceV2() and let the message route to V1.',
                    implode(', ', $reasons),
                    count($reasons) === 1 ? 'has' : 'have',
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        if ($this->forcedVersion !== null) {
            return $this->forcedVersion;
        }

        return $reasons === [] ? ApiVersion::V2 : ApiVersion::V1;
    }

    /**
     * Send this message over V1, even though V2 could carry it.
     *
     * A legitimate escape hatch — an account may depend on V1-side reporting —
     * and the last of forceV1()/forceV2() called wins, so a builder can be
     * reconfigured.
     */
    public function forceV1(): self
    {
        $this->forcedVersion = ApiVersion::V1;

        return $this;
    }

    /**
     * Require this message to go over V2.
     *
     * Combined with a V1-only option this **throws** at {@see self::apiVersion()}
     * rather than dropping the option.
     */
    public function forceV2(): self
    {
        $this->forcedVersion = ApiVersion::V2;

        return $this;
    }

    /**
     * Whether an explicit override is in force.
     */
    public function getForcedVersion(): ?ApiVersion
    {
        return $this->forcedVersion;
    }

    /**
     * Whether `to` names more than one recipient.
     *
     * `POST /v2/sms` takes exactly one. Empty segments are ignored so a trailing
     * comma or stray whitespace does not silently downgrade an otherwise-V2 send
     * — a real hazard, because both are invisible in a config value or a database
     * column.
     */
    protected function hasMultipleRecipients(): bool
    {
        if ($this->to === null) {
            return false;
        }

        $recipients = array_filter(
            array_map('trim', explode(',', $this->to)),
            static fn (string $r): bool => $r !== '',
        );

        return count($recipients) > 1;
    }
}
