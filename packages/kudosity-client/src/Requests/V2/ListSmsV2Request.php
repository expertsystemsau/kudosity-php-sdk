<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Data\V2\SmsListData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * List SMS messages, filterable and paged.
 *
 * `GET /v2/sms` pages by `page`/`limit`, both added by {@see V2PagedPaginator}
 * — the filters below are the only query parameters this class contributes
 * itself.
 *
 * The documented filter set for this endpoint is exactly `status`,
 * `recipient`, `sender`, `message_ref` and `direction`. Neither the vendored
 * skill nor the migration design spec documents a date-range filter for SMS —
 * `date_range`/`custom_date` belong to the WhatsApp and RCS list endpoints
 * instead. Do not add `start_date`/`end_date` here speculatively: an
 * unsupported query param is silently ignored by the API, which is worse
 * than not offering the filter — the caller believes their results are
 * date-filtered when they are not.
 *
 * @see https://developers.kudosity.com/reference/get_v2-sms
 */
class ListSmsV2Request extends KudosityV2Request implements PaginatesV2Pages
{
    /**
     * The 13 values `GET /v2/sms`'s `status` filter documents.
     *
     * {@see MessageStatus} is deliberately the union of three vocabularies, so
     * it also offers `QUEUED` (WhatsApp/RCS lists only) and `UNKNOWN` (this
     * SDK's own sentinel for values the docs have not published — never
     * something the API accepts as a filter). Both are excluded here; see
     * {@see MessageStatus} for the full picture and the "Message status
     * subsets" table in the README's "V2 channels" section for which
     * endpoint emits which subset.
     *
     * @var array<int, string>
     */
    public const FILTERABLE_STATUSES = [
        'PENDING', 'SENT', 'FAILED', 'DELIVERED', 'ACCEPTED', 'SOFT_BOUNCE',
        'HARD_BOUNCE', 'OTHER', 'REJECTED', 'PENDING_APPROVAL', 'SUBMITTED',
        'UNDELIVERABLE', 'READ',
    ];

    protected Method $method = Method::GET;

    /**
     * @throws ValidationException If status is not in this endpoint's documented filter vocabulary
     */
    public function __construct(
        protected ?MessageStatus $status = null,
        protected ?string $recipient = null,
        protected ?string $sender = null,
        protected ?string $messageRef = null,
        protected ?string $direction = null,
    ) {
        if ($status !== null && ! in_array($status->value, self::FILTERABLE_STATUSES, true)) {
            throw new ValidationException(
                message: sprintf(
                    'status must be one of %s; "%s" given.',
                    implode(', ', self::FILTERABLE_STATUSES),
                    $status->value,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/sms';
    }

    public function paginationItemsKey(): string
    {
        return 'smses';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        $query = [];

        if ($this->status !== null) {
            $query['status'] = $this->status->value;
        }

        if ($this->recipient !== null) {
            $query['recipient'] = $this->recipient;
        }

        if ($this->sender !== null) {
            $query['sender'] = $this->sender;
        }

        if ($this->messageRef !== null) {
            $query['message_ref'] = $this->messageRef;
        }

        if ($this->direction !== null) {
            $query['direction'] = $this->direction;
        }

        return $query;
    }

    public function createDtoFromResponse(Response $response): SmsListData
    {
        return SmsListData::fromArray(static::payload($response));
    }
}
