<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

/**
 * Cancel a scheduled SMS message.
 *
 * @see https://developer.transmitsms.com/#cancel-sms
 */
class CancelSmsRequest extends KudosityV1Request
{
    /**
     * Create a new CancelSmsRequest.
     *
     * @param  int  $messageId  The message ID to cancel (returned from send-sms)
     */
    public function __construct(
        protected int $messageId,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->formatEndpoint('/cancel-sms');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'id' => $this->messageId,
        ];
    }
}
