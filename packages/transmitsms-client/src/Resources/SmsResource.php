<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Resources;

use ExpertSystems\TransmitSms\Data\FormattedNumberData;
use ExpertSystems\TransmitSms\Data\SmsData;
use ExpertSystems\TransmitSms\Exceptions\TransmitSmsException;
use ExpertSystems\TransmitSms\Pagination\TransmitSmsPaginator;
use ExpertSystems\TransmitSms\Requests\CancelSmsRequest;
use ExpertSystems\TransmitSms\Requests\FormatNumberRequest;
use ExpertSystems\TransmitSms\Requests\GetSmsResponsesRequest;
use ExpertSystems\TransmitSms\Requests\GetUserSmsResponsesRequest;
use ExpertSystems\TransmitSms\Requests\SendSmsRequest;
use ExpertSystems\TransmitSms\Support\PhoneNumber;

/**
 * SMS resource for sending and managing SMS messages.
 *
 * @see https://developer.transmitsms.com/#sms
 */
class SmsResource extends Resource
{
    /**
     * Send an SMS message to one or more recipients.
     *
     * Uses the connector's default 'from' and 'countryCode' if configured.
     *
     * Pass a `$configure` closure to set any additional option on the request
     * (replies-to-email, callbacks, scheduling, validity, ...). It receives the
     * request after connector defaults have been applied:
     *
     * ```php
     * $client->sms()->send('Hi', '+61400000000', configure: fn (SendSmsRequest $r) =>
     *     $r->repliesToEmail('inbox@example.com')->validity(60)
     * );
     * ```
     *
     * @param  string  $message  The message content (up to 612 characters)
     * @param  string  $to  Single number or comma-separated numbers (up to 500)
     * @param  string|null  $from  Override the default sender ID (optional)
     * @param  (callable(SendSmsRequest): mixed)|null  $configure  Configure the request before sending (optional)
     *
     * @throws TransmitSmsException
     */
    public function send(string $message, string $to, ?string $from = null, ?callable $configure = null): SmsData
    {
        $request = (new SendSmsRequest($message))->to($to);

        $this->applyDefaults($request, $from);

        if ($configure !== null) {
            $configure($request);
        }

        /** @var SmsData */
        return $this->sendAndDto($request);
    }

    /**
     * Send an SMS message to a list.
     *
     * Uses the connector's default 'from' and 'countryCode' if configured.
     *
     * Pass a `$configure` closure to set any additional option on the request;
     * it receives the request after connector defaults have been applied. See
     * {@see self::send()} for an example.
     *
     * @param  string  $message  The message content (up to 612 characters)
     * @param  int  $listId  The list ID to send to
     * @param  string|null  $from  Override the default sender ID (optional)
     * @param  (callable(SendSmsRequest): mixed)|null  $configure  Configure the request before sending (optional)
     *
     * @throws TransmitSmsException
     */
    public function sendToList(string $message, int $listId, ?string $from = null, ?callable $configure = null): SmsData
    {
        $request = (new SendSmsRequest($message))->toList($listId);

        $this->applyDefaults($request, $from);

        if ($configure !== null) {
            $configure($request);
        }

        /** @var SmsData */
        return $this->sendAndDto($request);
    }

    /**
     * Send a custom SMS request with all options.
     *
     * Use this for advanced scenarios where you need full control over the request.
     * Note: Defaults are NOT applied when using this method - configure the request directly.
     *
     * @throws TransmitSmsException
     */
    public function sendRequest(SendSmsRequest $request): SmsData
    {
        /** @var SmsData */
        return $this->sendAndDto($request);
    }

