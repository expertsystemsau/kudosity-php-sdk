<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Replace a webhook registration.
 *
 * **`PUT` is a replace, not a patch.** `name` and `url` are required, which is
 * why they are required here too rather than nullable "change this if given"
 * parameters. Confirmed against the live API: a `PUT` carrying only `url`
 * answers 400 with `{"error":"Validation Error: name: length must be between 2
 * and 100"}` — the name was not preserved, it was simply missing.
 *
 * The practical consequence is that changing one field means reading the
 * registration first. {@see WebhooksResource::update()}
 * takes the whole shape for that reason, and the guards are shared with
 * {@see CreateWebhookRequest} so the two cannot drift.
 *
 * @see https://developers.kudosity.com/reference/put_v2-webhook-id
 */
class UpdateWebhookRequest extends KudosityV2BodyRequest
{
    protected Method $method = Method::PUT;

    /**
     * @throws ValidationException If the name is outside its documented length, the
     *                             URL is not HTTPS, or the rate limit is out of range
     */
    public function __construct(
        protected string $id,
        protected string $name,
        protected string $url,
        protected ?WebhookFilter $filter = null,
        protected ?int $rateLimit = null,
    ) {
        CreateWebhookRequest::guardName($name);
        CreateWebhookRequest::guardUrl($url);
        CreateWebhookRequest::guardRateLimit($rateLimit);
    }

    public function resolveEndpoint(): string
    {
        return '/v2/webhook/'.$this->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'url' => $this->url,
        ];

        if ($this->filter !== null && ! $this->filter->isEmpty()) {
            $body['filter'] = $this->filter->toArray();
        }

        if ($this->rateLimit !== null) {
            $body['rate_limit'] = $this->rateLimit;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): WebhookData
    {
        return WebhookData::fromArray(static::payload($response));
    }
}
