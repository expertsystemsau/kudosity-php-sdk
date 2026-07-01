<?php

declare(strict_types=1);

namespace ExpertSystems\TransmitSms\Requests;

use DateTimeInterface;
use DateTimeZone;
use ExpertSystems\TransmitSms\Data\SmsData;
use ExpertSystems\TransmitSms\Exceptions\ValidationException;
use ExpertSystems\TransmitSms\Support\PhoneNumber;
use ExpertSystems\TransmitSms\Support\Url;
use Saloon\Http\Response;

/**
 * Send an SMS message.
 *
 * Supports sending to individual numbers or to a list.
 * Use the fluent builder methods to set optional parameters.
 *
 * @see https://developer.transmitsms.com/#send-sms
 */
class SendSmsRequest extends TransmitSmsRequest
{
    /**
     * Maximum message length in characters (standard SMS concatenation limit).
     */
    public const MAX_MESSAGE_LENGTH = 612;

    /**
     * Maximum validity period in minutes (72 hours).
     */
    public const MAX_VALIDITY_MINUTES = 4320;

    protected ?string $to = null;

    protected ?int $listId = null;

    protected ?string $from = null;

    protected ?string $countryCode = null;

    protected ?string $sendAt = null;

    protected ?int $validity = null;

    protected ?string $repliesToEmail = null;

    protected ?string $trackedLinkUrl = null;

    protected ?string $dlrCallback = null;

    protected ?string $replyCallback = null;

    protected ?string $linkHitsCallback = null;

    protected bool $formatNumbers = false;

