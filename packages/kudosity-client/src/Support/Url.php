<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Support;

use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * URL validation utility for callback and webhook URLs.
 */
final class Url
{
    /**
     * Private/internal IP ranges that should be blocked for callback URLs.
     * These include loopback, private networks, and link-local addresses.
     *
     * @var array<string, array{start: string, end: string}>
     */
    private const PRIVATE_IP_RANGES = [
        'loopback_v4' => ['start' => '127.0.0.0', 'end' => '127.255.255.255'],
        'private_10' => ['start' => '10.0.0.0', 'end' => '10.255.255.255'],
        'private_172' => ['start' => '172.16.0.0', 'end' => '172.31.255.255'],
        'private_192' => ['start' => '192.168.0.0', 'end' => '192.168.255.255'],
        'link_local' => ['start' => '169.254.0.0', 'end' => '169.254.255.255'],
        'current_network' => ['start' => '0.0.0.0', 'end' => '0.255.255.255'],
    ];

    /**
     * Hostnames that should be blocked for callback URLs.
     *
     * @var array<int, string>
     */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'localhost.localdomain',
    ];

    /**
     * Validate that a URL is well-formed and uses HTTP/HTTPS protocol.
     *
     * @param  string  $url  The URL to validate
     * @param  string  $fieldName  The field name for error messages
     *
     * @throws ValidationException If the URL is invalid
     */
    public static function validate(string $url, string $fieldName = 'url'): void
    {
        if ($url === '') {
            throw new ValidationException(
                message: "The {$fieldName} cannot be empty",
                errorCode: 'FIELD_EMPTY'
            );
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException(
                message: "The {$fieldName} is not a valid URL: {$url}",
                errorCode: 'FIELD_INVALID'
            );
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            throw new ValidationException(
                message: "The {$fieldName} must use HTTP or HTTPS protocol: {$url}",
                errorCode: 'FIELD_INVALID'
            );
        }
    }

    /**
     * Check if a URL is valid without throwing exceptions.
     *
     * @param  string  $url  The URL to validate
     * @return bool True if the URL is valid
     */
    public static function isValid(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true);
    }

    /**
     * Validate an email address.
     *
     * @param  string  $email  The email address to validate
     * @param  string  $fieldName  The field name for error messages
     *
     * @throws ValidationException If the email is invalid
     */
    public static function validateEmail(string $email, string $fieldName = 'email'): void
    {
        if ($email === '') {
            throw new ValidationException(
                message: "The {$fieldName} cannot be empty",
                errorCode: 'FIELD_EMPTY'
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(
                message: "The {$fieldName} is not a valid email address: {$email}",
                errorCode: 'FIELD_INVALID'
            );
        }
    }

    /**
     * Check if an email address is valid without throwing exceptions.
     *
     * @param  string  $email  The email address to validate
     * @return bool True if the email is valid
     */
    public static function isValidEmail(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate a callback URL with SSRF protection.
     *
     * In addition to basic URL validation, this method rejects URLs that point to:
     * - Localhost/loopback addresses (127.0.0.0/8, ::1, localhost)
     * - Private IP ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
     * - Link-local addresses (169.254.0.0/16, including AWS metadata endpoint)
     *
     * This prevents SSRF attacks where user-controlled callback URLs could be
     * used to access internal resources.
     *
     * @param  string  $url  The callback URL to validate
     * @param  string  $fieldName  The field name for error messages
     *
     * @throws ValidationException If the URL is invalid or points to an internal resource
     */
    public static function validateCallbackUrl(string $url, string $fieldName = 'callback_url'): void
    {
        // First, perform basic URL validation
        self::validate($url, $fieldName);

        // Check for blocked hostnames and internal IPs
        if (! self::isCallbackUrlSafe($url)) {
            throw new ValidationException(
                message: "The {$fieldName} must not point to internal or private resources: {$url}",
                errorCode: 'FIELD_UNSAFE'
            );
        }
    }

    /**
     * Check if a callback URL is safe (not pointing to internal resources) without throwing exceptions.
     *
     * @param  string  $url  The URL to check
     * @return bool True if the URL is safe for use as a callback
     */
    public static function isCallbackUrlSafe(string $url): bool
    {
        // First check basic URL validity
        if (! self::isValid($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return false;
        }

        // Cast to string to handle possible empty string case
        $host = (string) $host;
        if ($host === '') {
            return false;
        }

        $hostLower = strtolower($host);

        // Check for blocked hostnames
        if (in_array($hostLower, self::BLOCKED_HOSTNAMES, true)) {
            return false;
        }

        // Check for IPv6 loopback
        if ($hostLower === '::1' || $hostLower === '[::1]') {
            return false;
        }

        // Resolve hostname to IP for checking
        // Note: This may trigger DNS lookup for domain names
        $ip = $host;

        // If it's not already an IP, try to resolve it
        // filter_var returns false for hostnames, so we check that way
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // It's a hostname, get the IP address
            $resolvedIp = gethostbyname($host);
            // gethostbyname returns the hostname unchanged if it can't resolve
            if ($resolvedIp === $host) {
                // Could not resolve - this might be acceptable in some cases
                // (e.g., the DNS might resolve later), but for safety we'll allow it
                // as we can't validate what we can't resolve
                return true;
            }
            $ip = $resolvedIp;
        }

        // Check if IP is in private ranges
        return ! self::isPrivateIp($ip);
    }

    /**
     * Check if an IP address is in a private/internal range.
     *
     * @param  string  $ip  The IP address to check
     * @return bool True if the IP is private/internal
     */
    private static function isPrivateIp(string $ip): bool
    {
        // Handle IPv6 loopback
        if ($ip === '::1') {
            return true;
        }

        // Validate as IPv4 - we only check IPv4 private ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            // For IPv6 addresses other than loopback, allow for now
            // (IPv6 private ranges could be added if needed)
            return false;
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach (self::PRIVATE_IP_RANGES as $range) {
            $startLong = ip2long($range['start']);
            $endLong = ip2long($range['end']);

            if ($startLong !== false && $endLong !== false) {
                if ($ipLong >= $startLong && $ipLong <= $endLong) {
                    return true;
                }
            }
        }

        return false;
    }
}
