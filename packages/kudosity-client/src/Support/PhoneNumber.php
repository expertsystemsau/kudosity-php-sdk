<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Support;

use InvalidArgumentException;

/**
 * Phone number formatting and validation utility.
 *
 * Handles conversion between local and E.164 international format,
 * validation of phone numbers, and sender ID validation.
 */
final class PhoneNumber
{
    /**
     * Maximum number of recipients per API call.
     */
    public const MAX_RECIPIENTS = 500;

    /**
     * Maximum length for alphanumeric sender ID.
     */
    public const MAX_SENDER_ID_LENGTH = 11;

    /**
     * Format a local phone number to E.164 international format.
     *
     * @param  string  $number  The phone number (local or international)
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 code or country name
     * @return string The formatted number in E.164 format (without +)
     *
     * @throws InvalidArgumentException If the country code is invalid
     */
    public static function toInternational(string $number, ?string $countryCode = null): string
    {
        // Remove all non-digit characters except leading +
        $cleaned = self::cleanNumber($number);

        // Need country code to convert local number
        if ($countryCode === null) {
            return $cleaned;
        }

        $dialingCode = CountryCodes::getDialingCode($countryCode);
        if ($dialingCode === null) {
            throw new InvalidArgumentException("Invalid country code: {$countryCode}");
        }

        // If already starts with the country dialing code, return as-is
        if (str_starts_with($cleaned, $dialingCode) && strlen($cleaned) >= 10) {
            return $cleaned;
        }

        // Remove single leading zero from local number (common in AU, NZ, UK, etc.)
        // Using preg_replace to only remove ONE leading zero, not all of them
        $result = preg_replace('/^0/', '', $cleaned);
        if ($result === null) {
            // preg_replace only returns null on regex error, which shouldn't happen
            // with this simple pattern, but we handle it explicitly for safety
            throw new InvalidArgumentException("Failed to process phone number: {$number}");
        }
        $cleaned = $result;

        return $dialingCode.$cleaned;
    }

    /**
     * Format multiple phone numbers (comma-separated) to international format.
     *
     * @param  string  $numbers  Comma-separated phone numbers
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 code for local numbers
     * @return string Comma-separated numbers in E.164 format
     *
     * @throws InvalidArgumentException If more than MAX_RECIPIENTS numbers provided
     */
    public static function formatMultiple(string $numbers, ?string $countryCode = null): string
    {
        $numberList = array_map('trim', explode(',', $numbers));
        $numberList = array_filter($numberList, fn ($n) => $n !== '');

        if (count($numberList) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(
                'Maximum '.self::MAX_RECIPIENTS.' recipients allowed per API call, got '.count($numberList)
            );
        }

        $formatted = array_map(
            fn ($n) => self::toInternational($n, $countryCode),
            $numberList
        );

        return implode(',', $formatted);
    }

    /**
     * Validate that a number is in E.164 international format.
     *
     * E.164 format: up to 15 digits, no leading zeros, no + prefix
     * (Kudosity expects numbers without the + prefix)
     *
     * @param  string  $number  The phone number to validate
     */
    public static function isValid(string $number): bool
    {
        $cleaned = self::cleanNumber($number);

        // Must be all digits
        if (! ctype_digit($cleaned)) {
            return false;
        }

        // E.164: 7-15 digits (minimum for valid international number)
        $length = strlen($cleaned);
        if ($length < 7 || $length > 15) {
            return false;
        }

        // Should not start with 0
        if (str_starts_with($cleaned, '0')) {
            return false;
        }

        return true;
    }

