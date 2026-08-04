<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * Thrown when recipients are invalid or list is empty.
 *
 * Error codes: RECIPIENTS_ERROR, LIST_EMPTY
 * HTTP status: 400
 */
class InvalidRecipientsException extends KudosityException {}
