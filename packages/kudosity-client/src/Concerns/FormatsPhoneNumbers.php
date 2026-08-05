<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use ExpertSystems\Kudosity\Support\PhoneNumber;

/**
 * Offline phone-number helpers, available wherever you are sending from.
 *
 * These do no I/O. The API-backed formatter lives on the numbers resource,
 * because it is a V1 endpoint call rather than a local utility.
 *
 * Requires the using class to expose a `$connector` with
 * `getDefaultCountryCode()`.
 */
trait FormatsPhoneNumbers
{
    /**
     * Format a number to E.164 locally, without an API call.
     */
    public function formatNumberLocal(string $number, ?string $countryCode = null): string
    {
        return PhoneNumber::toInternational($number, $countryCode ?? $this->connector->getDefaultCountryCode());
    }

    public function isValidNumber(string $number): bool
    {
        return PhoneNumber::isValid($number);
    }

    /**
     * @param  string  $numbers  Comma-separated numbers
     * @return array{valid: string[], invalid: string[]}
     */
    public function validateNumbers(string $numbers): array
    {
        return PhoneNumber::validateMultiple($numbers);
    }

    public function isValidSenderId(string $senderId): bool
    {
        return PhoneNumber::isValidSenderId($senderId);
    }
}
