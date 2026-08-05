<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Support\PhoneNumber;

describe('PhoneNumber', function () {
    describe('toInternational', function () {
        it('formats Australian local numbers', function () {
            expect(PhoneNumber::toInternational('0400000000', 'AU'))->toBe('61400000000');
            expect(PhoneNumber::toInternational('0412345678', 'AU'))->toBe('61412345678');
            expect(PhoneNumber::toInternational('0438333061', 'AU'))->toBe('61438333061');
        });

        it('formats Australian numbers with various formats', function () {
            expect(PhoneNumber::toInternational('0400 000 000', 'AU'))->toBe('61400000000');
            expect(PhoneNumber::toInternational('(04) 1234 5678', 'AU'))->toBe('61412345678');
            expect(PhoneNumber::toInternational('+61 400 000 000', 'AU'))->toBe('61400000000');
        });

        it('formats New Zealand local numbers', function () {
            expect(PhoneNumber::toInternational('0212172782', 'NZ'))->toBe('64212172782');
            expect(PhoneNumber::toInternational('021 217 2782', 'NZ'))->toBe('64212172782');
        });

        it('formats US local numbers', function () {
            expect(PhoneNumber::toInternational('(281) 869-1226', 'US'))->toBe('12818691226');
            expect(PhoneNumber::toInternational('2818691226', 'US'))->toBe('12818691226');
        });

        it('formats Singapore numbers', function () {
            expect(PhoneNumber::toInternational('96112234', 'SG'))->toBe('6596112234');
        });

        it('returns already international numbers unchanged', function () {
            expect(PhoneNumber::toInternational('61400000000', 'AU'))->toBe('61400000000');
            expect(PhoneNumber::toInternational('6596112234', 'SG'))->toBe('6596112234');
        });

        it('works with country names', function () {
            expect(PhoneNumber::toInternational('0400000000', 'Australia'))->toBe('61400000000');
            expect(PhoneNumber::toInternational('0212172782', 'New Zealand'))->toBe('64212172782');
        });

        it('throws for invalid country code', function () {
            expect(fn () => PhoneNumber::toInternational('0400000000', 'XX'))
                ->toThrow(InvalidArgumentException::class);
        });

        it('returns number as-is when no country code provided', function () {
            expect(PhoneNumber::toInternational('0400000000'))->toBe('0400000000');
        });
    });

    describe('formatMultiple', function () {
        it('formats multiple comma-separated numbers', function () {
            $result = PhoneNumber::formatMultiple('0400000000, 0411111111, 0422222222', 'AU');
            expect($result)->toBe('61400000000,61411111111,61422222222');
        });

        it('handles mixed formats', function () {
            $result = PhoneNumber::formatMultiple('0400000000, 61411111111', 'AU');
            expect($result)->toBe('61400000000,61411111111');
        });

        it('throws when exceeding max recipients', function () {
            $numbers = implode(',', array_fill(0, 501, '0400000000'));
            expect(fn () => PhoneNumber::formatMultiple($numbers, 'AU'))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('isValid', function () {
        it('validates E.164 format numbers', function () {
            expect(PhoneNumber::isValid('61400000000'))->toBeTrue();
            expect(PhoneNumber::isValid('6596112234'))->toBeTrue();
            expect(PhoneNumber::isValid('12818691226'))->toBeTrue();
        });

        it('rejects numbers starting with 0', function () {
            expect(PhoneNumber::isValid('0400000000'))->toBeFalse();
        });

        it('rejects too short numbers', function () {
            expect(PhoneNumber::isValid('123456'))->toBeFalse();
        });

        it('rejects too long numbers', function () {
            expect(PhoneNumber::isValid('1234567890123456'))->toBeFalse();
        });

        it('rejects non-numeric strings', function () {
            expect(PhoneNumber::isValid('abc123'))->toBeFalse();
        });
    });

    describe('validateMultiple', function () {
        it('separates valid and invalid numbers', function () {
            $result = PhoneNumber::validateMultiple('61400000000, 0400000000, 61411111111');

            expect($result['valid'])->toBe(['61400000000', '61411111111']);
            expect($result['invalid'])->toBe(['0400000000']);
        });
    });

    describe('isInternational', function () {
        it('identifies international format', function () {
            expect(PhoneNumber::isInternational('61400000000'))->toBeTrue();
            expect(PhoneNumber::isInternational('6596112234'))->toBeTrue();
        });

        it('identifies local format', function () {
            expect(PhoneNumber::isInternational('0400000000'))->toBeFalse();
            expect(PhoneNumber::isInternational('0212172782'))->toBeFalse();
        });
    });

    describe('isValidSenderId', function () {
        it('validates phone number sender IDs', function () {
            expect(PhoneNumber::isValidSenderId('61400000000'))->toBeTrue();
        });

        it('validates alphanumeric sender IDs', function () {
            expect(PhoneNumber::isValidSenderId('MyBrand'))->toBeTrue();
            expect(PhoneNumber::isValidSenderId('Company123'))->toBeTrue();
            expect(PhoneNumber::isValidSenderId('ALERT'))->toBeTrue();
        });

        it('rejects sender IDs longer than 11 chars', function () {
            expect(PhoneNumber::isValidSenderId('TooLongSenderID'))->toBeFalse();
        });

        it('rejects sender IDs with spaces', function () {
            expect(PhoneNumber::isValidSenderId('My Brand'))->toBeFalse();
        });

        it('rejects empty sender IDs', function () {
            expect(PhoneNumber::isValidSenderId(''))->toBeFalse();
        });

        it('rejects a sender ID with a trailing newline, which PCRE $ alone would allow', function () {
            // Without /D, PCRE's $ also matches immediately before a final
            // newline, so "MyBrand\n" satisfied the alphanumeric-only rule —
            // the only rule guarding a sender ID's character set. Asserted on
            // both entry points because isValidSenderId() delegates, and on a
            // digit-shaped value because ctype_digit() rejects the newline and
            // drops that branch through to the same regex.
            expect(PhoneNumber::isValidSenderId("MyBrand\n"))->toBeFalse()
                ->and(PhoneNumber::isValidAlphanumericSenderId("MyBrand\n"))->toBeFalse()
                ->and(PhoneNumber::isValidSenderId("61400000000\n"))->toBeFalse();
        });

        it('rejects a sender ID with a leading newline', function () {
            // Guards the other direction: ^ must not become line-anchored.
            expect(PhoneNumber::isValidSenderId("\nMyBrand"))->toBeFalse();
        });
    });

    describe('countRecipients', function () {
        it('counts single recipient', function () {
            expect(PhoneNumber::countRecipients('61400000000'))->toBe(1);
        });

        it('counts multiple recipients', function () {
            expect(PhoneNumber::countRecipients('61400000000,61411111111,61422222222'))->toBe(3);
        });

        it('ignores empty strings', function () {
            expect(PhoneNumber::countRecipients('61400000000, , 61411111111'))->toBe(2);
        });
    });
});