    /**
     * Cancel a scheduled SMS message.
     *
     * @param  int  $messageId  The message ID to cancel
     *
     * @throws TransmitSmsException
     */
    public function cancel(int $messageId): bool
    {
        $response = $this->connector->send(new CancelSmsRequest($messageId));
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Format a phone number for SMS delivery using the API.
     *
     * Converts local format numbers to international E.164 format.
     * If no country code is provided, uses the connector's default.
     *
     * @param  string  $number  The phone number to format
     * @param  string|null  $countryCode  2-letter ISO country code (e.g., 'AU', 'NZ', 'US')
     *
     * @throws TransmitSmsException
     */
    public function formatNumber(string $number, ?string $countryCode = null): FormattedNumberData
    {
        $countryCode ??= $this->connector->getDefaultCountryCode();

        if ($countryCode === null) {
            throw new TransmitSmsException(
                'Country code is required. Set it on the connector or pass it as a parameter.'
            );
        }

        $request = new FormatNumberRequest($number, $countryCode);

        /** @var FormattedNumberData */
        return $this->sendAndDto($request);
    }

    /**
     * Format a phone number locally (without API call).
     *
     * Uses the internal PhoneNumber utility to format numbers to E.164.
     *
     * @param  string  $number  The phone number to format
     * @param  string|null  $countryCode  2-letter ISO country code
     */
    public function formatNumberLocal(string $number, ?string $countryCode = null): string
    {
        $countryCode ??= $this->connector->getDefaultCountryCode();

        return PhoneNumber::toInternational($number, $countryCode);
    }

    /**
     * Validate a phone number format.
     *
     * @param  string  $number  The phone number to validate
     */
    public function isValidNumber(string $number): bool
    {
        return PhoneNumber::isValid($number);
    }

    /**
     * Validate multiple phone numbers.
     *
     * @param  string  $numbers  Comma-separated phone numbers
     * @return array{valid: string[], invalid: string[]}
     */
    public function validateNumbers(string $numbers): array
    {
        return PhoneNumber::validateMultiple($numbers);
    }

    /**
     * Validate a sender ID.
     *
     * @param  string  $senderId  The sender ID to validate
     */
    public function isValidSenderId(string $senderId): bool
    {
        return PhoneNumber::isValidSenderId($senderId);
    }

    /**
     * Get SMS responses/replies for a specific message.
     *
     * Returns a paginator that can be iterated to get all responses.
     *
     * @param  int  $messageId  The message ID to get responses for
     */
    public function getResponses(int $messageId): TransmitSmsPaginator
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
    public function getResponsesByKeywordId(int $keywordId): TransmitSmsPaginator
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
    public function getResponsesByKeyword(string $keyword, string $number): TransmitSmsPaginator
    {
        $request = GetSmsResponsesRequest::forKeyword($keyword, $number);

        return $this->connector->paginate($request);
    }

    /**
     * Get all SMS responses/replies using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getResponsesRequest(GetSmsResponsesRequest $request): TransmitSmsPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Get all SMS responses/replies for the account.
     *
     * Returns a paginator that can be iterated to get all responses.
     * By default returns responses from the last 30 days.
     */
    public function getAllResponses(): TransmitSmsPaginator
    {
        return $this->connector->paginate(new GetUserSmsResponsesRequest);
    }

    /**
     * Get all SMS responses/replies using a custom request.
     *
     * Use this for advanced filtering options.
     */
    public function getAllResponsesRequest(GetUserSmsResponsesRequest $request): TransmitSmsPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Apply connector defaults to a send request.
     *
     * @param  SendSmsRequest  $request  The request to modify
     * @param  string|null  $fromOverride  Override for the sender ID
     */
    protected function applyDefaults(SendSmsRequest $request, ?string $fromOverride = null): void
    {
        // Apply sender ID (override takes precedence, then connector default)
        $from = $fromOverride ?? $this->connector->getDefaultFrom();
        if ($from !== null) {
            $request->from($from);
        }

        // Apply country code if set
        $countryCode = $this->connector->getDefaultCountryCode();
        if ($countryCode !== null) {
            $request->countryCode($countryCode);
        }
    }
}
