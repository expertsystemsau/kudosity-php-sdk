<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use Saloon\Enums\Method;

/**
 * Delete a webhook registration.
 *
 * Answers **200**, not 204, and carries no useful body — so there is no DTO.
 * {@see WebhooksResource::delete()} reports
 * success as a bool rather than inventing one.
 *
 * Extends the plain V2 base, not the body one: a DELETE with a body — even `[]`
 * — is stripped or rejected by some gateways.
 *
 * @see https://developers.kudosity.com/reference/delete_v2-webhook-id
 */
class DeleteWebhookRequest extends KudosityV2Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/webhook/'.$this->id;
    }
}
