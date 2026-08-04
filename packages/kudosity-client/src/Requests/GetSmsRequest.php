<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\MessageData;
use Saloon\Http\Response;

/**
 * Get information about a message or campaign that has been sent.
 *
 * @see https://developer.transmitsms.com/#get-sms
 */
class GetSmsRequest extends KudosityV1Request
{
    public function __construct(
        protected int $messageId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-sms');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'message_id' => $this->messageId,
        ];
    }

    public function createDtoFromResponse(Response $response): MessageData
    {
        return MessageData::fromResponse($response->json());
    }
}
