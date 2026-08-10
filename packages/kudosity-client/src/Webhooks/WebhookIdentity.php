<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

/**
 * The comparison key for "is this registration ours".
 *
 * Scheme, host and path — **never the query string**, which is where the HMAC
 * signature lives. That exclusion is the whole point: rotating the signing key
 * changes the query and nothing else, and a registration whose signature no
 * longer verifies is precisely the one that needs repairing rather than
 * duplicating.
 *
 * Host and scheme are case-insensitive per RFC 3986; the path is not, and is
 * left alone.
 */
final class WebhookIdentity
{
    /**
     * The default port for each scheme, dropped when stated explicitly.
     *
     * `https://a.com:443/x` and `https://a.com/x` are the same endpoint, and
     * treating them as two would register a duplicate.
     */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Normalise a receiver URL to its identity.
     *
     * An unparseable URL falls back to itself, unchanged. Registrations made by
     * other tools can hold anything, and a row that cannot be parsed should
     * simply never match ours — leaving it untouched is safer than guessing.
     */
    public static function of(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $identity = $scheme.'://'.strtolower($parts['host']);

        $port = $parts['port'] ?? null;

        if ($port !== null && $port !== (self::DEFAULT_PORTS[$scheme] ?? null)) {
            $identity .= ':'.$port;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return $identity.($path === '' ? '/' : $path);
    }
}
