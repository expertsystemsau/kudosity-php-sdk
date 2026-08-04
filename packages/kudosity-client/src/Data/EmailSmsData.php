<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Email SMS authorization DTO.
 *
 * Returned by add-email endpoint.
 */
final readonly class EmailSmsData
{
    public function __construct(
        public string $email,
        public bool $authorized,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            email: (string) ($data['email'] ?? ''),
            authorized: (bool) ($data['authorized'] ?? true),
        );
    }
}
