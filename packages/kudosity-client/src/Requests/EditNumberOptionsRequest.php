<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Edit options for a virtual number.
 *
 * @see https://developer.transmitsms.com/#edit-number-options
 */
class EditNumberOptionsRequest extends KudosityV1Request
{
    protected ?string $forwardEmail = null;

    protected ?string $forwardUrl = null;

    protected ?int $listId = null;

    protected ?string $welcomeMessage = null;

    protected ?string $membersMessage = null;

    public function __construct(
        protected string $number,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('edit-number-options');
    }

    /**
     * Forward incoming messages to this email.
     */
    public function forwardEmail(string $email): self
    {
        $this->forwardEmail = $email;

        return $this;
    }

    /**
     * Forward incoming messages to this URL.
     */
    public function forwardUrl(string $url): self
    {
        $this->forwardUrl = $url;

        return $this;
    }

    /**
     * Add incoming contacts to this list.
     */
    public function listId(int $listId): self
    {
        $this->listId = $listId;

        return $this;
    }

    /**
     * Send this message to new contacts.
     */
    public function welcomeMessage(string $message): self
    {
        $this->welcomeMessage = $message;

        return $this;
    }

    /**
     * Send this message to existing contacts.
     */
    public function membersMessage(string $message): self
    {
        $this->membersMessage = $message;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'number' => $this->number,
        ];

        if ($this->forwardEmail !== null) {
            $body['forward_email'] = $this->forwardEmail;
        }

        if ($this->forwardUrl !== null) {
            $body['forward_url'] = $this->forwardUrl;
        }

        if ($this->listId !== null) {
            $body['list_id'] = $this->listId;
        }

        if ($this->welcomeMessage !== null) {
            $body['welcome_message'] = $this->welcomeMessage;
        }

        if ($this->membersMessage !== null) {
            $body['members_message'] = $this->membersMessage;
        }

        return $body;
    }
}
