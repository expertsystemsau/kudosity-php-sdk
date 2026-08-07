<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use Exception;
use ExpertSystems\Kudosity\Exceptions\AccessDeniedException;
use ExpertSystems\Kudosity\Exceptions\AuthenticationException;
use ExpertSystems\Kudosity\Exceptions\InsufficientFundsException;
use ExpertSystems\Kudosity\Exceptions\InvalidRecipientsException;
use ExpertSystems\Kudosity\Exceptions\InvalidSenderException;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use JsonException;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Response;
use TypeError;

/**
 * Ported from the root Pest suite's tests/Unit/ExceptionTest.php.
 *
 * The specific-exception-type tests instantiate subclasses that declare no
 * constructor of their own (AuthenticationException, ValidationException,
 * InsufficientFundsException, InvalidRecipientsException,
 * AccessDeniedException, InvalidSenderException) — they have no executable
 * lines of their own, so KudosityException is the only class genuinely
 * driven here. RateLimitException's own constructor is exercised too, but it
 * has its own direct test file (RateLimitExceptionTest.php).
 */
#[CoversClass(KudosityException::class)]
final class ExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // exception properties
    // -----------------------------------------------------------------

    public function test_stores_error_code(): void
    {
        $exception = new KudosityException('Test error', 0, null, 'TEST_CODE');

        $this->assertSame('TEST_CODE', $exception->getErrorCode());
        $this->assertSame('Test error', $exception->getMessage());
    }

    public function test_allows_null_error_code(): void
    {
        $exception = new KudosityException('Test error');

        $this->assertNull($exception->getErrorCode());
    }

    public function test_stores_previous_exception(): void
    {
        $previous = new Exception('Previous error');
        $exception = new KudosityException('Test error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    // -----------------------------------------------------------------
    // specific exception types
    // -----------------------------------------------------------------

    public function test_authentication_exception_extends_kudosity_exception(): void
    {
        $exception = new AuthenticationException('Auth failed', 401, null, 'AUTH_FAILED');

        $this->assertInstanceOf(KudosityException::class, $exception);
        $this->assertSame('AUTH_FAILED', $exception->getErrorCode());
    }

    public function test_rate_limit_exception_extends_kudosity_exception(): void
    {
        $exception = new RateLimitException('Rate limit exceeded');

        $this->assertInstanceOf(KudosityException::class, $exception);
    }

    public function test_validation_exception_extends_kudosity_exception(): void
    {
        $exception = new ValidationException('Invalid field');

        $this->assertInstanceOf(KudosityException::class, $exception);
    }

    public function test_insufficient_funds_exception_extends_kudosity_exception(): void
    {
        $exception = new InsufficientFundsException('Insufficient balance');

        $this->assertInstanceOf(KudosityException::class, $exception);
    }

    public function test_invalid_recipients_exception_extends_kudosity_exception(): void
    {
        $exception = new InvalidRecipientsException('Invalid recipients');

        $this->assertInstanceOf(KudosityException::class, $exception);
    }

    public function test_access_denied_exception_extends_kudosity_exception(): void
    {
        $exception = new AccessDeniedException('Access denied');

        $this->assertInstanceOf(KudosityException::class, $exception);
    }

    public function test_invalid_sender_exception_extends_kudosity_exception(): void
    {
        $exception = new InvalidSenderException('Invalid sender');

        $this->assertInstanceOf(KudosityException::class, $exception);
    }

    // -----------------------------------------------------------------
    // fromV1Response with array descriptions
    // -----------------------------------------------------------------

    public function test_handles_recipients_error_with_fails_array(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn([
            'error' => [
                'code' => 'RECIPIENTS_ERROR',
                'description' => [
                    'fails' => ['0491570007', '0491570008'],
                    'optouts' => [],
                ],
            ],
        ]);
        $response->shouldReceive('status')->andReturn(400);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(InvalidRecipientsException::class, $exception);
        $this->assertStringContainsString('invalid numbers', $exception->getMessage());
        $this->assertStringContainsString('0491570007', $exception->getMessage());
        $this->assertStringContainsString('0491570008', $exception->getMessage());
        $this->assertSame('RECIPIENTS_ERROR', $exception->getErrorCode());
    }

    public function test_handles_recipients_error_with_optouts_array(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn([
            'error' => [
                'code' => 'RECIPIENTS_ERROR',
                'description' => [
                    'fails' => [],
                    'optouts' => ['61491570009'],
                ],
            ],
        ]);
        $response->shouldReceive('status')->andReturn(400);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(InvalidRecipientsException::class, $exception);
        $this->assertStringContainsString('opted-out numbers', $exception->getMessage());
        $this->assertStringContainsString('61491570009', $exception->getMessage());
    }

    public function test_handles_recipients_error_with_both_fails_and_optouts(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn([
            'error' => [
                'code' => 'RECIPIENTS_ERROR',
                'description' => [
                    'fails' => ['invalid1'],
                    'optouts' => ['optedout1'],
                ],
            ],
        ]);
        $response->shouldReceive('status')->andReturn(400);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(InvalidRecipientsException::class, $exception);
        $this->assertStringContainsString('invalid numbers', $exception->getMessage());
        $this->assertStringContainsString('invalid1', $exception->getMessage());
        $this->assertStringContainsString('opted-out numbers', $exception->getMessage());
        $this->assertStringContainsString('optedout1', $exception->getMessage());
    }

    public function test_handles_recipients_error_with_empty_arrays(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn([
            'error' => [
                'code' => 'RECIPIENTS_ERROR',
                'description' => [
                    'fails' => [],
                    'optouts' => [],
                ],
            ],
        ]);
        $response->shouldReceive('status')->andReturn(400);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(InvalidRecipientsException::class, $exception);
        $this->assertSame('Recipients error - all recipients are invalid or opted out', $exception->getMessage());
    }

    public function test_handles_string_descriptions_normally(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn([
            'error' => [
                'code' => 'FIELD_INVALID',
                'description' => 'The message field is required',
            ],
        ]);
        $response->shouldReceive('status')->andReturn(400);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertSame('The message field is required', $exception->getMessage());
    }

    public function test_handles_unknown_array_descriptions_with_json_fallback(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn([
            'error' => [
                'code' => 'SOME_ERROR',
                'description' => [
                    'unknown' => 'structure',
                    'data' => 123,
                ],
            ],
        ]);
        $response->shouldReceive('status')->andReturn(400);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(KudosityException::class, $exception);
        $this->assertStringContainsString('SOME_ERROR', $exception->getMessage());
        $this->assertStringContainsString('"unknown":"structure"', $exception->getMessage());
    }

    public function test_handles_null_description_with_informative_fallback(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn([
            'error' => [
                'code' => 'UNKNOWN_ERROR',
            ],
        ]);
        $response->shouldReceive('status')->andReturn(500);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(KudosityException::class, $exception);
        $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        $this->assertStringContainsString('UNKNOWN_ERROR', $exception->getMessage());
    }

    // -----------------------------------------------------------------
    // fromV1Response against a non-JSON body
    // -----------------------------------------------------------------

    public function test_produces_a_useful_message_instead_of_crashing_on_json_exception(): void
    {
        // What a proxy or load balancer returns for a 503 — never JSON.
        // Response::json() decodes with JSON_THROW_ON_ERROR, so without a
        // guard this throws JsonException instead of a KudosityException.
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andThrow(new JsonException('Syntax error'));
        $response->shouldReceive('status')->andReturn(503);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(KudosityException::class, $exception);
        $this->assertSame('API request failed with HTTP 503', $exception->getMessage());
    }

    public function test_produces_a_useful_message_when_the_body_decodes_to_a_literal_null(): void
    {
        // Saloon assigns json()'s result into a non-nullable array
        // property, so a literal `null` body throws TypeError.
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andThrow(new TypeError('Cannot assign null to property'));
        $response->shouldReceive('status')->andReturn(500);

        $exception = KudosityException::fromV1Response($response);

        $this->assertInstanceOf(KudosityException::class, $exception);
        $this->assertSame('API request failed with HTTP 500', $exception->getMessage());
    }
}
