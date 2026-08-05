<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * List every webhook registered to the account.
 *
 * Items arrive under a named collection key, `webhooks` — the same shape as
 * SMS's `smses` rather than a bare array.
 *
 * **When there are none, the response is `{}`** — the key is omitted entirely
 * rather than sent as an empty array. Confirmed live, twice. Anything indexing
 * into `webhooks` without checking gets a warning and a null; this class returns
 * an empty array for that case.
 *
 * Deliberately **not** paginated. Neither the documentation nor any observed
 * response carries pagination metadata, so no paginator contract goes on this
 * request — inventing one would mean guessing a scheme. If a live response ever
 * turns up `meta.pagination` or a `total_records`, that is a finding to act on
 * rather than a shape to assume.
 *
 * @see https://developers.kudosity.com/reference/get_v2-webhook
 */
class ListWebhooksRequest extends KudosityV2Request
{
    /** The named collection key this endpoint returns items under. */
    public const ITEMS_KEY = 'webhooks';

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/webhook';
    }

    /**
     * @return array<int, WebhookData>
     */
    public function createDtoFromResponse(Response $response): array
    {
        $payload = static::payload($response);
        $items = $payload[self::ITEMS_KEY] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $webhooks = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $webhooks[] = WebhookData::fromArray($item);
            }
        }

        return $webhooks;
    }
}
