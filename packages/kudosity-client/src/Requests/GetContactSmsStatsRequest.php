<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use DateTimeInterface;
use ExpertSystems\Kudosity\Contracts\PaginatesResults;
use ExpertSystems\Kudosity\Data\ContactSmsStatsData;
use ExpertSystems\Kudosity\Resources\ReportingResource;
use Saloon\Http\Response;

/**
 * Get SMS statistics for a specific contact/mobile number.
 *
 * @see https://developers.kudosity.com
 */
class GetContactSmsStatsRequest extends KudosityV1Request implements PaginatesResults
{
    protected ?string $countryCode = null;

    protected ?string $start = null;

    protected ?string $end = null;

    public function __construct(
        protected string $mobile,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-contact-sms-stats');
    }

    /**
     * Set the country code for formatting local numbers.
     */
    public function countryCode(string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    /**
     * Set the start date filter.
     */
    public function from(string|DateTimeInterface $start): self
    {
        $this->start = $start instanceof DateTimeInterface
            ? $start->format('Y-m-d')
            : $start;

        return $this;
    }

    /**
     * Set the end date filter.
     */
    public function to(string|DateTimeInterface $end): self
    {
        $this->end = $end instanceof DateTimeInterface
            ? $end->format('Y-m-d')
            : $end;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'mobile' => $this->mobile,
        ];

        if ($this->countryCode !== null) {
            $body['countrycode'] = $this->countryCode;
        }

        if ($this->start !== null) {
            $body['start'] = $this->start;
        }

        if ($this->end !== null) {
            $body['end'] = $this->end;
        }

        return $body;
    }

    /**
     * The response key holding the per-message records.
     *
     * Not `stats`: this endpoint returns `{page, total, records[]}` regardless
     * of what its name suggests.
     */
    public function paginationItemsKey(): string
    {
        return 'records';
    }

    /**
     * @deprecated 2.2.0 The endpoint returns a paginated record list, not the
     *             aggregate shape {@see ContactSmsStatsData} models, so this
     *             throws on every real response. Use
     *             {@see ReportingResource::getContactRecords()}
     *             for the records, or `getContactStats()` for a summary
     *             counted from them.
     */
    public function createDtoFromResponse(Response $response): ContactSmsStatsData
    {
        return ContactSmsStatsData::fromResponse($response->json());
    }
}
