<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Delete an authorized email address.
 *
 * @see https://developers.kudosity.com
 */
class DeleteEmailRequest extends KudosityV1Request
{
    public function __construct(
        protected string $email,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('delete-email');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'email' => $this->email,
        ];
    }
}
