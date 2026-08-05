<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * The `sms_fallback` object accepted by the WhatsApp and RCS send endpoints.
 *
 * When the rich leg cannot be delivered, an SMS goes instead. `message` is
 * required whenever the object is present; `sender` is optional but should be
 * a sender registered to the account.
 *
 * The fallback is billed as a real SMS and is subject to SMS length limits, so
 * write a separate shorter body rather than reusing a 3072-character RCS one.
 */
final readonly class SmsFallback
{
    /**
     * @throws ValidationException If the message is empty
     */
    public function __construct(
        public string $message,
        public ?string $sender = null,
    ) {
        if ($message === '') {
            throw new ValidationException(
                message: 'An sms_fallback message is required when a fallback is given.',
                errorCode: 'FIELD_EMPTY',
            );
        }
    }

    /**
     * @return array{message: string, sender?: string}
     */
    public function toArray(): array
    {
        $body = ['message' => $this->message];

        if ($this->sender !== null) {
            $body['sender'] = $this->sender;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            message: is_string($data['message'] ?? null) ? $data['message'] : '',
            sender: is_string($data['sender'] ?? null) ? $data['sender'] : null,
        );
    }
}
