<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests\V2;

use ExpertSystems\Kudosity\Data\V2\RcsCapabilityData;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;
use ExpertSystems\Kudosity\Support\PhoneNumber;
use Saloon\Http\Response;

/**
 * Check whether a set of numbers can receive RCS from a given agent.
 *
 * `POST /v2/rcs/capabilities` is a per-agent question — a number reachable
 * for one agent is not guaranteed reachable for another — so `sender` is
 * required alongside `phone_numbers`, not optional the way it is on a send.
 *
 * The response is one result per number, in request order, under
 * `data.results`; `createDtoFromResponse()` returns that list directly rather
 * than a single DTO.
 *
 * @see https://developers.kudosity.com/reference/post_v2-rcs-capabilities
 */
class CheckRcsCapabilitiesRequest extends KudosityV2BodyRequest
{
    /**
     * The documented ceiling per request. The docs additionally *recommend*
     * batches of 1-10 to keep routing-time lookups fast, but that is guidance
     * for the caller, not a rule this class enforces.
     */
    public const MAX_PHONE_NUMBERS = 100;

    /**
     * @param  array<int, string>  $phoneNumbers
     *
     * @throws ValidationException If phone_numbers is empty or exceeds the
     *                             documented maximum of 100 per request
     */
    public function __construct(
        protected array $phoneNumbers,
        protected string $sender,
    ) {
        // The same trap SendRcsRequest closes, and it was open here. A phone
        // number in the agent slot returns "sender is not owned by this
        // account" from the live API — which is true, and tells the caller
        // nothing about the actual mistake. A rule enforced on the send path
        // and not this one is worse than no rule, because the first success
        // teaches the caller the wrong lesson.
        //
        // Deliberately identical to SendRcsRequest's: reuses
        // PhoneNumber::isValid() rather than inventing a second notion of what
        // a phone number looks like, and so accepts a short numeric agent ID
        // such as "12345", which the docs explicitly permit.
        if (PhoneNumber::isValid($sender)) {
            throw new ValidationException(
                message: sprintf(
                    'sender must be a registered RCS agent ID, not a phone number — "%s" looks like one.',
                    $sender,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        if ($phoneNumbers === []) {
            throw new ValidationException(
                message: 'phone_numbers cannot be empty',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if (count($phoneNumbers) > self::MAX_PHONE_NUMBERS) {
            throw new ValidationException(
                message: sprintf(
                    'Only %d phone numbers can be checked per request, %d given.',
                    self::MAX_PHONE_NUMBERS,
                    count($phoneNumbers),
                ),
                errorCode: 'FIELD_INVALID',
            );
        }
    }

    public function resolveEndpoint(): string
    {
        return '/v2/rcs/capabilities';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'sender' => $this->sender,
            'phone_numbers' => $this->phoneNumbers,
        ];
    }

    /**
     * @return array<int, RcsCapabilityData>
     */
    public function createDtoFromResponse(Response $response): array
    {
        $payload = static::payload($response);

        /** @var array<int, mixed> $results */
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        return array_values(array_map(
            static fn (mixed $result): RcsCapabilityData => RcsCapabilityData::fromArray(is_array($result) ? $result : []),
            $results,
        ));
    }
}
