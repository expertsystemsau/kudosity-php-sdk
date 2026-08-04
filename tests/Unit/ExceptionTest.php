<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\AccessDeniedException;
use ExpertSystems\Kudosity\Exceptions\AuthenticationException;
use ExpertSystems\Kudosity\Exceptions\InsufficientFundsException;
use ExpertSystems\Kudosity\Exceptions\InvalidRecipientsException;
use ExpertSystems\Kudosity\Exceptions\InvalidSenderException;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\TransmitSmsException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use Saloon\Http\Response;

describe('TransmitSmsException', function () {
    describe('exception properties', function () {
        it('stores error code', function () {
            $exception = new TransmitSmsException('Test error', 0, null, 'TEST_CODE');

            expect($exception->getErrorCode())->toBe('TEST_CODE');
            expect($exception->getMessage())->toBe('Test error');
        });

        it('allows null error code', function () {
            $exception = new TransmitSmsException('Test error');

            expect($exception->getErrorCode())->toBeNull();
        });

        it('stores previous exception', function () {
            $previous = new Exception('Previous error');
            $exception = new TransmitSmsException('Test error', 0, $previous);

            expect($exception->getPrevious())->toBe($previous);
        });
    });

    describe('specific exception types', function () {
        it('AuthenticationException extends TransmitSmsException', function () {
            $exception = new AuthenticationException('Auth failed', 401, null, 'AUTH_FAILED');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
            expect($exception->getErrorCode())->toBe('AUTH_FAILED');
        });

        it('RateLimitException extends TransmitSmsException', function () {
            $exception = new RateLimitException('Rate limit exceeded');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
        });

        it('ValidationException extends TransmitSmsException', function () {
            $exception = new ValidationException('Invalid field');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
        });

        it('InsufficientFundsException extends TransmitSmsException', function () {
            $exception = new InsufficientFundsException('Insufficient balance');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
        });

        it('InvalidRecipientsException extends TransmitSmsException', function () {
            $exception = new InvalidRecipientsException('Invalid recipients');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
        });

        it('AccessDeniedException extends TransmitSmsException', function () {
            $exception = new AccessDeniedException('Access denied');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
        });

        it('InvalidSenderException extends TransmitSmsException', function () {
            $exception = new InvalidSenderException('Invalid sender');

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
        });
    });

    describe('fromResponse with array descriptions', function () {
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

            $exception = TransmitSmsException::fromResponse($response);

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

            $exception = TransmitSmsException::fromResponse($response);

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

            $exception = TransmitSmsException::fromResponse($response);

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

            $exception = TransmitSmsException::fromResponse($response);

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

            $exception = TransmitSmsException::fromResponse($response);

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

            $exception = TransmitSmsException::fromResponse($response);

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
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

            $exception = TransmitSmsException::fromResponse($response);

            expect($exception)->toBeInstanceOf(TransmitSmsException::class);
            expect($exception->getMessage())->toContain('HTTP 500');
            expect($exception->getMessage())->toContain('UNKNOWN_ERROR');
        });
    });
});
