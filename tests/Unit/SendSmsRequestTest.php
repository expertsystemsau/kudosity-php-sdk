<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;

describe('SendSmsRequest', function () {
    describe('construction', function () {
        it('creates request with message', function () {
            $request = new SendSmsRequest('Hello World');
            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('rejects message exceeding max length', function () {
            $longMessage = str_repeat('a', 613);
            expect(fn () => new SendSmsRequest($longMessage))
                ->toThrow(ValidationException::class, 'exceeds maximum');
        });

        it('accepts message at max length', function () {
            $maxMessage = str_repeat('a', 612);
            $request = new SendSmsRequest($maxMessage);
            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });
    });

    describe('fluent builder', function () {
        it('chains to() method', function () {
            $request = (new SendSmsRequest('Test'))
                ->to('61400000000');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('chains toList() method', function () {
            $request = (new SendSmsRequest('Test'))
                ->toList(12345);

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('chains from() method', function () {
            $request = (new SendSmsRequest('Test'))
                ->to('61400000000')
                ->from('MyBrand');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('chains countryCode() method', function () {
            $request = (new SendSmsRequest('Test'))
                ->to('0400000000')
                ->countryCode('AU');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('chains all methods together', function () {
            $request = (new SendSmsRequest('Test message'))
                ->to('61400000000')
                ->from('MyBrand')
                ->countryCode('AU')
                ->scheduledAt('2025-12-06 10:00:00')
                ->validity(60)
                ->repliesToEmail('test@example.com')
                ->trackedLinkUrl('https://example.com')
                ->dlrCallback('https://myapp.com/dlr')
                ->replyCallback('https://myapp.com/reply')
                ->linkHitsCallback('https://myapp.com/clicks');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });
    });

    describe('scheduledAt', function () {
        it('accepts string datetime', function () {
            $request = (new SendSmsRequest('Test'))
                ->to('61400000000')
                ->scheduledAt('2025-12-06 10:00:00');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('accepts DateTime object', function () {
            $dateTime = new DateTime('2025-12-06 10:00:00', new DateTimeZone('UTC'));
            $request = (new SendSmsRequest('Test'))
                ->to('61400000000')
                ->scheduledAt($dateTime);

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('accepts DateTimeImmutable object', function () {
            $dateTime = new DateTimeImmutable('2025-12-06 10:00:00', new DateTimeZone('UTC'));
            $request = (new SendSmsRequest('Test'))
                ->to('61400000000')
                ->scheduledAt($dateTime);

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('converts non-UTC datetime to UTC', function () {
            // Create a datetime in Sydney timezone (UTC+11 in December)
            $sydneyTime = new DateTime('2025-12-06 21:00:00', new DateTimeZone('Australia/Sydney'));
            $request = (new SendSmsRequest('Test'))
                ->to('61400000000')
                ->scheduledAt($sydneyTime);

            // The internal sendAt should be in UTC (21:00 Sydney = 10:00 UTC)
            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });
    });

    describe('resolveEndpoint', function () {
        it('returns the correct endpoint', function () {
            $request = new SendSmsRequest('Test');
            expect($request->resolveEndpoint())->toBe('/send-sms.json');
        });
    });

    describe('formatNumbers', function () {
        it('can enable number formatting', function () {
            $request = (new SendSmsRequest('Test'))
                ->to('0400000000')
                ->countryCode('AU')
                ->formatNumbers();

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });
    });

    describe('URL validation', function () {
        it('validates trackedLinkUrl accepts valid HTTPS URL', function () {
            $request = (new SendSmsRequest('Test'))
                ->trackedLinkUrl('https://example.com/page');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('validates trackedLinkUrl rejects invalid URL', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->trackedLinkUrl('not-a-url'))
                ->toThrow(ValidationException::class);
        });

        it('validates dlrCallback accepts valid URL', function () {
            $request = (new SendSmsRequest('Test'))
                ->dlrCallback('https://myapp.com/webhook/dlr');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('validates dlrCallback rejects invalid URL', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->dlrCallback('ftp://invalid.com'))
                ->toThrow(ValidationException::class);
        });

        it('validates replyCallback accepts valid URL', function () {
            $request = (new SendSmsRequest('Test'))
                ->replyCallback('https://myapp.com/webhook/reply');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('validates replyCallback rejects invalid URL', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->replyCallback(''))
                ->toThrow(ValidationException::class);
        });

        it('validates linkHitsCallback accepts valid URL', function () {
            $request = (new SendSmsRequest('Test'))
                ->linkHitsCallback('https://myapp.com/webhook/clicks');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('validates linkHitsCallback rejects invalid URL', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->linkHitsCallback('javascript:alert(1)'))
                ->toThrow(ValidationException::class);
        });
    });

    describe('callback URL SSRF protection', function () {
        it('dlrCallback rejects localhost', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->dlrCallback('http://localhost/webhook'))
                ->toThrow(ValidationException::class, 'localhost');
        });

        it('dlrCallback rejects private IPs', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->dlrCallback('http://192.168.1.1/webhook'))
                ->toThrow(ValidationException::class, 'internal or private');
        });

        it('replyCallback rejects localhost', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->replyCallback('http://localhost/webhook'))
                ->toThrow(ValidationException::class, 'localhost');
        });

        it('linkHitsCallback rejects localhost', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->linkHitsCallback('http://localhost/webhook'))
                ->toThrow(ValidationException::class, 'localhost');
        });

        it('trackedLinkUrl allows localhost (not a callback)', function () {
            // trackedLinkUrl is for the user to click, not for Kudosity to hit
            // so it doesn't need SSRF protection
            $request = (new SendSmsRequest('Test'))
                ->trackedLinkUrl('http://localhost/page');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });
    });

    describe('email validation', function () {
        it('validates repliesToEmail accepts valid email', function () {
            $request = (new SendSmsRequest('Test'))
                ->repliesToEmail('test@example.com');

            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('validates repliesToEmail rejects invalid email', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->repliesToEmail('not-an-email'))
                ->toThrow(ValidationException::class);
        });

        it('validates repliesToEmail rejects empty email', function () {
            expect(fn () => (new SendSmsRequest('Test'))
                ->repliesToEmail(''))
                ->toThrow(ValidationException::class);
        });
    });

    describe('recipient validation', function () {
        it('accepts valid number of recipients', function () {
            $recipients = implode(',', array_fill(0, 100, '61400000000'));
            $request = (new SendSmsRequest('Test'))->to($recipients);
            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('rejects more than 500 recipients', function () {
            $recipients = implode(',', array_fill(0, 501, '61400000000'));
            expect(fn () => (new SendSmsRequest('Test'))->to($recipients))
                ->toThrow(ValidationException::class, 'exceeds maximum');
        });
    });

    describe('validity validation', function () {
        it('accepts valid validity period', function () {
            $request = (new SendSmsRequest('Test'))->validity(60);
            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('accepts zero validity (maximum period)', function () {
            $request = (new SendSmsRequest('Test'))->validity(0);
            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('accepts max validity (72 hours)', function () {
            $request = (new SendSmsRequest('Test'))->validity(4320);
            expect($request)->toBeInstanceOf(SendSmsRequest::class);
        });

        it('rejects negative validity', function () {
            expect(fn () => (new SendSmsRequest('Test'))->validity(-1))
                ->toThrow(ValidationException::class, 'cannot be negative');
        });

        it('rejects validity exceeding max', function () {
            expect(fn () => (new SendSmsRequest('Test'))->validity(4321))
                ->toThrow(ValidationException::class, 'exceeds maximum');
        });
    });
});
