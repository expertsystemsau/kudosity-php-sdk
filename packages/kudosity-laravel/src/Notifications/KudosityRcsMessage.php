<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * An RCS notification, for the `kudosity-rcs` channel.
 *
 * **The sender is a registered agent ID, not a phone number.** `agentId()` is
 * named for that reason, and passing a phone-number-shaped value is rejected
 * before the request leaves the process — the guard lives on `SendRcsRequest`, so
 * it fires whether the value came from here or from config.
 *
 * RCS also does not reach every handset. `smsFallback()` is what gives a delivery
 * guarantee; a capability check is best-effort and goes stale.
 */
class KudosityRcsMessage
{
    protected ?string $to = null;

    protected ?string $agentId = null;

    protected ?SmsFallback $fallback = null;

    protected ?string $messageRef = null;

    public function __construct(
        protected string $content = '',
    ) {}

    public static function create(string $content = ''): self
    {
        return new self($content);
    }

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

    /**
     * The registered RCS agent ID — **not** a phone number.
     */
    public function agentId(string $agentId): self
    {
        $this->agentId = $agentId;

        return $this;
    }

    /**
     * Fall back to SMS for handsets that cannot receive RCS.
     *
     * This, not a capability check, is what makes delivery reliable.
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

    public function getContent(): string
    {
        return $this->content;
    }

    public function getTo(): ?string
    {
        return $this->to;
    }

    public function getAgentId(): ?string
    {
        return $this->agentId;
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
     * @throws ValidationException If no agent ID is available from the message or config
     */
    public function assertSendable(?string $configuredAgentId): void
    {
        if (($this->agentId ?? $configuredAgentId) === null || ($this->agentId ?? $configuredAgentId) === '') {
            throw new ValidationException(
                message: 'An RCS send needs a registered agent ID. Set one with agentId(), or configure '.
                    'kudosity.rcs.agent_id. It is an agent ID, not a phone number.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }
}
