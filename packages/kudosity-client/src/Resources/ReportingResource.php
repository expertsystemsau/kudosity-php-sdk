<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use DateTimeInterface;
use ExpertSystems\Kudosity\Data\ContactSmsStatsData;
use ExpertSystems\Kudosity\Data\DeliveryStatusData;
use ExpertSystems\Kudosity\Data\MessageData;
use ExpertSystems\Kudosity\Data\MessageReportData;
use ExpertSystems\Kudosity\Data\SmsSentCountData;
use ExpertSystems\Kudosity\Data\SmsStatsData;
use ExpertSystems\Kudosity\Exceptions\TransmitSmsException;
use ExpertSystems\Kudosity\Pagination\TransmitSmsPaginator;
use ExpertSystems\Kudosity\Requests\GetContactSmsStatsRequest;
use ExpertSystems\Kudosity\Requests\GetMessageReportRequest;
use ExpertSystems\Kudosity\Requests\GetSmsDeliveryStatusRequest;
use ExpertSystems\Kudosity\Requests\GetSmsRequest;
use ExpertSystems\Kudosity\Requests\GetSmsSentCountRequest;
use ExpertSystems\Kudosity\Requests\GetSmsSentRequest;
use ExpertSystems\Kudosity\Requests\GetSmsStatsRequest;
use ExpertSystems\Kudosity\Requests\GetUserSmsSentRequest;

/**
 * Reporting resource for retrieving SMS delivery and statistics.
 *
 * @see https://developer.transmitsms.com/#sms
 */
class ReportingResource extends Resource
{
    /**
     * Get information about a message or campaign that has been sent.
     *
     * @param  int  $messageId  The message ID to retrieve
     *
     * @throws TransmitSmsException
     */
    public function getMessage(int $messageId): MessageData
    {
        $request = new GetSmsRequest($messageId);

        /** @var MessageData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get delivery status for a specific message to a specific recipient.
     *
     * @param  int  $messageId  The message ID
     * @param  string  $mobile  The recipient mobile number
     *
     * @throws TransmitSmsException
     */
    public function getDeliveryStatus(int $messageId, string $mobile): DeliveryStatusData
    {
        $request = new GetSmsDeliveryStatusRequest($messageId, $mobile);

        /** @var DeliveryStatusData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get statistics for a message or campaign that has been sent.
     *
     * @param  int  $messageId  The message ID
     *
     * @throws TransmitSmsException
     */
    public function getStats(int $messageId): SmsStatsData
    {
        $request = new GetSmsStatsRequest($messageId);

        /** @var SmsStatsData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get a count of SMS sent for the account.
     *
     * @param  string|DateTimeInterface|null  $start  Start date for the count
     * @param  string|DateTimeInterface|null  $end  End date for the count
     *
     * @throws TransmitSmsException
     */
    public function getSentCount(
        string|DateTimeInterface|null $start = null,
        string|DateTimeInterface|null $end = null,
    ): SmsSentCountData {
        $request = new GetSmsSentCountRequest;

        if ($start !== null) {
            $request->from($start);
        }

        if ($end !== null) {
            $request->to($end);
        }

        /** @var SmsSentCountData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get list of SMS sent for a message (paginated).
     *
     * Returns a paginator that can be iterated to get all sent SMS.
     *
     * @param  int  $messageId  The message ID
     */
    public function getSent(int $messageId): TransmitSmsPaginator
    {
        $request = new GetSmsSentRequest($messageId);

        return $this->connector->paginate($request);
    }

    /**
     * Get list of SMS sent for a message using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getSentRequest(GetSmsSentRequest $request): TransmitSmsPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get list of all SMS sent by user (paginated).
     *
     * Returns a paginator that can be iterated to get all sent SMS.
     */
    public function getUserSent(): TransmitSmsPaginator
    {
        return $this->connector->paginate(new GetUserSmsSentRequest);
    }

    /**
     * Get list of all SMS sent by user using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getUserSentRequest(GetUserSmsSentRequest $request): TransmitSmsPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get message report for a date range.
     *
     * @param  string|DateTimeInterface  $start  Start date for the report
     * @param  string|DateTimeInterface  $end  End date for the report
     *
     * @throws TransmitSmsException
     */
    public function getMessageReport(
        string|DateTimeInterface $start,
        string|DateTimeInterface $end,
    ): MessageReportData {
        $request = new GetMessageReportRequest($start, $end);

        /** @var MessageReportData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get message report using a custom request.
     *
     * Use this for advanced filtering options.
     *
     * @throws TransmitSmsException
     */
    public function getMessageReportRequest(GetMessageReportRequest $request): MessageReportData
    {
        /** @var MessageReportData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get SMS statistics for a specific contact/mobile number.
     *
     * @param  string  $mobile  The mobile number
     * @param  string|null  $countryCode  Country code for local numbers
     *
     * @throws TransmitSmsException
     */
    public function getContactStats(string $mobile, ?string $countryCode = null): ContactSmsStatsData
    {
        $request = new GetContactSmsStatsRequest($mobile);

        // Apply default country code if not provided
        $countryCode ??= $this->connector->getDefaultCountryCode();
        if ($countryCode !== null) {
            $request->countryCode($countryCode);
        }

        /** @var ContactSmsStatsData */
        return $this->connector->send($request)->dtoOrFail();
    }

    /**
     * Get SMS statistics for a specific contact using a custom request.
     *
     * Use this for advanced filtering options.
     *
     * @throws TransmitSmsException
     */
    public function getContactStatsRequest(GetContactSmsStatsRequest $request): ContactSmsStatsData
    {
        /** @var ContactSmsStatsData */
        return $this->connector->send($request)->dtoOrFail();
    }
}
