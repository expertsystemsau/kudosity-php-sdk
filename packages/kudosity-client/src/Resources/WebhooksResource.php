<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\GetWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;

/**
 * Account-level webhooks, managed over the API.
 *
 * **Architecturally different from V1's callbacks.** V1 attaches a
 * `dlr_callback` / `reply_callback` URL to each send; V2 has no per-send callback
 * URL at all. Registrations here are account-level resources, and one of them can
 * serve every channel.
 *
 * That difference is a migration trap rather than a detail: a consumer moving a
 * send from V1 to V2 **loses their callbacks silently** unless they register a
 * webhook. The V1 callbacks keep working, but only for V1 sends.
 *
 * Deliveries arrive as {@see WebhookEvent}
 * objects. They are unsigned — see that class — so
 * {@see SignedMessageRef} is how a receiver
 * gets any authenticity signal at all.
 */
class WebhooksResource extends V2Resource
{
    /**
     * Register a webhook.
     *
     * Event types are passed as their own parameter rather than buried in a
     * filter, because subscribing to specific events is the common case and
     * `filter.event_type` is the only correct place to put them — the top-level
     * `event_type` field is deprecated upstream and silently ignored.
     *
     * Pass no event types to receive **every** event type.
     *
     * @param  array<int, WebhookEventType|string>  $eventTypes
     *
     * @throws KudosityException
     */
    public function create(
        string $name,
        string $url,
        array $eventTypes = [],
        ?WebhookFilter $filter = null,
        ?int $rateLimit = null,
        bool $allowInsecureUrl = false,
    ): WebhookData {
        /** @var WebhookData */
        return $this->sendAndDto(new CreateWebhookRequest(
            name: $name,
            url: $url,
            filter: self::mergeEventTypes($filter, $eventTypes),
            rateLimit: $rateLimit,
            allowInsecureUrl: $allowInsecureUrl,
        ));
    }

    /**
     * Every webhook registered to the account.
     *
     * An empty array when there are none — the API answers `{}` in that case,
     * omitting the collection key entirely.
     *
     * @return array<int, WebhookData>
     *
     * @throws KudosityException
     */
    public function all(): array
    {
        /** @var array<int, WebhookData> */
        return $this->sendAndDto(new ListWebhooksRequest);
    }

    /**
     * Read one registration by id.
     *
     * @throws KudosityException If the registration does not exist
     */
    public function get(string $id): WebhookData
    {
        /** @var WebhookData */
        return $this->sendAndDto(new GetWebhookRequest($id));
    }

    /**
     * Replace a registration.
     *
     * **Every field is required, because `PUT` replaces rather than patches.**
     * Omitting the name does not preserve it — the API answers 400. To change one
     * field, read the registration first and pass the rest back:
     *
     * ```php
     * $hook = $k->webhooks()->get($id);
     * $k->webhooks()->update($id, $hook->name, $newUrl, filter: $hook->filter, rateLimit: $hook->rateLimit);
     * ```
     *
     * @param  array<int, WebhookEventType|string>  $eventTypes
     *
     * @throws KudosityException
     */
    public function update(
        string $id,
        string $name,
        string $url,
        array $eventTypes = [],
        ?WebhookFilter $filter = null,
        ?int $rateLimit = null,
        bool $allowInsecureUrl = false,
    ): WebhookData {
        /** @var WebhookData */
        return $this->sendAndDto(new UpdateWebhookRequest(
            id: $id,
            name: $name,
            url: $url,
            filter: self::mergeEventTypes($filter, $eventTypes),
            rateLimit: $rateLimit,
            allowInsecureUrl: $allowInsecureUrl,
        ));
    }

    /**
     * Delete a registration.
     *
     * True on success. The endpoint answers 200 with no useful body, so there is
     * nothing to return but the outcome — and a failure throws rather than
     * returning false, so a false here would be unreachable.
     *
     * @throws KudosityException If the registration does not exist
     */
    public function delete(string $id): bool
    {
        $response = $this->connector->send(new DeleteWebhookRequest($id));

        $response->throw();

        return $response->successful();
    }

    /**
     * Fold the event-type shortcut into the filter.
     *
     * Explicit event types win over a filter's own, on the grounds that the
     * shortcut is the more specific expression of intent — but only when the
     * shortcut is actually used.
     *
     * @param  array<int, WebhookEventType|string>  $eventTypes
     */
    private static function mergeEventTypes(?WebhookFilter $filter, array $eventTypes): ?WebhookFilter
    {
        if ($eventTypes === []) {
            return $filter;
        }

        $filter ??= new WebhookFilter;

        return new WebhookFilter(
            eventType: $eventTypes,
            sender: $filter->sender,
            status: $filter->status,
            messageRef: $filter->messageRef,
            campaignId: $filter->campaignId,
        );
    }
}
