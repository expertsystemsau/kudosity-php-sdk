<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Read a single MMS message by id.
 *
 * @see https://developers.kudosity.com/reference/get_v2-mms-id
 */
class GetMmsRequest extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/mms/'.$this->id;
    }

    public function createDtoFromResponse(Response $response): MmsMessageData
    {
        return MmsMessageData::fromArray(static::payload($response));
    }
}
