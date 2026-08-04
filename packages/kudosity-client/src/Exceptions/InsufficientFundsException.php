<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * Thrown when account has insufficient funds.
 *
 * Error code: LEDGER_ERROR
 * HTTP status: 400
 */
class InsufficientFundsException extends TransmitSmsException {}
