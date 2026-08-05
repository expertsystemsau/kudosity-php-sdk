<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Concerns;

use Saloon\Http\Response;

/**
 * Resolves the V2 API's two envelope shapes to a single payload array.
 *
 * SMS and MMS return their object flat. WhatsApp, RCS, RCS capabilities and
 * sender registrations wrap it: `{"data": {...}, "request": {}, "meta": {}}`.
 * Code written against one shape and reused for the other reads null, which
 * is the most common way to misread this API — so every DTO factory resolves
 * the payload through here rather than reaching into `json()` directly.
 *
 * Per-endpoint shapes are tabulated in the client package README.
 */
trait UnwrapsData
{
    use DecodesResponses;

    /**
     * Resolve the payload of a response, whichever envelope it used.
     *
     * @return array<string, mixed>
     */
    protected static function payload(Response $response): array
    {
        return static::payloadFrom(self::decode($response));
    }

    /**
     * Resolve the payload of an already-decoded body.
     *
     * A `data` key holding anything other than an array is left alone: that is
     * a flat payload whose own field happens to be called `data`, not an
     * envelope.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    protected static function payloadFrom(array $json): array
    {
        return isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
    }
}
