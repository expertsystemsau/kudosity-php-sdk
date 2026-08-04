<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Support\CountryCodes;

describe('CountryCodes', function () {
    describe('getDialingCode', function () {
        it('returns dialing codes for ISO country codes', function () {
            expect(CountryCodes::getDialingCode('AU'))->toBe('61');
            expect(CountryCodes::getDialingCode('NZ'))->toBe('64');
            expect(CountryCodes::getDialingCode('US'))->toBe('1');
            expect(CountryCodes::getDialingCode('GB'))->toBe('44');
            expect(CountryCodes::getDialingCode('SG'))->toBe('65');
        });

        it('returns dialing codes for country names', function () {
            expect(CountryCodes::getDialingCode('Australia'))->toBe('61');
            expect(CountryCodes::getDialingCode('New Zealand'))->toBe('64');
            expect(CountryCodes::getDialingCode('United States'))->toBe('1');
            expect(CountryCodes::getDialingCode('Singapore'))->toBe('65');
        });

        it('is case insensitive', function () {
            expect(CountryCodes::getDialingCode('au'))->toBe('61');
            expect(CountryCodes::getDialingCode('Au'))->toBe('61');
            expect(CountryCodes::getDialingCode('AUSTRALIA'))->toBe('61');
            expect(CountryCodes::getDialingCode('australia'))->toBe('61');
        });

        it('returns null for unknown codes', function () {
            expect(CountryCodes::getDialingCode('XX'))->toBeNull();
            expect(CountryCodes::getDialingCode('Unknown Country'))->toBeNull();
        });

        it('handles aliases', function () {
            expect(CountryCodes::getDialingCode('UK'))->toBe('44');
            expect(CountryCodes::getDialingCode('USA'))->toBe('1');
            expect(CountryCodes::getDialingCode('UAE'))->toBe('971');
        });
    });

    describe('isSupported', function () {
        it('returns true for supported codes', function () {
            expect(CountryCodes::isSupported('AU'))->toBeTrue();
            expect(CountryCodes::isSupported('Australia'))->toBeTrue();
        });

        it('returns false for unsupported codes', function () {
            expect(CountryCodes::isSupported('XX'))->toBeFalse();
        });
    });

    describe('normalizeToIso', function () {
        it('returns ISO code for country names', function () {
            expect(CountryCodes::normalizeToIso('Australia'))->toBe('AU');
            expect(CountryCodes::normalizeToIso('United Kingdom'))->toBe('GB');
        });

        it('returns ISO code unchanged for valid ISO codes', function () {
            expect(CountryCodes::normalizeToIso('AU'))->toBe('AU');
            expect(CountryCodes::normalizeToIso('GB'))->toBe('GB');
        });

        it('returns null for unknown values', function () {
            expect(CountryCodes::normalizeToIso('Unknown'))->toBeNull();
        });
    });
});
