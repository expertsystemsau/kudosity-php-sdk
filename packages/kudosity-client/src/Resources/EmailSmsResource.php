<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\EmailSmsData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\AddEmailRequest;
use ExpertSystems\Kudosity\Requests\DeleteEmailRequest;

/**
 * Email SMS resource for managing email-to-SMS authorization.
 *
 * @see https://developers.kudosity.com
 */
class EmailSmsResource extends Resource
{
    /**
     * Authorize an email address for Email SMS.
     *
     * @param  string  $email  The email address to authorize
     *
     * @throws KudosityException
     */
    public function add(string $email): EmailSmsData
    {
        $request = new AddEmailRequest($email);

        /** @var EmailSmsData */
        return $this->sendAndDto($request);
    }

    /**
     * Authorize an email address using a custom request.
     *
     * Use this to set additional options like max SMS.
     *
     * @throws KudosityException
     */
    public function addRequest(AddEmailRequest $request): EmailSmsData
    {
        /** @var EmailSmsData */
        return $this->sendAndDto($request);
    }

    /**
     * Delete an authorized email address.
     *
     * @param  string  $email  The email address to delete
     *
     * @throws KudosityException
     */
    public function delete(string $email): bool
    {
        $response = $this->connector->send(new DeleteEmailRequest($email));
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Authorize an email with a max SMS limit.
     *
     * @param  string  $email  The email address
     * @param  int  $maxSms  Maximum SMS allowed
     *
     * @throws KudosityException
     */
    public function addWithLimit(string $email, int $maxSms): EmailSmsData
    {
        $request = (new AddEmailRequest($email))->maxSms($maxSms);

        /** @var EmailSmsData */
        return $this->sendAndDto($request);
    }

    /**
     * Authorize an email with a specific sender number.
     *
     * @param  string  $email  The email address
     * @param  string  $number  The sender number
     *
     * @throws KudosityException
     */
    public function addWithNumber(string $email, string $number): EmailSmsData
    {
        $request = (new AddEmailRequest($email))->number($number);

        /** @var EmailSmsData */
        return $this->sendAndDto($request);
    }
}
