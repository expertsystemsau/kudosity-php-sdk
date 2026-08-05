<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Data\V2\SmsListData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
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
 * @see https://developers.kudosity.com/reference/get_v2-sms
 */
class ListSmsV2Request extends KudosityV2Request implements PaginatesV2Pages
{
    protected Method $method = Method::GET;

    public function __construct(
        protected ?MessageStatus $status = null,
        protected ?string $recipient = null,
        protected ?string $sender = null,
        protected ?string $messageRef = null,
        protected ?string $direction = null,
        protected ?string $startDate = null,
        protected ?string $endDate = null,
    ) {}

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

        if ($this->startDate !== null) {
            $query['start_date'] = $this->startDate;
        }

        if ($this->endDate !== null) {
            $query['end_date'] = $this->endDate;
        }

        return $query;
    }

    public function createDtoFromResponse(Response $response): SmsListData
    {
        return SmsListData::fromArray(static::payload($response));
    }
}
