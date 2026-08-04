<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\SmsStatsData;
use Saloon\Http\Response;

/**
 * Get statistics for a message or campaign that has been sent.
 *
 * @see https://developer.transmitsms.com/#get-sms-stats
 */
class GetSmsStatsRequest extends TransmitSmsRequest
{
    public function __construct(
        protected int $messageId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-sms-stats');
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

    public function createDtoFromResponse(Response $response): SmsStatsData
    {
        return SmsStatsData::fromResponse($response->json());
    }
}
