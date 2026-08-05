<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use ExpertSystems\Kudosity\Support\Url;
use Saloon\Http\Response;

/**
 * Send a single-recipient MMS.
 *
 * `POST /v2/mms` takes exactly one recipient, one media file, and the API
 * currently only delivers to Australia — but that last constraint is not
 * enforced here. Whether a given recipient can receive MMS is the API's
 * judgement to make, not the SDK's, so a non-AU recipient is not rejected
 * client-side.
 *
 * @see https://developers.kudosity.com/reference/post_v2-mms
 */
class SendMmsRequest extends KudosityV2BodyRequest
{
    /**
     * Only one media file can be attached per message.
     */
    public const MAX_CONTENT_URLS = 1;

    /**
     * `subject` is documented as ASCII-only, max 20 characters.
     */
    public const MAX_SUBJECT_LENGTH = 20;

    /**
     * The documented maximum for the message body.
     */
    public const MAX_MESSAGE_LENGTH = 1000;

    /**
     * The documented maximum for the caller's own reference field.
     */
    public const MAX_MESSAGE_REF_LENGTH = 500;

    /**
     * @param  array<int, string>  $contentUrls
     *
     * @throws ValidationException If content_urls is empty, has more than one
     *                             URL, contains a non-absolute URL, or if
     *                             subject, message or message_ref exceed
     *                             their documented limits
     */
    public function __construct(
        protected string $recipient,
        protected string $sender,
        protected array $contentUrls,
        protected ?string $subject = null,
        protected ?string $message = null,
        protected ?string $messageRef = null,
        protected bool $trackLinks = false,
    ) {
        if ($contentUrls === []) {
            throw new ValidationException(
                message: 'content_urls cannot be empty',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if (count($contentUrls) > self::MAX_CONTENT_URLS) {
            throw new ValidationException(
                message: sprintf(
                    'Only one media file can be attached per message, %d given',
                    count($contentUrls),
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        foreach ($contentUrls as $contentUrl) {
            Url::validate($contentUrl, 'content_urls');
        }

        if ($subject !== null) {
            if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                throw new ValidationException(
                    message: sprintf(
                        'subject length (%d) exceeds the maximum of %d characters',
                        mb_strlen($subject),
                        self::MAX_SUBJECT_LENGTH,
                    ),
                    errorCode: 'FIELD_INVALID',
                );
            }

            if (preg_match('/[^\x00-\x7F]/', $subject) === 1) {
                throw new ValidationException(
                    message: 'subject must be ASCII only',
                    errorCode: 'FIELD_INVALID',
                );
            }
        }

        if ($message !== null && mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new ValidationException(
                message: sprintf(
                    'message length (%d) exceeds the maximum of %d characters',
                    mb_strlen($message),
                    self::MAX_MESSAGE_LENGTH,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        if ($messageRef !== null && mb_strlen($messageRef) > self::MAX_MESSAGE_REF_LENGTH) {
            throw new ValidationException(
                message: sprintf(
                    'message_ref length (%d) exceeds the maximum of %d characters',
                    mb_strlen($messageRef),
                    self::MAX_MESSAGE_REF_LENGTH,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/mms';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'sender' => $this->sender,
            'recipient' => $this->recipient,
            'content_urls' => $this->contentUrls,
        ];

        if ($this->subject !== null) {
            $body['subject'] = $this->subject;
        }

        if ($this->message !== null) {
            $body['message'] = $this->message;
        }

        if ($this->messageRef !== null) {
            $body['message_ref'] = $this->messageRef;
        }

        if ($this->trackLinks) {
            $body['track_links'] = true;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): MmsMessageData
    {
        // payload() comes from UnwrapsData on the base. MMS is flat, but going
        // through it keeps every V2 request identical regardless of envelope.
        return MmsMessageData::fromArray(static::payload($response));
    }
}
