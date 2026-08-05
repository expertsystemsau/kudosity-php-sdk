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
     * Build from a payload that is required to carry a message.
     *
     * Throws when `message` is absent or empty, because the constructor's
     * invariant holds here too. For a payload read back off a **response**, use
     * {@see self::fromResponse()} instead — a response is not ours to police.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException If the payload carries no usable message
     */
    public static function fromArray(array $data): self
    {
        return new self(
            message: is_string($data['message'] ?? null) ? $data['message'] : '',
            sender: is_string($data['sender'] ?? null) ? $data['sender'] : null,
        );
    }

    /**
     * Build from a response payload, or null when there is nothing usable.
     *
     * The read path, shared by every channel that echoes `sms_fallback` back —
     * WhatsApp and RCS. It exists because this class's constructor rightly
     * rejects an empty message: correct for the request-shaped object it
     * primarily is, but wrong to impose on a response, where it would make
     * reading a message back throw over a field the caller may not even want.
     * The invariant is deliberately left intact and this path added beside it.
     *
     * **The trade-off, stated once and in one place:** a fallback whose message
     * cannot be represented reads as *"there was no fallback"* rather than
     * *"there was a fallback we could not parse"*. That is information loss. It
     * is the safe direction for a read path — a null is inspectable, a thrown
     * exception mid-hydration is not — and it is deliberate.
     *
     * A message-less fallback should not occur in practice: the send side
     * requires a non-empty message and this class's own constructor enforces it,
     * so what comes back has one. This path is for malformed payloads, not
     * normal ones.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): ?self
    {
        $message = $data['message'] ?? null;

        if (! is_string($message) || $message === '') {
            return null;
        }

        return new self(
            message: $message,
            sender: is_string($data['sender'] ?? null) ? $data['sender'] : null,
        );
    }
}
