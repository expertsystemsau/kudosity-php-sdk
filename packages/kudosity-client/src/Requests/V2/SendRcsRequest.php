<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Concerns\GuardsMessageRef;
use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use ExpertSystems\Kudosity\Support\PhoneNumber;
use Saloon\Http\Response;

/**
 * Send an RCS message.
 *
 * `POST /v2/rcs/messages` carries a `content_type` discriminator and a
 * `content` object nested the same way WhatsApp's is — `{"text": {"message":
 * ...}}` — but without a value-object hierarchy behind it: `text` is the only
 * documented variant today, so building a `Contracts\RcsContent` abstraction
 * for one case would be speculative. If RCS gains more content types, this is
 * the place to introduce one, mirroring {@see WhatsAppContent}.
 *
 * Unlike SMS, MMS and WhatsApp, `sender` is not a phone number here — it is a
 * registered RCS agent ID (e.g. `DemoSender`), and the constructor rejects a
 * sender that looks like a phone number before the request ever leaves the
 * process. See that check below for why a numeric agent ID is not rejected
 * outright.
 *
 * @see https://developers.kudosity.com/reference/post_v2-rcs-messages
 */
class SendRcsRequest extends KudosityV2BodyRequest
{
    use GuardsMessageRef;

    /**
     * The documented maximum for a Simple RCS message, full UTF-8.
     */
    public const MAX_MESSAGE_LENGTH = 3072;

    /**
     * @throws ValidationException If sender looks like a phone number rather than
     *                             an agent ID, or if message or message_ref exceed
     *                             their documented maximums
     */
    public function __construct(
        protected string $message,
        protected string $recipient,
        protected string $sender,
        protected ?SmsFallback $smsFallback = null,
        protected ?string $messageRef = null,
    ) {
        // The trap this class exists to close: passing a phone number as
        // `sender` fails validation upstream with a generic 400 that never
        // says "agent ID". PhoneNumber::isValid() is reused rather than a
        // second, bespoke notion of what a phone number looks like — it is
        // also the API's own E.164 rule (7-15 digits, no leading zero).
        //
        // A numeric agent ID is not rejected on sight: the docs explicitly
        // permit numeric agent IDs, so the rule checked here is "looks like a
        // valid phone number", not "is made of digits". A short numeric ID
        // such as "12345" fails isValid() on length and is accepted.
        if (PhoneNumber::isValid($sender)) {
            throw new ValidationException(
                message: sprintf(
                    'sender must be a registered RCS agent ID, not a phone number — "%s" looks like one.',
                    $sender,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new ValidationException(
                message: sprintf(
                    'message length (%d) exceeds the maximum of %d characters',
                    mb_strlen($message),
                    self::MAX_MESSAGE_LENGTH,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        self::guardMessageRef($messageRef);

        // Same reasoning as SendWhatsAppRequest: E.164 with no spaces, dashes
        // or leading `+`, and no country is assumed for a local number — the
        // remaining leading zero is left for the API to reject with a loud
        // 400 rather than guessing a country.
        $this->recipient = PhoneNumber::toInternational($recipient);
    }

    public function resolveEndpoint(): string
    {
        return '/v2/rcs/messages';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'sender' => $this->sender,
            'recipient' => $this->recipient,
            'content_type' => 'text',
            'content' => ['text' => ['message' => $this->message]],
        ];

        if ($this->smsFallback !== null) {
            $body['sms_fallback'] = $this->smsFallback->toArray();
        }

        if ($this->messageRef !== null) {
            $body['message_ref'] = $this->messageRef;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): RcsMessageData
    {
        // RCS wraps its payload like WhatsApp does: {"data": {...}}.
        // payload() resolves that, so the DTO reads data.id rather than a
        // top-level id that is not there.
        return RcsMessageData::fromArray(static::payload($response));
    }
}
