<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Concerns\GuardsMessageRef;
use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use Saloon\Http\Response;

/**
 * Send a single-recipient SMS.
 *
 * `POST /v2/sms` takes exactly one recipient and has no scheduling. For
 * multiple recipients, a contact list, or a future send time, use the V1
 * bulk surface — `$client->bulk()`.
 *
 * @see https://developers.kudosity.com/reference/post_v2-sms
 */
class SendSmsV2Request extends KudosityV2BodyRequest
{
    use GuardsMessageRef;

    /**
     * @throws ValidationException If message_ref exceeds its documented maximum
     */
    public function __construct(
        protected string $message,
        protected string $recipient,
        protected string $sender,
        protected ?string $messageRef = null,
        protected bool $trackLinks = false,
    ) {
        self::guardMessageRef($messageRef);
    }

    public function resolveEndpoint(): string
    {
        return '/v2/sms';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'message' => $this->message,
            'sender' => $this->sender,
            'recipient' => $this->recipient,
        ];

        if ($this->messageRef !== null) {
            $body['message_ref'] = $this->messageRef;
        }

        if ($this->trackLinks) {
            $body['track_links'] = true;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): SmsMessageData
    {
        // payload() comes from UnwrapsData on the base. SMS is flat, but going
        // through it keeps every V2 request identical regardless of envelope.
        return SmsMessageData::fromArray(static::payload($response));
    }
}
