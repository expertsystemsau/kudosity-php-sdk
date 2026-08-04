<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * Thrown when request validation fails.
 *
 * Error codes: FIELD_EMPTY, FIELD_INVALID
 * HTTP status: 400
 */
class ValidationException extends TransmitSmsException {}
