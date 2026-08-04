<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Data\DeliveryStatusData;
use Saloon\Http\Response;

/**
 * Get delivery status for a specific message to a specific recipient.
 *
 * @see https://developers.kudosity.com
 */
class GetSmsDeliveryStatusRequest extends KudosityV1Request
{
    public function __construct(
        protected int $messageId,
        protected string $mobile,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('get-sms-delivery-status');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'message_id' => $this->messageId,
            'mobile' => $this->mobile,
        ];
    }

    public function createDtoFromResponse(Response $response): DeliveryStatusData
    {
        return DeliveryStatusData::fromResponse($response->json());
    }
}
