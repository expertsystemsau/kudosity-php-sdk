<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * One entry of an RFC 9457 `issues[]` array.
 *
 * The V2 API reports every failed field in a single response rather than one
 * per attempt, so a validation failure is a list of these.
 */
final readonly class ProblemIssue
{
    public function __construct(
        public string $name,
        public string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $issue
     */
    public static function fromArray(array $issue): self
    {
        return new self(
            name: is_string($issue['name'] ?? null) ? $issue['name'] : '',
            message: is_string($issue['message'] ?? null) ? $issue['message'] : '',
        );
    }
}
