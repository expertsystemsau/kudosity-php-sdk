<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Facades;

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use Illuminate\Support\Facades\Facade;
use Saloon\Http\Response;

/**
 * @method static KudosityV1Connector connector()
 * @method static \ExpertSystems\Kudosity\KudosityV1Connector v1()
 * @method static \ExpertSystems\Kudosity\KudosityV2Connector v2()
 * @method static \ExpertSystems\Kudosity\Resources\AccountResource account()
 * @method static \ExpertSystems\Kudosity\Resources\BulkSmsResource bulk()
 * @method static \ExpertSystems\Kudosity\Resources\ReportingResource reporting()
 * @method static \ExpertSystems\Kudosity\Resources\ListsResource lists()
 * @method static \ExpertSystems\Kudosity\Resources\NumbersResource numbers()
 * @method static \ExpertSystems\Kudosity\Resources\KeywordsResource keywords()
 * @method static \ExpertSystems\Kudosity\Resources\EmailSmsResource emailSms()
 * @method static Response send(\ExpertSystems\Kudosity\Requests\KudosityV1Request $request)
 * @method static array<string, mixed> sendAndGetJson(\ExpertSystems\Kudosity\Requests\KudosityV1Request $request)
 * @method static KudosityClient setBaseUrl(string $baseUrl)
 * @method static KudosityClient setV1BaseUrl(string $baseUrl)
 *
 * @see KudosityClient
 */
class Kudosity extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return KudosityClient::class;
    }
}
