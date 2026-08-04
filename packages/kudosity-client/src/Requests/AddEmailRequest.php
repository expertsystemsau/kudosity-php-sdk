<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\EmailSmsData;
use Saloon\Http\Response;

/**
 * Authorize an email address for Email SMS.
 *
 * @see https://developers.kudosity.com
 */
class AddEmailRequest extends KudosityV1Request
{
    protected ?int $maxSms = null;

    protected ?string $number = null;

    public function __construct(
        protected string $email,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('add-email');
    }

    /**
     * Set the maximum SMS allowed per email.
     */
    public function maxSms(int $maxSms): self
    {
        $this->maxSms = $maxSms;

        return $this;
    }

    /**
     * Set the sender number for this email.
     */
    public function number(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'email' => $this->email,
        ];

        if ($this->maxSms !== null) {
            $body['max_sms'] = $this->maxSms;
        }

        if ($this->number !== null) {
            $body['number'] = $this->number;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): EmailSmsData
    {
        return EmailSmsData::fromResponse($response->json());
    }
}
