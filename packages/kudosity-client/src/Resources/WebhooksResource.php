<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\V2\EnsureResult;
use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\GetWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookIdentity;

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
     * Converge the account on one webhook registration, and report what changed.
     *
     * Idempotent: safe to call on every deploy, and free when nothing has moved.
     * Matching is by **receiver identity** — scheme, host and path, never the
     * query string — see {@see WebhookIdentity}.
     *
     * This exists because the failure worth catching is not a missing
     * registration, which is loud, but a stale one. The receiver URL carries an
     * HMAC signature; rotating the signing key, changing the route prefix or
     * moving the app leaves a registration that still exists and still receives
     * deliveries, every one of which the receiver then rejects — silently,
     * because Kudosity has no channel to report that your endpoint refused it.
     * A "does one exist?" check passes in every one of those cases.
     *
     * Never deletes, and never touches a registration whose identity differs.
     *
     * @param  array<int, WebhookEventType|string>  $eventTypes
     *
     * @throws KudosityException
     */
    public function ensure(
        string $name,
        string $url,
        array $eventTypes = [],
        ?WebhookFilter $filter = null,
        ?int $rateLimit = null,
        bool $allowInsecureUrl = false,
    ): EnsureResult {
        // Up front, not left to create()/update(): on the unchanged path no write
        // request is built, so a guard living only in the request classes would let
        // an existing plaintext registration return Unchanged forever.
        CreateWebhookRequest::guardUrl($url, $allowInsecureUrl);

        $desired = self::mergeEventTypes($filter, $eventTypes);
        $identity = WebhookIdentity::of($url);

        $matches = array_values(array_filter(
            $this->all(),
            static fn (WebhookData $hook): bool => WebhookIdentity::of($hook->url) === $identity,
        ));

        if ($matches === []) {
            return new EnsureResult(
                EnsureAction::Created,
                $this->create(
                    name: $name,
                    url: $url,
                    filter: $desired,
                    rateLimit: $rateLimit,
                    allowInsecureUrl: $allowInsecureUrl,
                ),
            );
        }

        $existing = array_shift($matches);

        if (self::matchesDesired($existing, $name, $url, $desired, $rateLimit)) {
            return new EnsureResult(EnsureAction::Unchanged, $existing, $matches);
        }

        return new EnsureResult(
            EnsureAction::Updated,
            $this->update(
                id: $existing->id,
                name: $name,
                url: $url,
                filter: $desired,
                rateLimit: $rateLimit,
                allowInsecureUrl: $allowInsecureUrl,
            ),
            $matches,
        );
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

    /**
     * Whether a registration already expresses the desired state.
     *
     * `$rateLimit` is compared **only when the caller supplied one**. The API
     * echoes `0` to mean "system default" rather than "no requests allowed"
     * ({@see WebhookData::hasRateLimit()}), so treating a caller's `null` as a
     * mismatch against a stored `0` would make every single deploy issue a `PUT`
     * that changes nothing — and the unchanged path would then never run in
     * production.
     */
    private static function matchesDesired(
        WebhookData $existing,
        string $name,
        string $url,
        ?WebhookFilter $desired,
        ?int $rateLimit,
    ): bool {
        if ($existing->name !== $name || $existing->url !== $url) {
            return false;
        }

        if (self::comparableFilter($existing->filter) !== self::comparableFilter($desired)) {
            return false;
        }

        return $rateLimit === null || $existing->rateLimit === $rateLimit;
    }

    /**
     * A filter reduced to something `===` can compare.
     *
     * Filter values are sets, not sequences — the API ORs within one array — so
     * reordering event types in application code must not read as drift. Sorted
     * and keyed for that reason, and an absent filter compares equal to an empty
     * one because both mean "every event".
     *
     * @return array<string, array<int, string>>
     */
    private static function comparableFilter(?WebhookFilter $filter): array
    {
        $comparable = $filter?->toArray() ?? [];

        foreach ($comparable as $key => $values) {
            sort($values);
            $comparable[$key] = array_values(array_unique($values));
        }

        ksort($comparable);

        return $comparable;
    }
}
