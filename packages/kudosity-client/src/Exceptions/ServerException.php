<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

/**
 * The API failed on its own side (V2 HTTP 5xx). Safe to retry with backoff.
 */
class ServerException extends KudosityException {}
