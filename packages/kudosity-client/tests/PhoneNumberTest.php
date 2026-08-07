<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Support\PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/PhoneNumberTest.php. This is a
 * pure support class with no container, framework, fixture or helper
 * dependency, hence its inclusion in the first batch of Task 7b.
 */
#[CoversClass(PhoneNumber::class)]
final class PhoneNumberTest extends TestCase
{
    // -----------------------------------------------------------------
    // toInternational
    // -----------------------------------------------------------------

    public function test_to_international_formats_australian_local_numbers(): void
    {
        $this->assertSame('61491570006', PhoneNumber::toInternational('0491570006', 'AU'));
        $this->assertSame('61491570012', PhoneNumber::toInternational('0491570012', 'AU'));
        $this->assertSame('61491570014', PhoneNumber::toInternational('0491570014', 'AU'));
    }

    public function test_to_international_formats_australian_numbers_with_various_formats(): void
    {
        $this->assertSame('61491570006', PhoneNumber::toInternational('0491 570 006', 'AU'));
        $this->assertSame('61491570012', PhoneNumber::toInternational('(04) 9157 0012', 'AU'));
        $this->assertSame('61491570006', PhoneNumber::toInternational('+61 491 570 006', 'AU'));
    }

    public function test_to_international_formats_new_zealand_local_numbers(): void
    {
        $this->assertSame('64212172782', PhoneNumber::toInternational('0212172782', 'NZ'));
        $this->assertSame('64212172782', PhoneNumber::toInternational('021 217 2782', 'NZ'));
    }

    public function test_to_international_formats_us_local_numbers(): void
    {
        $this->assertSame('12818691226', PhoneNumber::toInternational('(281) 869-1226', 'US'));
        $this->assertSame('12818691226', PhoneNumber::toInternational('2818691226', 'US'));
    }

    public function test_to_international_formats_singapore_numbers(): void
    {
        $this->assertSame('6596112234', PhoneNumber::toInternational('96112234', 'SG'));
    }

    public function test_to_international_returns_already_international_numbers_unchanged(): void
    {
        $this->assertSame('61491570006', PhoneNumber::toInternational('61491570006', 'AU'));
        $this->assertSame('6596112234', PhoneNumber::toInternational('6596112234', 'SG'));
    }

    public function test_to_international_works_with_country_names(): void
    {
        $this->assertSame('61491570006', PhoneNumber::toInternational('0491570006', 'Australia'));
        $this->assertSame('64212172782', PhoneNumber::toInternational('0212172782', 'New Zealand'));
    }

