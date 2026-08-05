<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * How a sender registration's verification code is delivered.
 *
 * **The API currently accepts exactly one value.** Discovered by probing, not
 * documented: posting an unrecognised `method` answers
 * `{"message":"method must be one of: SMS","name":"method"}`.
 *
 * A voice-call option is the obvious future addition, which is why this is an
 * open enum rather than a bool.
 */
enum SenderVerificationMethod: string
{
    case Sms = 'SMS';
    case Unknown = 'UNKNOWN';

    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }
}
