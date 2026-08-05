<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;
use ExpertSystems\Kudosity\Requests\V2\GetSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\ListSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\SendSmsV2Request;

/**
 * Single-recipient SMS via the V2 API.
 *
 * `POST /v2/sms` takes exactly one recipient and cannot schedule a future
 * send. Sending to a contact list, to multiple comma-separated numbers, or at
 * a future time is V1 territory — use `$client->bulk()` instead. Reaching for
 * this resource for one of those is the most likely mistake a consumer
 * arriving from 1.x will make.
 */
class SmsV2Resource extends V2Resource
{
    public function send(
        string $message,
        string $to,
        string $from,
        ?string $messageRef = null,
        bool $trackLinks = false,
    ): SmsMessageData {
        /** @var SmsMessageData */
        return $this->sendAndDto(new SendSmsV2Request(
            message: $message,
            recipient: $to,
            sender: $from,
            messageRef: $messageRef,
            trackLinks: $trackLinks,
        ));
    }

    public function get(string $id): SmsMessageData
    {
        /** @var SmsMessageData */
        return $this->sendAndDto(new GetSmsV2Request($id));
    }

    public function list(
        ?MessageStatus $status = null,
        ?string $recipient = null,
        ?string $sender = null,
        ?string $messageRef = null,
        ?string $direction = null,
    ): V2PagedPaginator {
        /** @var V2PagedPaginator */
        return $this->paginate(new ListSmsV2Request(
            status: $status,
            recipient: $recipient,
            sender: $sender,
            messageRef: $messageRef,
            direction: $direction,
        ));
    }
}
