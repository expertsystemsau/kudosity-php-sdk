<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

use Exception;

/**
 * Exception thrown when a callback URL signature verification fails.
 */
class InvalidSignatureException extends Exception
{
    public function __construct(string $message = 'Invalid callback signature')
    {
        parent::__construct($message);
    }
}
