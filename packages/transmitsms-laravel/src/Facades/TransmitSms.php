<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Laravel\Facades;

use ExpertSystems\TransmitSms\TransmitSmsClient;
use ExpertSystems\TransmitSms\TransmitSmsConnector;
use Illuminate\Support\Facades\Facade;
use Saloon\Http\Response;

/**
 * @method static TransmitSmsConnector connector()
 * @method static \ExpertSystems\TransmitSms\Resources\AccountResource account()
 * @method static \ExpertSystems\TransmitSms\Resources\SmsResource sms()
 * @method static \ExpertSystems\TransmitSms\Resources\ReportingResource reporting()
 * @method static \ExpertSystems\TransmitSms\Resources\ListsResource lists()
 * @method static \ExpertSystems\TransmitSms\Resources\NumbersResource numbers()
 * @method static \ExpertSystems\TransmitSms\Resources\KeywordsResource keywords()
 * @method static \ExpertSystems\TransmitSms\Resources\EmailSmsResource emailSms()
 * @method static Response send(\ExpertSystems\TransmitSms\Requests\TransmitSmsRequest $request)
 * @method static array<string, mixed> sendAndGetJson(\ExpertSystems\TransmitSms\Requests\TransmitSmsRequest $request)
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