    /**
     * Validate multiple comma-separated numbers.
     *
     * @param  string  $numbers  Comma-separated phone numbers
     * @return array{valid: string[], invalid: string[]} Arrays of valid and invalid numbers
     */
    public static function validateMultiple(string $numbers): array
    {
        $numberList = array_map('trim', explode(',', $numbers));
        $numberList = array_filter($numberList, fn ($n) => $n !== '');

        $valid = [];
        $invalid = [];

        foreach ($numberList as $number) {
            $cleaned = self::cleanNumber($number);
            if (self::isValid($cleaned)) {
                $valid[] = $cleaned;
            } else {
                $invalid[] = $number;
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * Check if a number appears to be in international format.
     *
     * A number is considered international if it:
     * - Starts with a known country dialing code (from our supported list)
     * - AND has a reasonable length (10+ digits total)
     * - AND doesn't start with 0
     *
     * Note: This is a heuristic check against known dialing codes.
     * Numbers starting with unknown codes may return false even if valid.
     *
     * @param  string  $number  The phone number to check
     */
    public static function isInternational(string $number): bool
    {
        $cleaned = self::cleanNumber($number);

        // If it starts with 0, it's definitely local format
        if (str_starts_with($cleaned, '0')) {
            return false;
        }

        // Must be all digits and have reasonable length
        if (! ctype_digit($cleaned) || strlen($cleaned) < 10) {
            return false;
        }

        // Check if it starts with a known country dialing code
        // This provides stronger validation than just checking digit count
        return CountryCodes::startsWithKnownDialingCode($cleaned);
    }

    /**
     * Validate a sender ID.
     *
     * Sender ID can be:
     * - A phone number in international format (VMN)
     * - An alphanumeric string (max 11 chars, no spaces)
     *
     * @param  string  $senderId  The sender ID to validate
     */
    public static function isValidSenderId(string $senderId): bool
    {
        // Check if it's a phone number
        if (ctype_digit($senderId) && self::isValid($senderId)) {
            return true;
        }

        // Check if it's an alphanumeric sender ID
        return self::isValidAlphanumericSenderId($senderId);
    }

    /**
     * Validate an alphanumeric sender ID.
     *
     * Rules:
     * - Maximum 11 characters
     * - No spaces
     * - Alphanumeric characters only
     *
     * @param  string  $senderId  The sender ID to validate
     */
    public static function isValidAlphanumericSenderId(string $senderId): bool
    {
        // Must not be empty
        if ($senderId === '') {
            return false;
        }

        // Max 11 characters
        if (strlen($senderId) > self::MAX_SENDER_ID_LENGTH) {
            return false;
        }

        // No spaces
        if (str_contains($senderId, ' ')) {
            return false;
        }

        // Alphanumeric only (but must contain at least one letter to be alphanumeric)
        if (! preg_match('/^[a-zA-Z0-9]+$/', $senderId)) {
            return false;
        }

        return true;
    }

    /**
     * Format a sender ID to international format if it's a phone number.
     *
     * @param  string  $senderId  The sender ID
     * @param  string|null  $countryCode  Country code for local numbers
     * @return string The formatted sender ID
     */
    public static function formatSenderId(string $senderId, ?string $countryCode = null): string
    {
        // If it looks like a phone number, format it
        if (preg_match('/^[\d\s\-\+\(\)]+$/', $senderId)) {
            return self::toInternational($senderId, $countryCode);
        }

        // Return alphanumeric sender ID as-is
        return $senderId;
    }

    /**
     * Clean a phone number by removing non-digit characters.
     *
     * @param  string  $number  The phone number to clean
     * @return string The cleaned number (digits only)
     *
     * @throws InvalidArgumentException If the number cannot be processed
     */
    public static function cleanNumber(string $number): string
    {
        // Remove + prefix and all non-digit characters
        $result = preg_replace('/[^\d]/', '', $number);
        if ($result === null) {
            throw new InvalidArgumentException("Failed to clean phone number: {$number}");
        }

        return $result;
    }

    /**
     * Get the count of recipients from a comma-separated string.
     *
     * @param  string  $numbers  Comma-separated phone numbers
     */
    public static function countRecipients(string $numbers): int
    {
        $numberList = array_map('trim', explode(',', $numbers));

        return count(array_filter($numberList, fn ($n) => $n !== ''));
    }
}
