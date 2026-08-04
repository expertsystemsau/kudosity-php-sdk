<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Opt out a contact from a list.
 *
 * @see https://developer.transmitsms.com/#optout-list-member
 */
class OptoutListMemberRequest extends TransmitSmsRequest
{
    public function __construct(
        protected int $listId,
        protected string $mobile,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('optout-list-member');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'list_id' => $this->listId,
            'msisdn' => $this->mobile,
        ];
    }
}
