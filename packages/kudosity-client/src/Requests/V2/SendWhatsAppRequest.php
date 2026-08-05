<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Concerns\GuardsMessageRef;
use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use ExpertSystems\Kudosity\Support\PhoneNumber;
use Saloon\Http\Response;

/**
 * Send a WhatsApp message.
 *
 * `POST /v2/whatsapp/messages` carries a `content_type` discriminator and a
 * `content` object whose single key repeats it. Both are derived from one
 * {@see WhatsAppContent} rather than passed separately, so they cannot
 * disagree and the flattened `content.name` shape the docs warn about is not
 * expressible here.
 *
 * Two platform rules decide whether the send is even allowed, and neither is
 * enforceable client-side: the recipient must have opted in, and free-form text
 * only delivers inside the 24-hour service window. A send outside the window
 * answers 200 and never arrives, so use a template whenever you are initiating
 * the conversation.
 *
 * @see https://developers.kudosity.com/reference/post_v2-whatsapp-messages
 */
class SendWhatsAppRequest extends KudosityV2BodyRequest
{
    use GuardsMessageRef;

    /**
     * @throws ValidationException If message_ref exceeds its documented maximum
     */
    public function __construct(
        protected WhatsAppContent $content,
        protected string $recipient,
        protected ?string $sender = null,
        protected ?SmsFallback $smsFallback = null,
        protected ?string $messageRef = null,
    ) {
        self::guardMessageRef($messageRef);

        // The endpoint wants E.164 with no spaces, dashes or leading `+`, and a
        // recipient carrying any of those is a 400. Normalising here means a
        // number pasted out of a CRM is never sent raw.
        //
        // No country is passed, deliberately. A leading-zero local number
        // cannot be resolved to E.164 without knowing the country, and the only
        // way to supply one here would be a default — which on a global channel
        // means prepending 61 to a number the caller typed for somewhere else.
        // That is a wrong send rather than a failed one. Punctuation goes; the
        // remaining zero is left for the API to reject with a 400, which is
        // loud. Do not "fix" this into a country default.
        $this->recipient = PhoneNumber::toInternational($recipient);
    }

    public function resolveEndpoint(): string
    {
        return '/v2/whatsapp/messages';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'recipient' => $this->recipient,
            'content_type' => $this->content->contentType(),
            'content' => $this->content->toArray(),
        ];

        // Omitted rather than defaulted: the account's registered sender is
        // used automatically when `sender` is absent, and a placeholder would
        // fail validation instead. Accounts with more than one sender must pass
        // it explicitly.
        if ($this->sender !== null) {
            $body['sender'] = $this->sender;
        }

        if ($this->smsFallback !== null) {
            $body['sms_fallback'] = $this->smsFallback->toArray();
        }

        if ($this->messageRef !== null) {
            $body['message_ref'] = $this->messageRef;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): WhatsAppMessageData
    {
        // Unlike SMS and MMS, WhatsApp really does wrap its payload:
        // {"data": {...}}. payload() resolves that, so the DTO reads data.id
        // rather than a top-level id that is not there.
        return WhatsAppMessageData::fromArray(static::payload($response));
    }
}
