<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Read a single RCS message by id.
 *
 * The id to hold on to is `data.id` from the send response — it is what
 * webhook events (`RCS_STATUS`, `RCS_INBOUND`) match against.
 *
 * @see https://developers.kudosity.com/reference/get_v2-rcs-messages-id
 */
class GetRcsRequest extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/rcs/messages/'.$this->id;
    }

    public function createDtoFromResponse(Response $response): RcsMessageData
    {
        return RcsMessageData::fromArray(static::payload($response));
    }
}
