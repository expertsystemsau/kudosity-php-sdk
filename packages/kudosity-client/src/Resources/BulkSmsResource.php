<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use DateTimeInterface;
use ExpertSystems\Kudosity\Concerns\FormatsPhoneNumbers;
use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\CancelSmsRequest;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;

/**
 * V1 bulk SMS: multiple recipients, contact lists, and scheduled sends.
 *
 * These are the sends V2 cannot do. `POST /v2/sms` takes exactly one recipient
 * and has no `send_at`, so multi-recipient, list and scheduled sends stay on
 * V1's `send-sms.json` — which is why this is `bulk()` and not `sms()`.
 *
 * @see https://developers.kudosity.com/reference/transmit-sms-api
 */
class BulkSmsResource extends Resource
{
    use FormatsPhoneNumbers;

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
     * $client->bulk()->send('Hi', '+61400000000', configure: fn (SendSmsRequest $r) =>
     *     $r->repliesToEmail('inbox@example.com')->validity(60)
     * );
     * ```
     *
     * @param  string  $message  The message content (up to 612 characters)
     * @param  string  $to  Single number or comma-separated numbers (up to 500)
     * @param  string|null  $from  Override the default sender ID (optional)
     * @param  (callable(SendSmsRequest): mixed)|null  $configure  Configure the request before sending (optional)
     *
     * @throws KudosityException
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
     * @throws KudosityException
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
     * @throws KudosityException
     */
    public function sendRequest(SendSmsRequest $request): SmsData
    {
        /** @var SmsData */
        return $this->sendAndDto($request);
    }

    /**
     * Send at a future time.
     *
     * Scheduling is V1-only — `POST /v2/sms` has no `send_at`.
     *
     * @param  string|DateTimeInterface  $at  ISO8601 `YYYY-MM-DD HH:MM:SS` in UTC, or a DateTimeInterface
     *
     * @throws KudosityException
     */
    public function schedule(
        string $message,
        string $to,
        string|DateTimeInterface $at,
        ?string $from = null,
    ): SmsData {
        return $this->send($message, $to, $from, static fn (SendSmsRequest $request) => $request->scheduledAt($at));
    }

    /**
     * Cancel a scheduled SMS message.
     *
     * @param  int  $messageId  The message ID to cancel
     *
     * @throws KudosityException
     */
    public function cancel(int $messageId): bool
    {
        $response = $this->connector->send(new CancelSmsRequest($messageId));
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
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