    /**
     * Create a new SendSmsRequest.
     *
     * @param  string  $message  The message content (up to 612 characters)
     *
     * @throws ValidationException If the message exceeds the maximum length
     */
    public function __construct(
        protected string $message,
    ) {
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new ValidationException(
                message: sprintf(
                    'Message length (%d chars) exceeds maximum of %d characters',
                    mb_strlen($message),
                    self::MAX_MESSAGE_LENGTH
                ),
                errorCode: 'FIELD_INVALID'
            );
        }
    }

    /**
     * Set the recipient phone number(s).
     *
     * Numbers can be in local or international format. If a country code is set,
     * local numbers will be automatically formatted to international E.164 format.
     *
     * @param  string  $to  Single number or comma-separated numbers (up to 500)
     *
     * @throws ValidationException If more than 500 recipients are provided
     */
    public function to(string $to): self
    {
        $recipientCount = PhoneNumber::countRecipients($to);
        if ($recipientCount > PhoneNumber::MAX_RECIPIENTS) {
            throw new ValidationException(
                message: sprintf(
                    'Recipient count (%d) exceeds maximum of %d recipients per request',
                    $recipientCount,
                    PhoneNumber::MAX_RECIPIENTS
                ),
                errorCode: 'FIELD_INVALID'
            );
        }

        $this->to = $to;

        return $this;
    }

    /**
     * Set the recipient list ID.
     *
     * @param  int  $listId  The list ID to send to
     */
    public function toList(int $listId): self
    {
        $this->listId = $listId;

        return $this;
    }

    /**
     * Set the sender ID.
     *
     * Can be:
     * - A virtual mobile number (VMN) in international format
     * - A short code
     * - An alphanumeric sender (max 11 chars, no spaces)
     *
     * If not set, defaults to the shared Sender ID for the destination country.
     *
     * @param  string  $from  Virtual number, short code, or alphanumeric sender
     */
    public function from(string $from): self
    {
        $this->from = $from;

        return $this;
    }

    /**
     * Set the country code for formatting local numbers.
     *
     * When set, local numbers (e.g., 0400000000) will be automatically
     * formatted to international E.164 format (e.g., 61400000000).
     *
     * @param  string  $countryCode  2-letter ISO 3166 country code (e.g., 'AU', 'NZ', 'US')
     */
    public function countryCode(string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    /**
     * Enable automatic formatting of phone numbers.
     *
     * When enabled, the client will format numbers locally before sending.
     * This is useful when you want to ensure numbers are in E.164 format
     * without relying on the API's countrycode parameter.
     */
    public function formatNumbers(bool $format = true): self
    {
        $this->formatNumbers = $format;

        return $this;
    }

    /**
     * Schedule the message for a specific time.
     *
     * @param  string|DateTimeInterface  $sendAt  Date/time string (ISO8601: YYYY-MM-DD HH:MM:SS UTC) or DateTimeInterface
     *
     * @throws ValidationException If the datetime cannot be converted
     */
    public function scheduledAt(string|DateTimeInterface $sendAt): self
    {
        if ($sendAt instanceof DateTimeInterface) {
            // Create DateTimeImmutable directly from Unix timestamp in UTC
            // Using 'U' format ensures timezone-agnostic conversion
            $utc = \DateTimeImmutable::createFromFormat(
                'U',
                (string) $sendAt->getTimestamp(),
                new DateTimeZone('UTC')
            );

            if ($utc === false) {
                throw new ValidationException(
                    message: 'Failed to convert datetime to UTC format',
                    errorCode: 'FIELD_INVALID'
                );
            }

            $this->sendAt = $utc->format('Y-m-d H:i:s');
        } else {
            $this->sendAt = $sendAt;
        }

        return $this;
    }

    /**
     * Set the message validity period.
     *
     * @param  int  $minutes  Maximum time to attempt delivery (0 = maximum period, max 4320 = 72 hours)
     *
     * @throws ValidationException If validity exceeds the maximum period
     */
    public function validity(int $minutes): self
    {
        if ($minutes < 0) {
            throw new ValidationException(
                message: 'Validity period cannot be negative',
                errorCode: 'FIELD_INVALID'
            );
        }

        if ($minutes > self::MAX_VALIDITY_MINUTES) {
            throw new ValidationException(
                message: sprintf(
                    'Validity period (%d minutes) exceeds maximum of %d minutes (72 hours)',
                    $minutes,
                    self::MAX_VALIDITY_MINUTES
                ),
                errorCode: 'FIELD_INVALID'
            );
        }

        $this->validity = $minutes;

        return $this;
    }

    /**
     * Send replies to an email address.
     *
     * @param  string  $email  Email address to receive replies
     *
     * @throws ValidationException If the email is invalid
     */
    public function repliesToEmail(string $email): self
    {
        Url::validateEmail($email, 'replies_to_email');
        $this->repliesToEmail = $email;

        return $this;
    }

    /**
     * Set a tracked link URL.
     *
     * Use [tracked-link] in your message to insert the shortened tracking link.
     *
     * @param  string  $url  The URL to convert to a tracking link
     *
     * @throws ValidationException If the URL is invalid
     */
    public function trackedLinkUrl(string $url): self
    {
        Url::validate($url, 'tracked_link_url');
        $this->trackedLinkUrl = $url;

        return $this;
    }

    /**
     * Set the delivery report callback URL.
     *
     * This URL will be called by the TransmitSMS API to notify you of delivery status.
     * SSRF protection is applied to prevent internal network access.
     *
     * @param  string  $url  URL to receive delivery notifications (must be publicly accessible)
     *
     * @throws ValidationException If the URL is invalid or points to internal resources
     */
    public function dlrCallback(string $url): self
    {
        Url::validateCallbackUrl($url, 'dlr_callback');
        $this->dlrCallback = $url;

        return $this;
    }

    /**
     * Set the reply callback URL.
     *
     * This URL will be called by the TransmitSMS API when replies are received.
     * SSRF protection is applied to prevent internal network access.
     *
     * @param  string  $url  URL to receive reply notifications (must be publicly accessible)
     *
     * @throws ValidationException If the URL is invalid or points to internal resources
     */
    public function replyCallback(string $url): self
    {
        Url::validateCallbackUrl($url, 'reply_callback');
        $this->replyCallback = $url;

        return $this;
    }

    /**
     * Set the link hits callback URL.
     *
     * This URL will be called by the TransmitSMS API when tracked links are clicked.
     * SSRF protection is applied to prevent internal network access.
     *
     * @param  string  $url  URL to receive link click notifications (must be publicly accessible)
     *
     * @throws ValidationException If the URL is invalid or points to internal resources
     */
    public function linkHitsCallback(string $url): self
    {
        Url::validateCallbackUrl($url, 'link_hits_callback');
        $this->linkHitsCallback = $url;

        return $this;
    }

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('/send-sms');
    }

    /**
     * Get the formatted recipient number(s).
     *
     * @return string|null The formatted number(s)
     */
    protected function getFormattedTo(): ?string
    {
        if ($this->to === null) {
            return null;
        }

        // If format numbers is enabled and we have a country code, format locally
        if ($this->formatNumbers && $this->countryCode !== null) {
            return PhoneNumber::formatMultiple($this->to, $this->countryCode);
        }

        return $this->to;
    }

    /**
     * Get the formatted sender ID.
     *
     * @return string|null The formatted sender ID
     */
    protected function getFormattedFrom(): ?string
    {
        if ($this->from === null) {
            return null;
        }

        // Format sender if it looks like a phone number and we have a country code
        if ($this->formatNumbers && $this->countryCode !== null) {
            return PhoneNumber::formatSenderId($this->from, $this->countryCode);
        }

        return $this->from;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'message' => $this->message,
        ];

        $to = $this->getFormattedTo();
        if ($to !== null) {
            $body['to'] = $to;
        }

        if ($this->listId !== null) {
            $body['list_id'] = $this->listId;
        }

        $from = $this->getFormattedFrom();
        if ($from !== null) {
            $body['from'] = $from;
        }

        // Only send countrycode if we're not formatting locally
        if ($this->countryCode !== null && ! $this->formatNumbers) {
            $body['countrycode'] = $this->countryCode;
        }

        if ($this->sendAt !== null) {
            $body['send_at'] = $this->sendAt;
        }

        if ($this->validity !== null) {
            $body['validity'] = $this->validity;
        }

        if ($this->repliesToEmail !== null) {
            $body['replies_to_email'] = $this->repliesToEmail;
        }

        if ($this->trackedLinkUrl !== null) {
            $body['tracked_link_url'] = $this->trackedLinkUrl;
        }

        if ($this->dlrCallback !== null) {
            $body['dlr_callback'] = $this->dlrCallback;
        }

        if ($this->replyCallback !== null) {
            $body['reply_callback'] = $this->replyCallback;
        }

        if ($this->linkHitsCallback !== null) {
            $body['link_hits_callback'] = $this->linkHitsCallback;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): SmsData
    {
        return SmsData::fromResponse($response->json());
    }
}
