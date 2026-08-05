<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Read a single SMS message by id.
 *
 * @see https://developers.kudosity.com/reference/get_v2-sms-id
 */
class GetSmsV2Request extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/sms/'.$this->id;
    }

    public function createDtoFromResponse(Response $response): SmsMessageData
    {
        return SmsMessageData::fromArray(static::payload($response));
    }
}
