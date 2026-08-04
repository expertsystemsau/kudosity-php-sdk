<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\BalanceData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\GetBalanceRequest;

/**
 * Account resource for account-related API operations.
 *
 * @see https://developer.transmitsms.com/#account
 */
class AccountResource extends Resource
{
    /**
     * Get the account balance.
     *
     * Returns the current account balance and currency.
     *
     * @throws KudosityException
     *
     * @see https://developer.transmitsms.com/#get-balance
     */
    public function getBalance(): BalanceData
    {
        $request = new GetBalanceRequest;

        /** @var BalanceData */
        return $this->connector->send($request)->dtoOrFail();
    }
}
