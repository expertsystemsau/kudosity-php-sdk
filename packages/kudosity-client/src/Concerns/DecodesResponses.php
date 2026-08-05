<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use JsonException;
use Saloon\Http\Response;
use TypeError;

/**
 * Decode a response body to an array without ever throwing.
 *
 * `Saloon\Http\Response::json()` decodes with `JSON_THROW_ON_ERROR` into a
 * non-nullable `array` property, so a response body that isn't valid JSON
 * (an HTML error page from a proxy or load balancer) throws `JsonException`,
 * and a body that decodes to anything other than a JSON object or array
 * (a literal `null`, a bare string or number) throws `TypeError` when
 * Saloon tries to assign it. Both are exactly the shapes a 5xx is likely to
 * arrive in — the one time this code needs to build a message from the body
 * rather than crash building it. Once past the try/catch, the result is
 * always an array — Saloon's `json()` cannot return anything else without
 * having already thrown.
 *
 * Shared by {@see KudosityException}'s
 * error factories and {@see UnwrapsData}, the two places that read a
 * response body speculatively rather than after a successful decode is
 * already known.
 */
trait DecodesResponses
{
    /**
     * @return array<string, mixed>
     */
    protected static function decode(Response $response): array
    {
        try {
            return $response->json();
        } catch (JsonException|TypeError) {
            return [];
        }
    }
}