    public function test_to_international_throws_for_invalid_country_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::toInternational('0491570006', 'XX');
    }

    public function test_a_country_name_resolves_the_same_dialing_code_as_its_iso_alias(): void
    {
        // The table maps both an ISO code and a full country name to the
        // same dialing code — asserted by the two producing an identical
        // result rather than by reading the constant directly. Moved here
        // from ValueObjectTest.php in Task 7b batch 1's fix round: this
        // class now owns Support\PhoneNumber.
        $this->assertSame(
            PhoneNumber::toInternational('0491570006', 'AU'),
            PhoneNumber::toInternational('0491570006', 'Australia'),
        );
    }

    public function test_an_unsupported_country_code_is_rejected(): void
    {
        // Same behaviour as test_to_international_throws_for_invalid_country_code
        // above (a different invalid literal, 'ZZ' vs 'XX') — kept as a
        // separate test because it was moved verbatim from ValueObjectTest.php
        // in Task 7b batch 1's fix round rather than folded into the other.
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::toInternational('0491570006', 'ZZ');
    }

    public function test_to_international_returns_number_as_is_when_no_country_code_provided(): void
    {
        $this->assertSame('0491570006', PhoneNumber::toInternational('0491570006'));
    }

    // -----------------------------------------------------------------
    // formatMultiple
    // -----------------------------------------------------------------

    public function test_format_multiple_formats_comma_separated_numbers(): void
    {
        $result = PhoneNumber::formatMultiple('0491570006, 0491570011, 0491570013', 'AU');

        $this->assertSame('61491570006,61491570011,61491570013', $result);
    }

    public function test_format_multiple_handles_mixed_formats(): void
    {
        $result = PhoneNumber::formatMultiple('0491570006, 61491570011', 'AU');

        $this->assertSame('61491570006,61491570011', $result);
    }

    public function test_format_multiple_throws_when_exceeding_max_recipients(): void
    {
        $numbers = implode(',', array_fill(0, 501, '0491570006'));

        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::formatMultiple($numbers, 'AU');
    }

    // -----------------------------------------------------------------
    // isValid
    // -----------------------------------------------------------------

    public function test_is_valid_validates_e164_format_numbers(): void
    {
        $this->assertTrue(PhoneNumber::isValid('61491570006'));
        $this->assertTrue(PhoneNumber::isValid('6596112234'));
        $this->assertTrue(PhoneNumber::isValid('12818691226'));
    }

    public function test_is_valid_rejects_numbers_starting_with_zero(): void
    {
        $this->assertFalse(PhoneNumber::isValid('0491570006'));
    }

    public function test_is_valid_rejects_too_short_numbers(): void
    {
        $this->assertFalse(PhoneNumber::isValid('123456'));
    }

    public function test_is_valid_rejects_too_long_numbers(): void
    {
        $this->assertFalse(PhoneNumber::isValid('1234567890123456'));
    }

    public function test_is_valid_rejects_non_numeric_strings(): void
    {
        $this->assertFalse(PhoneNumber::isValid('abc123'));
    }

    // -----------------------------------------------------------------
    // validateMultiple
    // -----------------------------------------------------------------

    public function test_validate_multiple_separates_valid_and_invalid_numbers(): void
    {
        $result = PhoneNumber::validateMultiple('61491570006, 0491570006, 61491570011');

        $this->assertSame(['61491570006', '61491570011'], $result['valid']);
        $this->assertSame(['0491570006'], $result['invalid']);
    }

    // -----------------------------------------------------------------
    // isInternational
    // -----------------------------------------------------------------

    public function test_is_international_identifies_international_format(): void
    {
        $this->assertTrue(PhoneNumber::isInternational('61491570006'));
        $this->assertTrue(PhoneNumber::isInternational('6596112234'));
    }

    public function test_is_international_identifies_local_format(): void
    {
        $this->assertFalse(PhoneNumber::isInternational('0491570006'));
        $this->assertFalse(PhoneNumber::isInternational('0212172782'));
    }

    // -----------------------------------------------------------------
    // isValidSenderId
    // -----------------------------------------------------------------

    public function test_is_valid_sender_id_validates_phone_number_sender_ids(): void
    {
        $this->assertTrue(PhoneNumber::isValidSenderId('61491570006'));
    }

    public function test_is_valid_sender_id_validates_alphanumeric_sender_ids(): void
    {
        $this->assertTrue(PhoneNumber::isValidSenderId('MyBrand'));
        $this->assertTrue(PhoneNumber::isValidSenderId('Company123'));
        $this->assertTrue(PhoneNumber::isValidSenderId('ALERT'));
    }

    public function test_is_valid_sender_id_rejects_sender_ids_longer_than_11_chars(): void
    {
        $this->assertFalse(PhoneNumber::isValidSenderId('TooLongSenderID'));
    }

    public function test_is_valid_sender_id_rejects_sender_ids_with_spaces(): void
    {
        $this->assertFalse(PhoneNumber::isValidSenderId('My Brand'));
    }

    public function test_is_valid_sender_id_rejects_empty_sender_ids(): void
    {
        $this->assertFalse(PhoneNumber::isValidSenderId(''));
    }

    public function test_is_valid_sender_id_rejects_a_trailing_newline_which_pcre_dollar_alone_would_allow(): void
    {
        // Without /D, PCRE's $ also matches immediately before a final
        // newline, so "MyBrand\n" satisfied the alphanumeric-only rule —
        // the only rule guarding a sender ID's character set. Asserted on
        // both entry points because isValidSenderId() delegates, and on a
        // digit-shaped value because ctype_digit() rejects the newline and
        // drops that branch through to the same regex.
        $this->assertFalse(PhoneNumber::isValidSenderId("MyBrand\n"));
        $this->assertFalse(PhoneNumber::isValidAlphanumericSenderId("MyBrand\n"));
        $this->assertFalse(PhoneNumber::isValidSenderId("61491570006\n"));
    }

    public function test_is_valid_sender_id_rejects_a_leading_newline(): void
    {
        // Guards the other direction: ^ must not become line-anchored.
        $this->assertFalse(PhoneNumber::isValidSenderId("\nMyBrand"));
    }

    // -----------------------------------------------------------------
    // countRecipients
    // -----------------------------------------------------------------

    public function test_count_recipients_counts_single_recipient(): void
    {
        $this->assertSame(1, PhoneNumber::countRecipients('61491570006'));
    }

    public function test_count_recipients_counts_multiple_recipients(): void
    {
        $this->assertSame(3, PhoneNumber::countRecipients('61491570006,61491570011,61491570013'));
    }

    public function test_count_recipients_ignores_empty_strings(): void
    {
        $this->assertSame(2, PhoneNumber::countRecipients('61491570006, , 61491570011'));
    }
}
