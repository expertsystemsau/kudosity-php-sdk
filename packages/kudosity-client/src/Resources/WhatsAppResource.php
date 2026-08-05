<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Data\V2\Content\CustomContent;
use ExpertSystems\Kudosity\Data\V2\Content\TemplateContent;
use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Requests\V2\GetWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\SendWhatsAppRequest;

/**
 * WhatsApp via the V2 API.
 *
 * Three send helpers, one per content variant, plus {@see self::send()} for a
 * {@see WhatsAppContent} you already hold. Which one you want is a platform
 * question rather than a stylistic one:
 *
 * - initiating a conversation, or outside the 24-hour service window →
 *   {@see self::template()}
 * - replying within 24 hours of the recipient's last message →
 *   {@see self::text()}
 * - the template needs an image, document, buttons or a carousel →
 *   {@see self::custom()}
 *
 * `$to` must be given in international form — `61411122211`, not `0411 122 211`.
 * Spaces, dashes and a leading `+` are cleaned up for you, but no country is
 * assumed: a local number keeps its leading zero and the API rejects it.
 *
 * `$from` is optional throughout: omit it and the account's registered sender is
 * used. Setting `$fallback` is worth more here than on other channels, because
 * opt-in and WhatsApp account status are outside the caller's control — a
 * perfectly valid send can fail simply because the recipient does not use
 * WhatsApp.
 */
class WhatsAppResource extends V2Resource
{
    /**
     * Send free-form text. Only delivers inside the 24-hour service window.
     *
     * @param  string  $to  Recipient in international form, e.g. `61411122211`. Punctuation
     *                      and a leading `+` are stripped; no country is assumed, so a local
     *                      number keeps its leading zero and the API rejects it.
     */
    public function text(
        string $message,
        string $to,
        ?string $from = null,
        ?SmsFallback $fallback = null,
        ?string $messageRef = null,
    ): WhatsAppMessageData {
        return $this->send(new TextContent($message), $to, $from, $fallback, $messageRef);
    }

    /**
     * Send a pre-approved template — the shape that delivers at any time.
     *
     * @param  array<int, mixed>  $parameters  Positional strings filling `{1}`, `{2}`, …
     * @param  string  $to  Recipient in international form, e.g. `61411122211`. Punctuation
     *                      and a leading `+` are stripped; no country is assumed, so a local
     *                      number keeps its leading zero and the API rejects it.
     */
    public function template(
        string $name,
        array $parameters,
        string $to,
        ?string $locale = null,
        ?string $from = null,
        ?SmsFallback $fallback = null,
        ?string $messageRef = null,
    ): WhatsAppMessageData {
        return $this->send(new TemplateContent($name, $parameters, $locale), $to, $from, $fallback, $messageRef);
    }

    /**
     * Send a raw Meta Cloud API payload — media headers, buttons, carousels.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $to  Recipient in international form, e.g. `61411122211`. Punctuation
     *                      and a leading `+` are stripped; no country is assumed, so a local
     *                      number keeps its leading zero and the API rejects it.
     */
    public function custom(
        array $payload,
        string $to,
        ?string $from = null,
        ?SmsFallback $fallback = null,
        ?string $messageRef = null,
    ): WhatsAppMessageData {
        return $this->send(new CustomContent($payload), $to, $from, $fallback, $messageRef);
    }

    /**
     * Send any content variant. `content_type` is derived from `$content`.
     *
     * @param  string  $to  Recipient in international form, e.g. `61411122211`. Punctuation
     *                      and a leading `+` are stripped; no country is assumed, so a local
     *                      number keeps its leading zero and the API rejects it.
     */
    public function send(
        WhatsAppContent $content,
        string $to,
        ?string $from = null,
        ?SmsFallback $fallback = null,
        ?string $messageRef = null,
    ): WhatsAppMessageData {
        /** @var WhatsAppMessageData */
        return $this->sendAndDto(new SendWhatsAppRequest(
            content: $content,
            recipient: $to,
            sender: $from,
            smsFallback: $fallback,
            messageRef: $messageRef,
        ));
    }

    public function get(string $id): WhatsAppMessageData
    {
        /** @var WhatsAppMessageData */
        return $this->sendAndDto(new GetWhatsAppRequest($id));
    }

    public function list(
        ?string $dateRange = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $campaignId = null,
    ): V2CursorPaginator {
        /** @var V2CursorPaginator */
        return $this->paginate(new ListWhatsAppRequest(
            dateRange: $dateRange,
            startDate: $startDate,
            endDate: $endDate,
            campaignId: $campaignId,
        ));
    }
}
