<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Support\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/UrlValidationTest.php. This is
 * a pure support class with no container, framework, fixture or helper
 * dependency, hence its inclusion in the first batch of Task 7b.
 *
 * Support\Url had no reference anywhere in the client suite before this file
 * — these 55 tests, including the SSRF guard, had never run on PHP 8.2.
 */
#[CoversClass(Url::class)]
final class UrlValidationTest extends TestCase
{
    // -----------------------------------------------------------------
    // validate()
    // -----------------------------------------------------------------

    public function test_validate_accepts_valid_https_url(): void
    {
        Url::validate('https://example.com/webhook');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_accepts_valid_http_url(): void
    {
        Url::validate('http://example.com/webhook');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_accepts_url_with_port(): void
    {
        Url::validate('https://example.com:8080/webhook');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_accepts_url_with_query_parameters(): void
    {
        Url::validate('https://example.com/webhook?token=abc123');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_rejects_empty_url(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be empty');

        Url::validate('');
    }

    public function test_validate_rejects_invalid_url_format(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not a valid URL');

        Url::validate('not-a-url');
    }

    public function test_validate_rejects_ftp_urls(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must use HTTP or HTTPS');

        Url::validate('ftp://example.com/file');
    }

    public function test_validate_rejects_file_urls(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must use HTTP or HTTPS');

        Url::validate('file:///etc/passwd');
    }

    public function test_validate_rejects_mailto_urls(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must use HTTP or HTTPS');

        Url::validate('mailto:test@example.com');
    }

    public function test_validate_includes_field_name_in_error_message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('dlr_callback');

        Url::validate('invalid', 'dlr_callback');
    }

    public function test_validate_sets_error_code_to_field_empty_for_empty_urls(): void
    {
        try {
            Url::validate('', 'test_field');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame('FIELD_EMPTY', $e->getErrorCode());
        }
    }

    public function test_validate_sets_error_code_to_field_invalid_for_invalid_urls(): void
    {
        try {
            Url::validate('not-a-url', 'test_field');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame('FIELD_INVALID', $e->getErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // isValid()
    // -----------------------------------------------------------------

    public function test_is_valid_returns_true_for_valid_https_url(): void
    {
        $this->assertTrue(Url::isValid('https://example.com/webhook'));
    }

    public function test_is_valid_returns_true_for_valid_http_url(): void
    {
        $this->assertTrue(Url::isValid('http://example.com/webhook'));
    }

    public function test_is_valid_returns_false_for_empty_string(): void
    {
        $this->assertFalse(Url::isValid(''));
    }

    public function test_is_valid_returns_false_for_invalid_url(): void
    {
        $this->assertFalse(Url::isValid('not-a-url'));
    }

    public function test_is_valid_returns_false_for_ftp_url(): void
    {
        $this->assertFalse(Url::isValid('ftp://example.com'));
    }

    // -----------------------------------------------------------------
    // validateEmail()
    // -----------------------------------------------------------------

    public function test_validate_email_accepts_valid_email(): void
    {
        Url::validateEmail('test@example.com');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_email_accepts_email_with_subdomain(): void
    {
        Url::validateEmail('test@mail.example.com');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_email_accepts_email_with_plus_sign(): void
    {
        Url::validateEmail('test+tag@example.com');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_email_rejects_empty_email(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be empty');

        Url::validateEmail('');
    }

    public function test_validate_email_rejects_invalid_email_format(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not a valid email');

        Url::validateEmail('not-an-email');
    }

    public function test_validate_email_rejects_email_without_domain(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not a valid email');

        Url::validateEmail('test@');
    }

    public function test_validate_email_includes_field_name_in_error_message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('replies_to_email');

        Url::validateEmail('invalid', 'replies_to_email');
    }

    // -----------------------------------------------------------------
    // isValidEmail()
    // -----------------------------------------------------------------

    public function test_is_valid_email_returns_true_for_valid_email(): void
    {
        $this->assertTrue(Url::isValidEmail('test@example.com'));
    }

    public function test_is_valid_email_returns_false_for_empty_string(): void
    {
        $this->assertFalse(Url::isValidEmail(''));
    }

    public function test_is_valid_email_returns_false_for_invalid_email(): void
    {
        $this->assertFalse(Url::isValidEmail('not-an-email'));
    }

    // -----------------------------------------------------------------
    // validateCallbackUrl() - SSRF protection
    // -----------------------------------------------------------------

    public function test_validate_callback_url_accepts_valid_external_https_url(): void
    {
        Url::validateCallbackUrl('https://example.com/webhook');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_callback_url_accepts_valid_external_http_url(): void
    {
        Url::validateCallbackUrl('http://api.example.com/callback');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_callback_url_rejects_localhost_url(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://localhost/callback');
    }

    public function test_validate_callback_url_rejects_localhost_with_port(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://localhost:8080/callback');
    }

    public function test_validate_callback_url_rejects_127_0_0_1_loopback(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://127.0.0.1/callback');
    }

    public function test_validate_callback_url_rejects_other_127_x_x_x_loopback_addresses(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://127.0.0.2/callback');
    }

    public function test_validate_callback_url_rejects_10_x_x_x_private_range(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://10.0.0.1/callback');
    }

    public function test_validate_callback_url_rejects_172_16_x_x_private_range(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://172.16.0.1/callback');
    }

    public function test_validate_callback_url_rejects_172_31_x_x_private_range(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://172.31.255.255/callback');
    }

    public function test_validate_callback_url_accepts_172_32_x_x_outside_private_range(): void
    {
        Url::validateCallbackUrl('http://172.32.0.1/callback');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_callback_url_rejects_192_168_x_x_private_range(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://192.168.1.1/callback');
    }

    public function test_validate_callback_url_rejects_aws_metadata_endpoint(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://169.254.169.254/latest/meta-data/');
    }

    public function test_validate_callback_url_rejects_link_local_range(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://169.254.1.1/callback');
    }

    public function test_validate_callback_url_rejects_0_0_0_0(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not point to internal or private resources');

        Url::validateCallbackUrl('http://0.0.0.0/callback');
    }

    public function test_validate_callback_url_includes_custom_field_name_in_error_message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('dlr_callback');

        Url::validateCallbackUrl('http://localhost/webhook', 'dlr_callback');
    }

    public function test_validate_callback_url_sets_error_code_to_field_unsafe_for_internal_urls(): void
    {
        try {
            Url::validateCallbackUrl('http://localhost/callback');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame('FIELD_UNSAFE', $e->getErrorCode());
        }
    }

    public function test_validate_callback_url_rejects_empty_url(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be empty');

        Url::validateCallbackUrl('');
    }

    public function test_validate_callback_url_rejects_invalid_url_format(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not a valid URL');

        Url::validateCallbackUrl('not-a-url');
    }

    // -----------------------------------------------------------------
    // isCallbackUrlSafe()
    // -----------------------------------------------------------------

    public function test_is_callback_url_safe_returns_true_for_valid_external_url(): void
    {
        $this->assertTrue(Url::isCallbackUrlSafe('https://example.com/webhook'));
    }

    public function test_is_callback_url_safe_returns_false_for_localhost(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe('http://localhost/callback'));
    }

    public function test_is_callback_url_safe_returns_false_for_127_0_0_1(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe('http://127.0.0.1/callback'));
    }

    public function test_is_callback_url_safe_returns_false_for_private_10_x_x_x_range(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe('http://10.0.0.1/callback'));
    }

    public function test_is_callback_url_safe_returns_false_for_private_192_168_x_x_range(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe('http://192.168.1.1/callback'));
    }

    public function test_is_callback_url_safe_returns_false_for_aws_metadata_endpoint(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe('http://169.254.169.254/metadata'));
    }

    public function test_is_callback_url_safe_returns_false_for_empty_string(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe(''));
    }

    public function test_is_callback_url_safe_returns_false_for_invalid_url(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe('not-a-url'));
    }

    public function test_is_callback_url_safe_returns_true_for_public_ip_addresses(): void
    {
        $this->assertTrue(Url::isCallbackUrlSafe('http://8.8.8.8/callback'));
    }

    public function test_is_callback_url_safe_returns_false_for_ipv6_loopback(): void
    {
        $this->assertFalse(Url::isCallbackUrlSafe('http://[::1]/callback'));
    }
}
