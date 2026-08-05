<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\AccessDeniedException;
use ExpertSystems\Kudosity\Exceptions\AuthenticationException;
use ExpertSystems\Kudosity\Exceptions\InsufficientFundsException;
use ExpertSystems\Kudosity\Exceptions\InvalidRecipientsException;
use ExpertSystems\Kudosity\Exceptions\InvalidSenderException;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use Saloon\Http\Response;

describe('KudosityException', function () {
    describe('exception properties', function () {
        it('stores error code', function () {
            $exception = new KudosityException('Test error', 0, null, 'TEST_CODE');

            expect($exception->getErrorCode())->toBe('TEST_CODE');
            expect($exception->getMessage())->toBe('Test error');
        });

        it('allows null error code', function () {
            $exception = new KudosityException('Test error');

            expect($exception->getErrorCode())->toBeNull();
        });

        it('stores previous exception', function () {
            $previous = new Exception('Previous error');
            $exception = new KudosityException('Test error', 0, $previous);

            expect($exception->getPrevious())->toBe($previous);
        });
    });

    describe('specific exception types', function () {
        it('AuthenticationException extends KudosityException', function () {
            $exception = new AuthenticationException('Auth failed', 401, null, 'AUTH_FAILED');

            expect($exception)->toBeInstanceOf(KudosityException::class);
            expect($exception->getErrorCode())->toBe('AUTH_FAILED');
        });

        it('RateLimitException extends KudosityException', function () {
            $exception = new RateLimitException('Rate limit exceeded');

            expect($exception)->toBeInstanceOf(KudosityException::class);
        });

        it('ValidationException extends KudosityException', function () {
            $exception = new ValidationException('Invalid field');

            expect($exception)->toBeInstanceOf(KudosityException::class);
        });

        it('InsufficientFundsException extends KudosityException', function () {
            $exception = new InsufficientFundsException('Insufficient balance');

            expect($exception)->toBeInstanceOf(KudosityException::class);
        });

        it('InvalidRecipientsException extends KudosityException', function () {
            $exception = new InvalidRecipientsException('Invalid recipients');

            expect($exception)->toBeInstanceOf(KudosityException::class);
        });

        it('AccessDeniedException extends KudosityException', function () {
            $exception = new AccessDeniedException('Access denied');

            expect($exception)->toBeInstanceOf(KudosityException::class);
        });

        it('InvalidSenderException extends KudosityException', function () {
            $exception = new InvalidSenderException('Invalid sender');

            expect($exception)->toBeInstanceOf(KudosityException::class);
        });
    });

    describe('fromV1Response with array descriptions', function () {
        it('handles RECIPIENTS_ERROR with fails array', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->andReturn([
                'error' => [
                    'code' => 'RECIPIENTS_ERROR',
                    'description' => [
                        'fails' => ['0400000001', '0400000002'],
                        'optouts' => [],
                    ],
                ],
            ]);
            $response->shouldReceive('status')->andReturn(400);

            $exception = KudosityException::fromV1Response($response);

            expect($exception)->toBeInstanceOf(InvalidRecipientsException::class);
            expect($exception->getMessage())->toContain('invalid numbers');
            expect($exception->getMessage())->toContain('0400000001');
            expect($exception->getMessage())->toContain('0400000002');
            expect($exception->getErrorCode())->toBe('RECIPIENTS_ERROR');
        });

        it('handles RECIPIENTS_ERROR with optouts array', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->andReturn([
                'error' => [
                    'code' => 'RECIPIENTS_ERROR',
                    'description' => [
                        'fails' => [],
                        'optouts' => ['61400000003'],
                    ],
                ],
            ]);
            $response->shouldReceive('status')->andReturn(400);

            $exception = KudosityException::fromV1Response($response);

            expect($exception)->toBeInstanceOf(InvalidRecipientsException::class);
            expect($exception->getMessage())->toContain('opted-out numbers');
            expect($exception->getMessage())->toContain('61400000003');
        });

        it('handles RECIPIENTS_ERROR with both fails and optouts', function () {
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

            expect($exception)->toBeInstanceOf(InvalidRecipientsException::class);
            expect($exception->getMessage())->toContain('invalid numbers');
            expect($exception->getMessage())->toContain('invalid1');
            expect($exception->getMessage())->toContain('opted-out numbers');
            expect($exception->getMessage())->toContain('optedout1');
        });

        it('handles RECIPIENTS_ERROR with empty arrays', function () {
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

            expect($exception)->toBeInstanceOf(InvalidRecipientsException::class);
            expect($exception->getMessage())->toBe('Recipients error - all recipients are invalid or opted out');
        });

        it('handles string descriptions normally', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->andReturn([
                'error' => [
                    'code' => 'FIELD_INVALID',
                    'description' => 'The message field is required',
                ],
            ]);
            $response->shouldReceive('status')->andReturn(400);

            $exception = KudosityException::fromV1Response($response);

            expect($exception)->toBeInstanceOf(ValidationException::class);
            expect($exception->getMessage())->toBe('The message field is required');
        });

        it('handles unknown array descriptions with JSON fallback', function () {
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

            expect($exception)->toBeInstanceOf(KudosityException::class);
            expect($exception->getMessage())->toContain('SOME_ERROR');
            expect($exception->getMessage())->toContain('"unknown":"structure"');
        });

        it('handles null description with informative fallback', function () {
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->andReturn([
                'error' => [
                    'code' => 'UNKNOWN_ERROR',
                ],
            ]);
            $response->shouldReceive('status')->andReturn(500);

            $exception = KudosityException::fromV1Response($response);

            expect($exception)->toBeInstanceOf(KudosityException::class);
            expect($exception->getMessage())->toContain('HTTP 500');
            expect($exception->getMessage())->toContain('UNKNOWN_ERROR');
        });
    });

    describe('fromV1Response against a non-JSON body', function () {
        it('produces a useful message instead of crashing on JsonException', function () {
            // What a proxy or load balancer returns for a 503 — never JSON.
            // Response::json() decodes with JSON_THROW_ON_ERROR, so without a
            // guard this throws JsonException instead of a KudosityException.
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->andThrow(new JsonException('Syntax error'));
            $response->shouldReceive('status')->andReturn(503);

            $exception = KudosityException::fromV1Response($response);

            expect($exception)->toBeInstanceOf(KudosityException::class)
                ->and($exception->getMessage())->toBe('API request failed with HTTP 503');
        });

        it('produces a useful message when the body decodes to a literal null', function () {
            // Saloon assigns json()'s result into a non-nullable array
            // property, so a literal `null` body throws TypeError.
            $response = Mockery::mock(Response::class);
            $response->shouldReceive('json')->andThrow(new TypeError('Cannot assign null to property'));
            $response->shouldReceive('status')->andReturn(500);

            $exception = KudosityException::fromV1Response($response);

            expect($exception)->toBeInstanceOf(KudosityException::class)
                ->and($exception->getMessage())->toBe('API request failed with HTTP 500');
        });
    });
});
