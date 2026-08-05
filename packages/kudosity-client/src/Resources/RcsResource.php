<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\V2\RcsCapabilityData;
use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Enums\RcsCapabilityCode;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Requests\V2\CheckRcsCapabilitiesRequest;
use ExpertSystems\Kudosity\Requests\V2\GetRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListRcsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendRcsRequest;

/**
 * RCS via the V2 API.
 *
 * The one thing that trips people up: `$agentId` is a registered RCS agent
 * ID, not a phone number — `DemoSender`, not `61411122211`. Passing a phone
 * number is rejected before the request leaves the process rather than left
 * for the API's generic validation error.
 *
 * Set `$fallback` on almost every send. Not every handset supports RCS and
 * carrier delivery can fail outright, and unlike WhatsApp's opt-in
 * requirement this is not something the caller can check in advance — the
 * skill is emphatic that `sms_fallback` is what turns a silent non-delivery
 * into an actual message reaching the recipient. It is not required here
 * because the API does not require it either.
 */
class RcsResource extends V2Resource
{
    /**
     * Send an RCS message.
     *
     * @param  string  $to  Recipient in international form, e.g. `61411122211`. Punctuation
     *                      and a leading `+` are stripped; no country is assumed, so a local
     *                      number keeps its leading zero and the API rejects it.
     * @param  string  $agentId  Your registered RCS agent ID (e.g. `DemoSender`) — never a
     *                           phone number. A phone-number-shaped value is rejected before
     *                           the request is sent.
     */
    public function send(
        string $message,
        string $to,
        string $agentId,
        ?SmsFallback $fallback = null,
        ?string $messageRef = null,
    ): RcsMessageData {
        /** @var RcsMessageData */
        return $this->sendAndDto(new SendRcsRequest(
            message: $message,
            recipient: $to,
            sender: $agentId,
            smsFallback: $fallback,
            messageRef: $messageRef,
        ));
    }

    public function get(string $id): RcsMessageData
    {
        /** @var RcsMessageData */
        return $this->sendAndDto(new GetRcsRequest($id));
    }

    public function list(
        ?string $dateRange = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): V2CursorPaginator {
        /** @var V2CursorPaginator */
        return $this->paginate(new ListRcsRequest(
            dateRange: $dateRange,
            startDate: $startDate,
            endDate: $endDate,
        ));
    }

    /**
     * Check whether numbers can receive RCS from a given agent.
     *
     * Capability is per agent, so `$agentId` is required here even though it
     * is not on {@see self::get()} — a check that omits it is meaningless,
     * since a number reachable for one agent is not guaranteed reachable for
     * another. Up to 100 numbers per request; the docs recommend batches of
     * 1-10 to keep routing-time lookups fast, but that is not enforced here.
     *
     * Do not treat the result as a hard gate on sending — see
     * {@see RcsCapabilityCode::isReachable()}.
     * Results are best-effort and go stale, so re-check rather than caching
     * indefinitely.
     *
     * @param  array<int, string>  $phoneNumbers  Up to 100 numbers, E.164 without a leading `+`.
     * @return array<int, RcsCapabilityData> One result per number, in request order.
     */
    public function capabilities(array $phoneNumbers, string $agentId): array
    {
        /** @var array<int, RcsCapabilityData> */
        return $this->sendAndDto(new CheckRcsCapabilitiesRequest($phoneNumbers, $agentId));
    }
}
