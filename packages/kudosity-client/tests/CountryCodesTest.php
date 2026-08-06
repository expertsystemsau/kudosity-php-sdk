<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Support\CountryCodes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/CountryCodesTest.php. This is a
 * pure support class with no container, framework, fixture or helper
 * dependency, hence its inclusion in the first batch of Task 7b.
 */
#[CoversClass(CountryCodes::class)]
final class CountryCodesTest extends TestCase
{
    // -----------------------------------------------------------------
    // getDialingCode
    // -----------------------------------------------------------------

    public function test_get_dialing_code_returns_dialing_codes_for_iso_country_codes(): void
    {
        $this->assertSame('61', CountryCodes::getDialingCode('AU'));
        $this->assertSame('64', CountryCodes::getDialingCode('NZ'));
        $this->assertSame('1', CountryCodes::getDialingCode('US'));
        $this->assertSame('44', CountryCodes::getDialingCode('GB'));
        $this->assertSame('65', CountryCodes::getDialingCode('SG'));
    }

    public function test_get_dialing_code_returns_dialing_codes_for_country_names(): void
    {
        $this->assertSame('61', CountryCodes::getDialingCode('Australia'));
        $this->assertSame('64', CountryCodes::getDialingCode('New Zealand'));
        $this->assertSame('1', CountryCodes::getDialingCode('United States'));
        $this->assertSame('65', CountryCodes::getDialingCode('Singapore'));
    }

    public function test_get_dialing_code_is_case_insensitive(): void
    {
        $this->assertSame('61', CountryCodes::getDialingCode('au'));
        $this->assertSame('61', CountryCodes::getDialingCode('Au'));
        $this->assertSame('61', CountryCodes::getDialingCode('AUSTRALIA'));
        $this->assertSame('61', CountryCodes::getDialingCode('australia'));
    }

    public function test_get_dialing_code_returns_null_for_unknown_codes(): void
    {
        $this->assertNull(CountryCodes::getDialingCode('XX'));
        $this->assertNull(CountryCodes::getDialingCode('Unknown Country'));
    }

    public function test_get_dialing_code_handles_aliases(): void
    {
        $this->assertSame('44', CountryCodes::getDialingCode('UK'));
        $this->assertSame('1', CountryCodes::getDialingCode('USA'));
        $this->assertSame('971', CountryCodes::getDialingCode('UAE'));
    }

    // -----------------------------------------------------------------
    // isSupported
    // -----------------------------------------------------------------

    public function test_is_supported_returns_true_for_supported_codes(): void
    {
        $this->assertTrue(CountryCodes::isSupported('AU'));
        $this->assertTrue(CountryCodes::isSupported('Australia'));
    }

    public function test_is_supported_returns_false_for_unsupported_codes(): void
    {
        $this->assertFalse(CountryCodes::isSupported('XX'));
    }

    // -----------------------------------------------------------------
    // normalizeToIso
    // -----------------------------------------------------------------

    public function test_normalize_to_iso_returns_iso_code_for_country_names(): void
    {
        $this->assertSame('AU', CountryCodes::normalizeToIso('Australia'));
        $this->assertSame('GB', CountryCodes::normalizeToIso('United Kingdom'));
    }

    public function test_normalize_to_iso_returns_iso_code_unchanged_for_valid_iso_codes(): void
    {
        $this->assertSame('AU', CountryCodes::normalizeToIso('AU'));
        $this->assertSame('GB', CountryCodes::normalizeToIso('GB'));
    }

    public function test_normalize_to_iso_returns_null_for_unknown_values(): void
    {
        $this->assertNull(CountryCodes::normalizeToIso('Unknown'));
    }
}
