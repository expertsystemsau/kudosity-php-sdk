<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Support\Url;

describe('Url validation', function () {
    describe('validate()', function () {
        it('accepts valid HTTPS URL', function () {
            expect(fn () => Url::validate('https://example.com/webhook'))->not->toThrow(ValidationException::class);
        });

        it('accepts valid HTTP URL', function () {
            expect(fn () => Url::validate('http://example.com/webhook'))->not->toThrow(ValidationException::class);
        });

        it('accepts URL with port', function () {
            expect(fn () => Url::validate('https://example.com:8080/webhook'))->not->toThrow(ValidationException::class);
        });

        it('accepts URL with query parameters', function () {
            expect(fn () => Url::validate('https://example.com/webhook?token=abc123'))->not->toThrow(ValidationException::class);
        });

        it('rejects empty URL', function () {
            expect(fn () => Url::validate(''))
                ->toThrow(ValidationException::class, 'cannot be empty');
        });

        it('rejects invalid URL format', function () {
            expect(fn () => Url::validate('not-a-url'))
                ->toThrow(ValidationException::class, 'not a valid URL');
        });

        it('rejects FTP URLs', function () {
            expect(fn () => Url::validate('ftp://example.com/file'))
                ->toThrow(ValidationException::class, 'must use HTTP or HTTPS');
        });

        it('rejects file URLs', function () {
            expect(fn () => Url::validate('file:///etc/passwd'))
                ->toThrow(ValidationException::class, 'must use HTTP or HTTPS');
        });

        it('rejects mailto URLs', function () {
            expect(fn () => Url::validate('mailto:test@example.com'))
                ->toThrow(ValidationException::class, 'must use HTTP or HTTPS');
        });

        it('includes field name in error message', function () {
            expect(fn () => Url::validate('invalid', 'dlr_callback'))
                ->toThrow(ValidationException::class, 'dlr_callback');
        });

        it('sets error code to FIELD_EMPTY for empty URLs', function () {
            try {
                Url::validate('', 'test_field');
            } catch (ValidationException $e) {
                expect($e->getErrorCode())->toBe('FIELD_EMPTY');
            }
        });

        it('sets error code to FIELD_INVALID for invalid URLs', function () {
            try {
                Url::validate('not-a-url', 'test_field');
            } catch (ValidationException $e) {
                expect($e->getErrorCode())->toBe('FIELD_INVALID');
            }
        });
    });

    describe('isValid()', function () {
        it('returns true for valid HTTPS URL', function () {
            expect(Url::isValid('https://example.com/webhook'))->toBeTrue();
        });

        it('returns true for valid HTTP URL', function () {
            expect(Url::isValid('http://example.com/webhook'))->toBeTrue();
        });

        it('returns false for empty string', function () {
            expect(Url::isValid(''))->toBeFalse();
        });

        it('returns false for invalid URL', function () {
            expect(Url::isValid('not-a-url'))->toBeFalse();
        });

        it('returns false for FTP URL', function () {
            expect(Url::isValid('ftp://example.com'))->toBeFalse();
        });
    });

    describe('validateEmail()', function () {
        it('accepts valid email', function () {
            expect(fn () => Url::validateEmail('test@example.com'))->not->toThrow(ValidationException::class);
        });

        it('accepts email with subdomain', function () {
            expect(fn () => Url::validateEmail('test@mail.example.com'))->not->toThrow(ValidationException::class);
        });

        it('accepts email with plus sign', function () {
            expect(fn () => Url::validateEmail('test+tag@example.com'))->not->toThrow(ValidationException::class);
        });

        it('rejects empty email', function () {
            expect(fn () => Url::validateEmail(''))
                ->toThrow(ValidationException::class, 'cannot be empty');
        });

        it('rejects invalid email format', function () {
            expect(fn () => Url::validateEmail('not-an-email'))
                ->toThrow(ValidationException::class, 'not a valid email');
        });

        it('rejects email without domain', function () {
            expect(fn () => Url::validateEmail('test@'))
                ->toThrow(ValidationException::class, 'not a valid email');
        });

        it('includes field name in error message', function () {
            expect(fn () => Url::validateEmail('invalid', 'replies_to_email'))
                ->toThrow(ValidationException::class, 'replies_to_email');
        });
    });

    describe('isValidEmail()', function () {
        it('returns true for valid email', function () {
            expect(Url::isValidEmail('test@example.com'))->toBeTrue();
        });

        it('returns false for empty string', function () {
            expect(Url::isValidEmail(''))->toBeFalse();
        });

        it('returns false for invalid email', function () {
            expect(Url::isValidEmail('not-an-email'))->toBeFalse();
        });
    });

    describe('validateCallbackUrl() - SSRF protection', function () {
        it('accepts valid external HTTPS URL', function () {
            expect(fn () => Url::validateCallbackUrl('https://example.com/webhook'))->not->toThrow(ValidationException::class);
        });

        it('accepts valid external HTTP URL', function () {
            expect(fn () => Url::validateCallbackUrl('http://api.example.com/callback'))->not->toThrow(ValidationException::class);
        });

        it('rejects localhost URL', function () {
            expect(fn () => Url::validateCallbackUrl('http://localhost/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects localhost with port', function () {
            expect(fn () => Url::validateCallbackUrl('http://localhost:8080/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects 127.0.0.1 loopback', function () {
            expect(fn () => Url::validateCallbackUrl('http://127.0.0.1/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects other 127.x.x.x loopback addresses', function () {
            expect(fn () => Url::validateCallbackUrl('http://127.0.0.2/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects 10.x.x.x private range', function () {
            expect(fn () => Url::validateCallbackUrl('http://10.0.0.1/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects 172.16.x.x private range', function () {
            expect(fn () => Url::validateCallbackUrl('http://172.16.0.1/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects 172.31.x.x private range', function () {
            expect(fn () => Url::validateCallbackUrl('http://172.31.255.255/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('accepts 172.32.x.x (outside private range)', function () {
            expect(fn () => Url::validateCallbackUrl('http://172.32.0.1/callback'))->not->toThrow(ValidationException::class);
        });

        it('rejects 192.168.x.x private range', function () {
            expect(fn () => Url::validateCallbackUrl('http://192.168.1.1/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects AWS metadata endpoint (169.254.169.254)', function () {
            expect(fn () => Url::validateCallbackUrl('http://169.254.169.254/latest/meta-data/'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects link-local range', function () {
            expect(fn () => Url::validateCallbackUrl('http://169.254.1.1/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('rejects 0.0.0.0', function () {
            expect(fn () => Url::validateCallbackUrl('http://0.0.0.0/callback'))
                ->toThrow(ValidationException::class, 'must not point to internal or private resources');
        });

        it('includes custom field name in error message', function () {
            expect(fn () => Url::validateCallbackUrl('http://localhost/webhook', 'dlr_callback'))
                ->toThrow(ValidationException::class, 'dlr_callback');
        });

        it('sets error code to FIELD_UNSAFE for internal URLs', function () {
            try {
                Url::validateCallbackUrl('http://localhost/callback');
            } catch (ValidationException $e) {
                expect($e->getErrorCode())->toBe('FIELD_UNSAFE');
            }
        });

        it('rejects empty URL', function () {
            expect(fn () => Url::validateCallbackUrl(''))
                ->toThrow(ValidationException::class, 'cannot be empty');
        });

        it('rejects invalid URL format', function () {
            expect(fn () => Url::validateCallbackUrl('not-a-url'))
                ->toThrow(ValidationException::class, 'not a valid URL');
        });
    });

    describe('isCallbackUrlSafe()', function () {
        it('returns true for valid external URL', function () {
            expect(Url::isCallbackUrlSafe('https://example.com/webhook'))->toBeTrue();
        });

        it('returns false for localhost', function () {
            expect(Url::isCallbackUrlSafe('http://localhost/callback'))->toBeFalse();
        });

        it('returns false for 127.0.0.1', function () {
            expect(Url::isCallbackUrlSafe('http://127.0.0.1/callback'))->toBeFalse();
        });

        it('returns false for private 10.x.x.x range', function () {
            expect(Url::isCallbackUrlSafe('http://10.0.0.1/callback'))->toBeFalse();
        });

        it('returns false for private 192.168.x.x range', function () {
            expect(Url::isCallbackUrlSafe('http://192.168.1.1/callback'))->toBeFalse();
        });

        it('returns false for AWS metadata endpoint', function () {
            expect(Url::isCallbackUrlSafe('http://169.254.169.254/metadata'))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect(Url::isCallbackUrlSafe(''))->toBeFalse();
        });

        it('returns false for invalid URL', function () {
            expect(Url::isCallbackUrlSafe('not-a-url'))->toBeFalse();
        });

        it('returns true for public IP addresses', function () {
            expect(Url::isCallbackUrlSafe('http://8.8.8.8/callback'))->toBeTrue();
        });

        it('returns false for IPv6 loopback ::1', function () {
            expect(Url::isCallbackUrlSafe('http://[::1]/callback'))->toBeFalse();
        });
    });
});
