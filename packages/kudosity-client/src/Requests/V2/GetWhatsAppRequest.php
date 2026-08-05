<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Read a single WhatsApp message by id.
 *
 * The id to hold on to is `data.id` from the send response — it is what webhook
 * events match against.
 *
 * @see https://developers.kudosity.com/reference/get_v2-whatsapp-messages-id
 */
class GetWhatsAppRequest extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/whatsapp/messages/'.$this->id;
    }

    public function createDtoFromResponse(Response $response): WhatsAppMessageData
    {
        return WhatsAppMessageData::fromArray(static::payload($response));
    }
}
