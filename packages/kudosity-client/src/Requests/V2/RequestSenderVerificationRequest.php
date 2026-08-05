<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Enums\SenderVerificationMethod;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use ExpertSystems\Kudosity\Resources\SendersResource;
use Saloon\Enums\Method;

/**
 * Ask Kudosity to send a verification code for a registration.
 *
 * Schema read from the API's own validation errors rather than guessed: `POST`
 * with `{}` answers
 * `issues: [method is required, originating_sender is required]`, and an
 * unrecognised method answers `method must be one of: SMS`.
 *
 * `originating_sender` is the number the code is sent **from** — one already
 * usable on the account, not the number being registered. That reading follows
 * from the name and from the fact that the registered number cannot send until
 * this flow completes; it is the one field here whose meaning is inferred rather
 * than stated, so it is worth confirming against a real run.
 *
 * No DTO: the success shape has never been observed, and inventing one is exactly
 * what {@see SendersResource} avoids. The
 * resource returns the decoded body.
 */
class RequestSenderVerificationRequest extends KudosityV2BodyRequest
{
    protected Method $method = Method::POST;

    /**
     * @throws ValidationException If the registration id or originating sender is empty
     */
    public function __construct(
        protected string $registrationId,
        protected string $originatingSender,
        protected SenderVerificationMethod $verificationMethod = SenderVerificationMethod::Sms,
    ) {
        if ($registrationId === '') {
            throw new ValidationException(
                message: 'A registration id is required to request verification.',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if ($originatingSender === '') {
            throw new ValidationException(
                message: 'originating_sender is required — the number the verification code is sent from.',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if ($verificationMethod === SenderVerificationMethod::Unknown) {
            throw new ValidationException(
                message: sprintf('method must be one of: %s.', SenderVerificationMethod::Sms->value),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/senders/registrations/'.$this->registrationId.'/verifications';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'method' => $this->verificationMethod->value,
            'originating_sender' => $this->originatingSender,
        ];
    }
}
