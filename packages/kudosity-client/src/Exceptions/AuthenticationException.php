<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * Thrown when API authentication fails.
 *
 * Error codes: AUTH_FAILED, AUTH_FAILED_NO_DATA
 * HTTP status: 401
 */
class AuthenticationException extends TransmitSmsException {}
