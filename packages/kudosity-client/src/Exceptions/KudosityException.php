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

    /**
     * V2 HTTP status to exception class.
     *
     * 400 and 422 both map to ValidationException on purpose: the error
     * registry documents InputValidationProblem as 422, while the RCS and
     * WhatsApp endpoint references show 400 for the same condition. Handling
     * both means we do not depend on which is true today.
     *
     * @var array<int, class-string<KudosityException>>
     */
    protected static array $v2StatusMap = [
        400 => ValidationException::class,
        401 => AuthenticationException::class,
        403 => AccessDeniedException::class,
        404 => NotFoundException::class,
        422 => ValidationException::class,
        429 => RateLimitException::class,
    ];

    protected ?string $errorCode = null;

    protected ?Response $response = null;

    /**
     * @var array<int, ProblemIssue>
     */
    protected array $issues = [];

    protected ?string $problemType = null;

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
     * Create an exception from a V2 API response.
     *
     * Handles all three shapes V2 returns: RFC 9457 Problem Details under
     * `error` (the messaging endpoints), a plain string under `error` (the
     * webhook endpoints and `GET /v2/sms/{id}`'s 404), and no error key at all.
     *
     * @see https://developers.kudosity.com/reference/errors
     */
    public static function fromV2Response(Response $response): self
    {
        $status = $response->status();
        $json = $response->json();
        $error = $json['error'] ?? null;

        $issues = [];
        $problemType = null;

        if (is_string($error) && $error !== '') {
            $message = $error;
        } elseif (is_array($error)) {
            $problemType = is_string($error['type'] ?? null) ? $error['type'] : null;

            foreach (is_array($error['issues'] ?? null) ? $error['issues'] : [] as $issue) {
                if (is_array($issue)) {
                    $issues[] = ProblemIssue::fromArray($issue);
                }
            }

            $message = self::messageFromProblem($error, $issues, $status);
        } else {
            $message = sprintf('API request failed with HTTP %d', $status);
        }

        $exceptionClass = static::$v2StatusMap[$status]
            ?? ($status >= 500 ? ServerException::class : self::class);

        if ($exceptionClass === RateLimitException::class) {
            $exception = RateLimitException::fromResponseWithMetadata($response, $message, null);
        } else {
            $exception = new $exceptionClass(
                message: $message,
                code: $status,
                response: $response,
            );
        }

        self::attachProblemDetails($exception, $issues, $problemType);

        return $exception;
    }

    /**
     * Build a message from a Problem Details object.
     *
     * Prefers the per-field issues, because they name what the caller has to
     * change. Falls back to `detail`, then `title`, then the bare status.
     *
     * @param  array<string, mixed>  $error
     * @param  array<int, ProblemIssue>  $issues
     */
    protected static function messageFromProblem(array $error, array $issues, int $status): string
    {
        if ($issues !== []) {
            return implode('; ', array_map(
                static fn (ProblemIssue $issue): string => $issue->name !== ''
                    ? sprintf('%s: %s', $issue->name, $issue->message)
                    : $issue->message,
                $issues
            ));
        }

        foreach (['detail', 'title'] as $key) {
            if (is_string($error[$key] ?? null) && $error[$key] !== '') {
                return $error[$key];
            }
        }

        return sprintf('API request failed with HTTP %d', $status);
    }

    /**
     * Assign issues and problem type onto a freshly built exception.
     *
     * A private helper (rather than inline assignment in `fromV2Response()`)
     * keeps `issues`/`problemType` declared `protected` instead of `public`:
     * PHPStan sees `$exception` there as the mapped subclass, and assigning
     * a protected property from outside its declaring scope would fail
     * analysis even though it is legal PHP within the same class hierarchy.
     *
     * @param  array<int, ProblemIssue>  $issues
     */
    private static function attachProblemDetails(self $exception, array $issues, ?string $problemType): void
    {
        $exception->issues = $issues;
        $exception->problemType = $problemType;
    }

    /**
     * Every field the V2 API reported as invalid. Empty for V1 errors.
     *
     * @return array<int, ProblemIssue>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * The RFC 9457 problem type URI, when the response carried one.
     */
    public function getProblemType(): ?string
    {
        return $this->problemType;
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
