<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Exceptions;

use Exception;
use Saloon\Http\Response;
use Throwable;

class KudosityException extends Exception
{
    /**
     * Error code to exception class mapping.
     *
     * @var array<string, class-string<KudosityException>>
     */
    protected static array $errorMap = [
        'AUTH_FAILED' => AuthenticationException::class,
        'AUTH_FAILED_NO_DATA' => AuthenticationException::class,
        'OVER_LIMIT' => RateLimitException::class,
        'FIELD_EMPTY' => ValidationException::class,
        'FIELD_INVALID' => ValidationException::class,
        'FIELD_UNSAFE' => ValidationException::class,
        'LEDGER_ERROR' => InsufficientFundsException::class,
        'RECIPIENTS_ERROR' => InvalidRecipientsException::class,
        'LIST_EMPTY' => InvalidRecipientsException::class,
        'NO_ACCESS' => AccessDeniedException::class,
        'BAD_CALLER_ID' => InvalidSenderException::class,
    ];

    protected ?string $errorCode = null;

    protected ?Response $response = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?string $errorCode = null,
        ?Response $response = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->response = $response;
    }

    /**
     * Create an exception from a V1 API response.
     *
     * Returns a specific exception type based on the error code.
     * For rate limit exceptions, extracts rate limit metadata from headers.
     */
    public static function fromV1Response(Response $response): self
    {
        $data = $response->json();
        $error = $data['error'] ?? [];
        $errorCode = $error['code'] ?? null;
        $httpStatus = $response->status();

        // Build informative error message
        $description = $error['description'] ?? null;

        // Handle array descriptions (e.g., RECIPIENTS_ERROR returns {"fails":[],"optouts":[]})
        if (is_array($description)) {
            $message = self::formatArrayDescription($description, $errorCode);
        } elseif (is_string($description)) {
            $message = $description;
        } else {
            // Provide more context when API doesn't return a description
            $message = sprintf(
                'API request failed with HTTP %d%s',
                $httpStatus,
                $errorCode !== null ? " (error code: {$errorCode})" : ''
            );
        }

        $exceptionClass = self::$errorMap[$errorCode] ?? self::class;

        // For rate limit exceptions, use the specialized factory method
        // to extract rate limit metadata from headers
        if ($exceptionClass === RateLimitException::class) {
            return RateLimitException::fromResponseWithMetadata($response, $message, $errorCode);
        }

        return new $exceptionClass(
            message: $message,
            code: $httpStatus,
            errorCode: $errorCode,
            response: $response
        );
    }

    /**
     * Get the Kudosity API error code.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get the Saloon response if available.
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * Format an array description into a readable error message.
     *
     * @param  array<string, mixed>  $description
     */
    protected static function formatArrayDescription(array $description, ?string $errorCode): string
    {
        // Handle RECIPIENTS_ERROR format: {"fails":["number1"],"optouts":["number2"]}
        if (isset($description['fails']) || isset($description['optouts'])) {
            $parts = [];

            $fails = $description['fails'] ?? [];
            $optouts = $description['optouts'] ?? [];

            if (! empty($fails)) {
                $parts[] = 'invalid numbers: '.implode(', ', (array) $fails);
            }

            if (! empty($optouts)) {
                $parts[] = 'opted-out numbers: '.implode(', ', (array) $optouts);
            }

            if (! empty($parts)) {
                return 'Recipients error - '.implode('; ', $parts);
            }

            // All recipients invalid but arrays are empty
            return 'Recipients error - all recipients are invalid or opted out';
        }

        // Fallback: JSON encode the array for visibility
        $json = json_encode($description, JSON_UNESCAPED_SLASHES);

        return $errorCode !== null
            ? sprintf('%s: %s', $errorCode, $json)
            : sprintf('Error details: %s', $json);
    }
}
