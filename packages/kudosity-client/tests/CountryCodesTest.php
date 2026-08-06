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

    // -----------------------------------------------------------------
    // matchDialingCode / startsWithKnownDialingCode
    //
    // Added in Task 7b batch 1's fix round: before this, the only caller
    // anywhere in either suite was PhoneNumber::isInternational(), tested
    // exclusively from PhoneNumberTest.php — which carries
    // #[CoversClass(PhoneNumber::class)], not CountryCodes::class. PHPUnit's
    // coverage attribution is scoped by a test's own covers metadata, so
    // that indirect exercise never counted towards CountryCodes.php's
    // coverage at all, in either suite, on either side of the port. Direct
    // tests here, under this file's #[CoversClass(CountryCodes::class)], is
    // the fix — the class that reads self::CODES owns the test that reads
    // it directly.
    // -----------------------------------------------------------------

    public function test_match_dialing_code_returns_the_matched_code_for_a_known_prefix(): void
    {
        $this->assertSame('61', CountryCodes::matchDialingCode('61400000000'));
        $this->assertSame('1', CountryCodes::matchDialingCode('12818691226'));
    }

    public function test_match_dialing_code_returns_null_when_nothing_matches(): void
    {
        // No known dialing code starts with '0', so a number that starts
        // with a leading zero (a local-format number, never itself a valid
        // dialing-code prefix) cannot match anything.
        $this->assertNull(CountryCodes::matchDialingCode('0400000000'));
    }

    public function test_starts_with_known_dialing_code_wraps_match_dialing_code(): void
    {
        $this->assertTrue(CountryCodes::startsWithKnownDialingCode('61400000000'));
        $this->assertFalse(CountryCodes::startsWithKnownDialingCode('0400000000'));
    }

    // Not asserted: that the longest matching code wins over a shorter one
    // sharing the same prefix (the reason matchDialingCode() sorts by length
    // before scanning — see its docblock's own '61' vs '6' example). Checked
    // this exhaustively against the real table: of the 64 unique dialing
    // codes in self::CODES, none is a prefix of any other (verified by
    // pairwise str_starts_with() over all of them). Since a shorter code can
    // only ever out-match a longer one on a string where the shorter is
    // genuinely a prefix of it, there is currently no real input for which
    // sort order changes matchDialingCode()'s result — a test asserting
    // "longest wins" against this table would pass whether or not the sort
    // exists, which is exactly the kind of test this whole phase exists to
    // rule out. The sort is defensive against a future entry creating a
    // collision, not something today's data can exercise.
}
