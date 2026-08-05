<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Requests\V2\GetMmsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendMmsRequest;

/**
 * Single-recipient MMS via the V2 API.
 *
 * `POST /v2/mms` takes exactly one recipient and one media file. The API
 * currently only delivers MMS to Australian numbers, but that is not
 * enforced here — whether a given recipient can receive MMS is the API's
 * judgement to make, not the SDK's.
 */
class MmsResource extends V2Resource
{
    /**
     * @param  array<int, string>  $contentUrls
     */
    public function send(
        string $to,
        string $from,
        array $contentUrls,
        ?string $subject = null,
        ?string $message = null,
        ?string $messageRef = null,
        bool $trackLinks = false,
    ): MmsMessageData {
        /** @var MmsMessageData */
        return $this->sendAndDto(new SendMmsRequest(
            recipient: $to,
            sender: $from,
            contentUrls: $contentUrls,
            subject: $subject,
            message: $message,
            messageRef: $messageRef,
            trackLinks: $trackLinks,
        ));
    }

    public function get(string $id): MmsMessageData
    {
        /** @var MmsMessageData */
        return $this->sendAndDto(new GetMmsRequest($id));
    }
}
