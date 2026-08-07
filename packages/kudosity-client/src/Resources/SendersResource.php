<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\V2\SenderRegistrationData;
use ExpertSystems\Kudosity\Enums\SenderRegistrationType;
use ExpertSystems\Kudosity\Enums\SenderVerificationMethod;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\V2\ConfirmSenderVerificationRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteSenderPhoneNumberRequest;
use ExpertSystems\Kudosity\Requests\V2\ListSenderRegistrationsRequest;
use ExpertSystems\Kudosity\Requests\V2\RegisterSenderRequest;
use ExpertSystems\Kudosity\Requests\V2\RequestSenderVerificationRequest;
use Saloon\PaginationPlugin\Paginator;

/**
 * Sender registrations.
 *
 * ## Read this before reaching for `register()`
 *
 * This endpoint family is narrower than "senders" suggests. It registers **a
 * personal mobile number**, verified by an SMS code — the API accepts exactly one
 * `type`, `PERSONAL_MOBILE_NUMBER`. Alphanumeric sender IDs, WhatsApp Business
 * senders and RCS agents all need Kudosity approval and are **not** available
 * here.
 *
 * A consequence worth knowing: an account can send perfectly well and still
 * report **zero** registrations, because a leased virtual number is not a
 * registration. That is the case on the account this was built against. For
 * leased numbers use V1 — `$k->numbers()`.
 *
 * ## The distinction that costs a day
 *
 * `VERIFIED` means *provisioning*, not sendable. Only `READY_TO_USE` can send,
 * and sending on `VERIFIED` fails in a way that looks like anything but a sender
 * problem. {@see SenderRegistrationData::isReadyToUse()} is the check.
 *
 * ## Verification flow
 *
 * ```php
 * $reg = $k->senders()->register('61491570006', 'AU');
 * $k->senders()->requestVerification($reg->id, originatingSender: '61491570017');
 * $k->senders()->confirmVerification($reg->id, '123456');
 *
 * foreach ($k->senders()->registrations() as $r) {
 *     // $r->isReadyToUse(), $r->needsYourAction(), $r->statusReason
 * }
 * ```
 */
class SendersResource extends V2Resource
{
    /**
     * The registrations paginator, yielding raw rows.
     *
     * Returned rather than mapped, to match `sms()`, `whatsapp()` and `rcs()`
     * list reads: the paginator pulls items straight off the JSON and never calls
     * `.dto()`, so nothing here can hand back typed objects without re-walking
     * the pages. {@see self::allRegistrations()} does that when you want it.
     *
     * Page-based, 25 per page by default — the paginator uses the limit the API
     * reports rather than assuming its own.
     *
     * @throws KudosityException
     */
    public function registrations(): Paginator
    {
        return $this->paginate(new ListSenderRegistrationsRequest);
    }

    /**
     * Every registration on the account, typed, across all pages.
     *
     * Walks the paginator's responses rather than its `items()`, because Saloon
     * annotates `items()` as yielding responses when it in fact yields rows — so
     * iterating it cannot be type-checked. Reading each response with the
     * request's own items key gets the same rows and stays honest about types.
     *
     * @return array<int, SenderRegistrationData>
     *
     * @throws KudosityException
     */
    public function allRegistrations(): array
    {
        $request = new ListSenderRegistrationsRequest;
        $registrations = [];

        foreach ($this->paginate($request) as $response) {
            $rows = $response->json($request->paginationItemsKey());

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $registrations[] = SenderRegistrationData::fromArray($row);
                }
            }
        }

        return $registrations;
    }

    /**
     * Register a sender.
     *
     * @param  string  $sender  The number to register, in E.164.
     * @param  string  $country  ISO 3166-1 alpha-2, e.g. `AU`.
     *
     * @throws KudosityException
     */
    public function register(
        string $sender,
        string $country,
        SenderRegistrationType $type = SenderRegistrationType::PersonalMobileNumber,
    ): SenderRegistrationData {
        /** @var SenderRegistrationData */
        return $this->sendAndDto(new RegisterSenderRequest(
            sender: $sender,
            country: $country,
            type: $type,
        ));
    }

    /**
     * Ask for a verification code to be sent for a registration.
     *
     * `$originatingSender` is the number the code is sent **from** — one already
     * usable on the account, not the one being registered.
     *
     * Returns the decoded response body. No DTO, because the success shape has
     * never been observed and inventing one is how a wrong read looks like a
     * right one.
     *
     * @return array<string, mixed>
     *
     * @throws KudosityException
     */
    public function requestVerification(
        string $registrationId,
        string $originatingSender,
        SenderVerificationMethod $method = SenderVerificationMethod::Sms,
    ): array {
        $response = $this->connector->send(new RequestSenderVerificationRequest(
            registrationId: $registrationId,
            originatingSender: $originatingSender,
            verificationMethod: $method,
        ));

        $response->throw();

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Confirm a registration with the code that was sent to it.
     *
     * The code is a string: verification codes have leading zeros.
     *
     * @return array<string, mixed>
     *
     * @throws KudosityException
     */
    public function confirmVerification(string $registrationId, string $code): array
    {
        $response = $this->connector->send(new ConfirmSenderVerificationRequest(
            registrationId: $registrationId,
            code: $code,
        ));

        $response->throw();

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Remove a registered phone number.
     *
     * Keyed on the number, not a registration id — the only call in this family
     * that is.
     *
     * @throws KudosityException If the number is not registered
     */
    public function deleteByPhoneNumber(string $phoneNumber): bool
    {
        $response = $this->connector->send(new DeleteSenderPhoneNumberRequest($phoneNumber));

        $response->throw();

        return $response->successful();
    }

    /**
     * The registrations that can actually send right now.
     *
     * @return array<int, SenderRegistrationData>
     *
     * @throws KudosityException
     */
    public function readyToUse(): array
    {
        return array_values(array_filter(
            $this->allRegistrations(),
            static fn (SenderRegistrationData $r): bool => $r->isReadyToUse(),
        ));
    }
}
