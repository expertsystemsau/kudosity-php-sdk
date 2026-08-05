<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\SenderRegistrationData;
use ExpertSystems\Kudosity\Enums\SenderRegistrationType;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Register a sender.
 *
 * ## Where this request's shape came from
 *
 * The vendored skill documents the endpoint but **not its request body**, and
 * inventing wire field names is how a call that looks successful does nothing —
 * an unsupported parameter is silently ignored. So the schema was not guessed: it
 * was read out of the API's own validation errors, which name each field.
 *
 * - `POST` with `{}` answers
 *   `issues: [sender is required, country is required, type is required]`.
 * - An unrecognised `type` answers
 *   `type must be one of: PERSONAL_MOBILE_NUMBER`.
 *
 * Those three fields, and that single permitted type, are the whole documented
 * surface. Anything optional the endpoint may also accept is not represented,
 * because nothing has revealed it.
 *
 * ## What this actually registers
 *
 * **Your own mobile number, verified by an SMS code sent to it.** Not an
 * alphanumeric sender ID, not a WhatsApp Business sender, not an RCS agent —
 * those need Kudosity approval and are not self-service. See
 * {@see SenderRegistrationType}.
 *
 * Registration alone does not produce a usable sender. The flow is: register,
 * then {@see RequestSenderVerificationRequest}, then
 * {@see ConfirmSenderVerificationRequest}, and only once the status reaches
 * `READY_TO_USE` can it send.
 *
 * **Not live-verified end to end**, and deliberately so: completing it registers
 * a real personal number and sends a real code to it. The request shape is
 * evidence-based; the success response is not, so
 * {@see SenderRegistrationData} reads every field defensively and keeps `raw`.
 */
class RegisterSenderRequest extends KudosityV2BodyRequest
{
    protected Method $method = Method::POST;

    /**
     * @param  string  $sender  The number to register, in E.164.
     * @param  string  $country  ISO 3166-1 alpha-2, e.g. `AU`.
     *
     * @throws ValidationException If sender or country is empty
     */
    public function __construct(
        protected string $sender,
        protected string $country,
        protected SenderRegistrationType $type = SenderRegistrationType::PersonalMobileNumber,
    ) {
        if ($sender === '') {
            throw new ValidationException(
                message: 'sender is required.',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if ($country === '') {
            throw new ValidationException(
                message: 'country is required; use an ISO 3166-1 alpha-2 code such as "AU".',
                errorCode: 'FIELD_EMPTY',
            );
        }

        // Unknown is the enum's landing pad for values the API has not published,
        // which makes it fine to read but never right to send.
        if ($type === SenderRegistrationType::Unknown) {
            throw new ValidationException(
                message: sprintf(
                    'type must be one of: %s.',
                    SenderRegistrationType::PersonalMobileNumber->value,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/senders/registrations';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'sender' => $this->sender,
            'country' => $this->country,
            'type' => $this->type->value,
        ];
    }

    public function createDtoFromResponse(Response $response): SenderRegistrationData
    {
        return SenderRegistrationData::fromArray(static::payload($response));
    }
}
