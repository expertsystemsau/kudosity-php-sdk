<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;

/**
 * Remove a registered phone number from the account.
 *
 * Keyed on the **number itself**, not a registration id — the only endpoint in
 * this family that is. Confirmed live: a `DELETE` against an unregistered number
 * answers 404 with `{"detail":"sender not found"}` in RFC 9457 form, so the
 * endpoint exists and the number is the identifier.
 *
 * Extends the plain V2 base: a DELETE with a body is stripped or rejected by some
 * gateways.
 */
class DeleteSenderPhoneNumberRequest extends KudosityV2Request
{
    protected Method $method = Method::DELETE;

    /**
     * @throws ValidationException If the number is empty
     */
    public function __construct(
        protected string $phoneNumber,
    ) {
        if ($phoneNumber === '') {
            throw new ValidationException(
                message: 'A phone number is required to delete a sender.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        // rawurlencode, not raw interpolation: a `+`-prefixed E.164 number in a
        // path segment would otherwise be read as a space.
        return '/v2/senders/phone-numbers/'.rawurlencode($this->phoneNumber);
    }
}
