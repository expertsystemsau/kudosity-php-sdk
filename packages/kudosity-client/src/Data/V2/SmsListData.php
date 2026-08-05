<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2;

/**
 * A page of SMS messages, as returned by `GET /v2/sms`.
 *
 * Distinct from {@see \ExpertSystems\Kudosity\Data\SmsListData}, which is a
 * V1 shape (a contact list's id and name) entirely unrelated to this one.
 */
final readonly class SmsListData
{
    /**
     * @param  array<int, SmsMessageData>  $messages
     */
    public function __construct(
        public array $messages,
        public int $totalRecords,
        public int $totalSegments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $messages = is_array($data['smses'] ?? null) ? $data['smses'] : [];

        return new self(
            messages: array_map(
                static fn (mixed $message): SmsMessageData => SmsMessageData::fromArray(is_array($message) ? $message : []),
                $messages
            ),
            totalRecords: (int) ($data['total_records'] ?? 0),
            totalSegments: (int) ($data['total_segments'] ?? 0),
        );
    }
}
