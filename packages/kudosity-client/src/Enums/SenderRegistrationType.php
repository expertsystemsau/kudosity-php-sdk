<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * What kind of sender a registration is for.
 *
 * **The API currently accepts exactly one value.** Discovered by probing rather
 * than from the documentation, which does not list the field at all: posting an
 * unrecognised `type` answers
 * `{"message":"type must be one of: PERSONAL_MOBILE_NUMBER","name":"type"}`.
 *
 * That is worth understanding before reaching for this endpoint, because the name
 * is narrower than "sender registration" suggests. `POST
 * /v2/senders/registrations` registers **your own mobile number** as a sender,
 * verified by an SMS code you receive on it. It is **not** the route to an
 * alphanumeric sender ID, a WhatsApp Business sender, or an RCS agent — those go
 * through Kudosity approval and are not self-service.
 *
 * It also explains why an account can have a working number for sending and still
 * report zero registrations: a leased virtual number is not a registration. Use
 * V1's `get-numbers.json?filter=owned` — reachable as `$k->numbers()` — to see
 * those.
 *
 * Left open despite having one case, like every other V2 enum: the registry will
 * grow, and a client reading its own registrations must not break when it does.
 */
enum SenderRegistrationType: string
{
    case PersonalMobileNumber = 'PERSONAL_MOBILE_NUMBER';
    case Unknown = 'UNKNOWN';

    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }
}
