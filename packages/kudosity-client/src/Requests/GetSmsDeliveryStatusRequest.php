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
            // The live API requires 'msisdn', not 'mobile' — confirmed
            // 2026-08-08: sending 'mobile' answers
            // {"error":{"code":"FIELD_EMPTY","description":"Field msisdn is
            // required"}} on every call. The *response* nests the recipient
            // back under 'mobile' (see DeliveryStatusData), so the request
            // and response deliberately disagree on the field name.
            'msisdn' => $this->mobile,
        ];
    }

    public function createDtoFromResponse(Response $response): DeliveryStatusData
    {
        return DeliveryStatusData::fromResponse($response->json());
    }
}
