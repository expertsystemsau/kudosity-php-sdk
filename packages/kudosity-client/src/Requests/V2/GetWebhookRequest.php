<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Read a single webhook registration by id.
 *
 * This endpoint is **not in the vendored skill**, which documents only the
 * collection read. It was confirmed to exist by probing the live API: a `GET`
 * against a real registration id answers 200 with the same flat shape the create
 * response uses, including the undocumented `is_sandbox`, `created_at` and
 * `updated_at`.
 *
 * Worth knowing that the read response's `created_at` carried **six** fractional
 * digits where the create response carried nine, which is why timestamps go
 * through the permissive parser rather than a fixed format.
 */
class GetWebhookRequest extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/webhook/'.$this->id;
    }

    public function createDtoFromResponse(Response $response): WebhookData
    {
        return WebhookData::fromArray(static::payload($response));
    }
}
