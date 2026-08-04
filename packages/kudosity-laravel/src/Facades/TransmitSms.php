<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Facades;

use ExpertSystems\Kudosity\TransmitSmsClient;
use ExpertSystems\Kudosity\TransmitSmsConnector;
use Illuminate\Support\Facades\Facade;
use Saloon\Http\Response;

/**
 * @method static TransmitSmsConnector connector()
 * @method static \ExpertSystems\Kudosity\Resources\AccountResource account()
 * @method static \ExpertSystems\Kudosity\Resources\SmsResource sms()
 * @method static \ExpertSystems\Kudosity\Resources\ReportingResource reporting()
 * @method static \ExpertSystems\Kudosity\Resources\ListsResource lists()
 * @method static \ExpertSystems\Kudosity\Resources\NumbersResource numbers()
 * @method static \ExpertSystems\Kudosity\Resources\KeywordsResource keywords()
 * @method static \ExpertSystems\Kudosity\Resources\EmailSmsResource emailSms()
 * @method static Response send(\ExpertSystems\Kudosity\Requests\TransmitSmsRequest $request)
 * @method static array<string, mixed> sendAndGetJson(\ExpertSystems\Kudosity\Requests\TransmitSmsRequest $request)
 * @method static TransmitSmsClient useSmsUrl()
 * @method static TransmitSmsClient useMmsUrl()
 * @method static TransmitSmsClient setBaseUrl(string $baseUrl)
 *
 * @see TransmitSmsClient
 */
class TransmitSms extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return TransmitSmsClient::class;
    }
}
