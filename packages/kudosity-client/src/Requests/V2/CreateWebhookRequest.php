<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Register an account-level webhook.
 *
 * Answers **201**, not 200. The response is flat rather than `data`-wrapped.
 *
 * ## `filter.event_type`, never a top-level `event_type`
 *
 * The top-level field is deprecated upstream. This class does not send it at
 * all, which matters because sending it *looks* like it works — the request
 * succeeds and the registration is created, just not filtered the way the caller
 * asked.
 *
 * ## HTTPS is required here, and this is stricter than the platform
 *
 * The documentation states webhook URLs must use HTTPS. **The API does not
 * enforce it** — a probe registering `http://example.com/x` returned 201. This
 * class rejects it anyway, for two reasons: deliveries carry message text and
 * phone numbers, and they are unsigned, so a plaintext endpoint is both readable
 * and forgeable by anyone on the path. A loud failure at registration beats
 * years of quiet plaintext delivery.
 *
 * That makes this the one place the SDK is deliberately stricter than the
 * platform. If a caller has a genuine need for a plaintext receiver, this guard
 * is the thing to revisit — not something to work around by calling the endpoint
 * directly.
 *
 * @see https://developers.kudosity.com/reference/post_v2-webhook
 */
class CreateWebhookRequest extends KudosityV2BodyRequest
{
    /** Documented bounds on the registration name. */
    public const MIN_NAME_LENGTH = 2;

    public const MAX_NAME_LENGTH = 100;

    /** Documented ceiling on deliveries per second; 0 means the system default. */
    public const MAX_RATE_LIMIT = 10_000;

    protected Method $method = Method::POST;

    /**
     * @throws ValidationException If the name is outside its documented length, the
     *                             URL is not HTTPS, or the rate limit is out of range
     */
    public function __construct(
        protected string $name,
        protected string $url,
        protected ?WebhookFilter $filter = null,
        protected ?int $rateLimit = null,
    ) {
        self::guardName($name);
        self::guardUrl($url);
        self::guardRateLimit($rateLimit);
    }

    public function resolveEndpoint(): string
    {
        return '/v2/webhook';
    }

    /**
     * @throws ValidationException
     */
    public static function guardName(string $name): void
    {
        // mb_strlen: the API counts characters, and the error it returns names
        // the same bounds this does.
        $length = mb_strlen($name);

        if ($length < self::MIN_NAME_LENGTH || $length > self::MAX_NAME_LENGTH) {
            throw new ValidationException(
                message: sprintf(
                    'Webhook name length (%d) must be between %d and %d characters.',
                    $length,
                    self::MIN_NAME_LENGTH,
                    self::MAX_NAME_LENGTH,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    /**
     * @throws ValidationException
     */
    public static function guardUrl(string $url): void
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new ValidationException(
                message: sprintf(
                    'Webhook URL must use HTTPS; "%s" given. Deliveries carry message content and are '.
                    'unsigned, so a plaintext endpoint is readable and forgeable in transit.',
                    $url,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    /**
     * @throws ValidationException
     */
    public static function guardRateLimit(?int $rateLimit): void
    {
        if ($rateLimit === null) {
            return;
        }

        if ($rateLimit < 0 || $rateLimit > self::MAX_RATE_LIMIT) {
            throw new ValidationException(
                message: sprintf(
                    'Webhook rate_limit (%d) must be between 0 and %d; 0 means the system default.',
                    $rateLimit,
                    self::MAX_RATE_LIMIT,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'url' => $this->url,
        ];

        // Omitted rather than sent empty: an absent filter means every event
        // type, and `"filter": {}` is a different request from no filter at all.
        if ($this->filter !== null && ! $this->filter->isEmpty()) {
            $body['filter'] = $this->filter->toArray();
        }

        if ($this->rateLimit !== null) {
            $body['rate_limit'] = $this->rateLimit;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): WebhookData
    {
        return WebhookData::fromArray(static::payload($response));
    }
}
