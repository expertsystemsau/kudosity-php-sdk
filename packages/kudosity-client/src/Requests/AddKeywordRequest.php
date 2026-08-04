<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\KeywordData;
use Saloon\Http\Response;

/**
 * Add a keyword to a virtual number.
 *
 * @see https://developer.transmitsms.com/#add-keyword
 */
class AddKeywordRequest extends KudosityV1Request
{
    protected ?string $forwardUrl = null;

    protected ?string $forwardEmail = null;

    protected ?int $listId = null;

    protected ?string $welcomeMessage = null;

    protected ?string $membersMessage = null;

    protected ?string $status = null;

    public function __construct(
        protected string $keyword,
        protected string $number,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('add-keyword');
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
     * Forward incoming messages to this email.
     */
    public function forwardEmail(string $email): self
    {
        $this->forwardEmail = $email;

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
     * Set the keyword status.
     *
     * @param  string  $status  'active' or 'inactive'
     */
    public function status(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'keyword' => $this->keyword,
            'number' => $this->number,
        ];

        if ($this->forwardUrl !== null) {
            $body['forward_url'] = $this->forwardUrl;
        }

        if ($this->forwardEmail !== null) {
            $body['forward_email'] = $this->forwardEmail;
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

        if ($this->status !== null) {
            $body['status'] = $this->status;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): KeywordData
    {
        return KeywordData::fromResponse($response->json());
    }
}
