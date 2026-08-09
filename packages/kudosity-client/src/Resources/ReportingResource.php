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
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use ExpertSystems\Kudosity\Requests\GetContactSmsStatsRequest;
use ExpertSystems\Kudosity\Requests\GetMessageReportRequest;
use ExpertSystems\Kudosity\Requests\GetSmsDeliveryStatusRequest;
use ExpertSystems\Kudosity\Requests\GetSmsRequest;
use ExpertSystems\Kudosity\Requests\GetSmsResponsesRequest;
use ExpertSystems\Kudosity\Requests\GetSmsSentCountRequest;
use ExpertSystems\Kudosity\Requests\GetSmsSentRequest;
use ExpertSystems\Kudosity\Requests\GetSmsStatsRequest;
use ExpertSystems\Kudosity\Requests\GetUserSmsResponsesRequest;
use ExpertSystems\Kudosity\Requests\GetUserSmsSentRequest;

/**
 * Reporting resource for retrieving SMS delivery and statistics.
 *
 * Replies are reads too, so the SMS response/reply readers live here
 * alongside every other read — sends and list management stay elsewhere.
 *
 * @see https://developers.kudosity.com
 */
class ReportingResource extends Resource
{
    /**
     * Get information about a message or campaign that has been sent.
     *
     * @param  int  $messageId  The message ID to retrieve
     *
     * @throws KudosityException
     */
    public function getMessage(int $messageId): MessageData
    {
        $request = new GetSmsRequest($messageId);

        /** @var MessageData */
        return $this->sendAndDto($request);
    }

    /**
     * Get delivery status for a specific message to a specific recipient.
     *
     * @param  int  $messageId  The message ID
     * @param  string  $mobile  The recipient mobile number
     *
     * @throws KudosityException
     */
    public function getDeliveryStatus(int $messageId, string $mobile): DeliveryStatusData
    {
        $request = new GetSmsDeliveryStatusRequest($messageId, $mobile);

        /** @var DeliveryStatusData */
        return $this->sendAndDto($request);
    }

    /**
     * Get statistics for a message or campaign that has been sent.
     *
     * @param  int  $messageId  The message ID
     *
     * @throws KudosityException
     */
    public function getStats(int $messageId): SmsStatsData
    {
        $request = new GetSmsStatsRequest($messageId);

        /** @var SmsStatsData */
        return $this->sendAndDto($request);
    }

    /**
     * Get a count of SMS sent for the account.
     *
     * @param  string|DateTimeInterface|null  $start  Start date for the count
     * @param  string|DateTimeInterface|null  $end  End date for the count
     *
     * @throws KudosityException
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
        return $this->sendAndDto($request);
    }

    /**
     * Get list of SMS sent for a message (paginated).
     *
     * Returns a paginator that can be iterated to get all sent SMS.
     *
     * @param  int  $messageId  The message ID
     */
    public function getSent(int $messageId): V1PagedPaginator
    {
        $request = new GetSmsSentRequest($messageId);

        return $this->connector->paginate($request);
    }

    /**
     * Get list of SMS sent for a message using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getSentRequest(GetSmsSentRequest $request): V1PagedPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get list of all SMS sent by user (paginated).
     *
     * Returns a paginator that can be iterated to get all sent SMS.
     */
    public function getUserSent(): V1PagedPaginator
    {
        return $this->connector->paginate(new GetUserSmsSentRequest);
    }

    /**
     * Get list of all SMS sent by user using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getUserSentRequest(GetUserSmsSentRequest $request): V1PagedPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get message report for a date range.
     *
     * @param  string|DateTimeInterface  $start  Start date for the report
     * @param  string|DateTimeInterface  $end  End date for the report
     *
     * @throws KudosityException
     */
    public function getMessageReport(
        string|DateTimeInterface $start,
        string|DateTimeInterface $end,
    ): MessageReportData {
        $request = new GetMessageReportRequest($start, $end);

        /** @var MessageReportData */
        return $this->sendAndDto($request);
    }

    /**
     * Get message report using a custom request.
     *
     * Use this for advanced filtering options.
     *
     * @throws KudosityException
     */
    public function getMessageReportRequest(GetMessageReportRequest $request): MessageReportData
    {
        /** @var MessageReportData */
        return $this->sendAndDto($request);
    }

    /**
     * Get SMS statistics for a specific contact/mobile number.
     *
     * @param  string  $mobile  The mobile number
     * @param  string|null  $countryCode  Country code for local numbers
     *
     * @throws KudosityException
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
        return $this->sendAndDto($request);
    }

    /**
     * Get SMS statistics for a specific contact using a custom request.
     *
     * Use this for advanced filtering options.
     *
     * @throws KudosityException
     */
    public function getContactStatsRequest(GetContactSmsStatsRequest $request): ContactSmsStatsData
    {
        /** @var ContactSmsStatsData */
        return $this->sendAndDto($request);
    }

    /**
     * Get SMS responses/replies for a specific message.
     *
     * Returns a paginator that can be iterated to get all responses.
     *
     * @param  int  $messageId  The message ID to get responses for
     */
    public function getResponses(int $messageId): V1PagedPaginator
    {
        $request = GetSmsResponsesRequest::forMessage($messageId);

        return $this->connector->paginate($request);
    }

    /**
     * Get SMS responses/replies for a keyword.
     *
     * Returns a paginator that can be iterated to get all responses.
     *
     * @param  int  $keywordId  The keyword ID
     */
    public function getResponsesByKeywordId(int $keywordId): V1PagedPaginator
    {
        $request = GetSmsResponsesRequest::forKeywordId($keywordId);

        return $this->connector->paginate($request);
    }

    /**
     * Get SMS responses/replies for a keyword by name.
     *
     * Returns a paginator that can be iterated to get all responses.
     *
     * @param  string  $keyword  The keyword name
     * @param  string  $number  The VMN number
     */
    public function getResponsesByKeyword(string $keyword, string $number): V1PagedPaginator
    {
        $request = GetSmsResponsesRequest::forKeyword($keyword, $number);

        return $this->connector->paginate($request);
    }

    /**
     * Get all SMS responses/replies using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getResponsesRequest(GetSmsResponsesRequest $request): V1PagedPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get all SMS responses/replies for the account.
     *
     * Returns a paginator that can be iterated to get all responses.
     * By default returns responses from the last 30 days.
     */
    public function getAllResponses(): V1PagedPaginator
    {
        return $this->connector->paginate(new GetUserSmsResponsesRequest);
    }

    /**
     * Get all SMS responses/replies using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getAllResponsesRequest(GetUserSmsResponsesRequest $request): V1PagedPaginator
    {
        return $this->connector->paginate($request);
    }
}
