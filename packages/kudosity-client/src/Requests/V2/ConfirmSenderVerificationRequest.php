<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use Saloon\Enums\Method;

/**
 * Confirm a registration with the code that was sent to it.
 *
 * Schema read from the API's own validation error: `POST` with `{}` answers
 * `issues: [code is required]`. That single field is the whole body.
 *
 * `code` is a string, not an int, on purpose — verification codes have leading
 * zeros, and `012345` as an integer is `12345` or an octal literal depending on
 * how it reaches you.
 */
class ConfirmSenderVerificationRequest extends KudosityV2BodyRequest
{
    protected Method $method = Method::POST;

    /**
     * @throws ValidationException If the registration id or code is empty
     */
    public function __construct(
        protected string $registrationId,
        protected string $code,
    ) {
        if ($registrationId === '') {
            throw new ValidationException(
                message: 'A registration id is required to confirm verification.',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if ($code === '') {
            throw new ValidationException(
                message: 'code is required.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/senders/registrations/'.$this->registrationId.'/verifications/confirmation';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['code' => $this->code];
    }
}
