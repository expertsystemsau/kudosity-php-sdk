<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/SendSmsRequestTest.php.
 *
 * Holds the callback-URL SSRF guards — the security-relevant part of this
 * batch. Every blocked case stays its own test method rather than being
 * collapsed into a loop.
 *
 * Support\Url and Support\PhoneNumber are exercised extensively here (every
 * *Callback() setter delegates to Url::validateCallbackUrl(), and to()
 * delegates to PhoneNumber::countRecipients()), but both already have their
 * own direct test files from batch 1 (UrlValidationTest.php,
 * PhoneNumberTest.php), so they are not repeated in the covers list.
 */
#[CoversClass(SendSmsRequest::class)]
final class SendSmsRequestTest extends TestCase
{
    // -----------------------------------------------------------------
    // construction
    // -----------------------------------------------------------------

    public function test_creates_request_with_message(): void
    {
        $request = new SendSmsRequest('Hello World');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_rejects_message_exceeding_max_length(): void
    {
        $longMessage = str_repeat('a', 613);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds maximum');

        new SendSmsRequest($longMessage);
    }

    public function test_accepts_message_at_max_length(): void
    {
        $maxMessage = str_repeat('a', 612);
        $request = new SendSmsRequest($maxMessage);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    // -----------------------------------------------------------------
    // fluent builder
    // -----------------------------------------------------------------

    public function test_chains_to_method(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->to('61400000000');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_chains_to_list_method(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->toList(12345);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_chains_from_method(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->to('61400000000')
            ->from('MyBrand');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_chains_country_code_method(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->to('0400000000')
            ->countryCode('AU');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_chains_all_methods_together(): void
    {
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

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    // -----------------------------------------------------------------
    // scheduledAt
    // -----------------------------------------------------------------

    public function test_scheduled_at_accepts_string_datetime(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->to('61400000000')
            ->scheduledAt('2025-12-06 10:00:00');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_scheduled_at_accepts_date_time_object(): void
    {
        $dateTime = new DateTime('2025-12-06 10:00:00', new DateTimeZone('UTC'));
        $request = (new SendSmsRequest('Test'))
            ->to('61400000000')
            ->scheduledAt($dateTime);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_scheduled_at_accepts_date_time_immutable_object(): void
    {
        $dateTime = new DateTimeImmutable('2025-12-06 10:00:00', new DateTimeZone('UTC'));
        $request = (new SendSmsRequest('Test'))
            ->to('61400000000')
            ->scheduledAt($dateTime);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_scheduled_at_converts_non_utc_datetime_to_utc(): void
    {
        // Create a datetime in Sydney timezone (UTC+11 in December)
        $sydneyTime = new DateTime('2025-12-06 21:00:00', new DateTimeZone('Australia/Sydney'));
        $request = (new SendSmsRequest('Test'))
            ->to('61400000000')
            ->scheduledAt($sydneyTime);

        // The internal sendAt should be in UTC (21:00 Sydney = 10:00 UTC)
        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    // -----------------------------------------------------------------
    // resolveEndpoint
    // -----------------------------------------------------------------

    public function test_resolve_endpoint_returns_the_correct_endpoint(): void
    {
        $request = new SendSmsRequest('Test');

        $this->assertSame('/send-sms.json', $request->resolveEndpoint());
    }

    // -----------------------------------------------------------------
    // formatNumbers
    // -----------------------------------------------------------------

    public function test_can_enable_number_formatting(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->to('0400000000')
            ->countryCode('AU')
            ->formatNumbers();

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    // -----------------------------------------------------------------
    // URL validation
    // -----------------------------------------------------------------

    public function test_validates_tracked_link_url_accepts_valid_https_url(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->trackedLinkUrl('https://example.com/page');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_validates_tracked_link_url_rejects_invalid_url(): void
    {
        $this->expectException(ValidationException::class);

        (new SendSmsRequest('Test'))->trackedLinkUrl('not-a-url');
    }

    public function test_validates_dlr_callback_accepts_valid_url(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->dlrCallback('https://myapp.com/webhook/dlr');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_validates_dlr_callback_rejects_invalid_url(): void
    {
        $this->expectException(ValidationException::class);

        (new SendSmsRequest('Test'))->dlrCallback('ftp://invalid.com');
    }

    public function test_validates_reply_callback_accepts_valid_url(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->replyCallback('https://myapp.com/webhook/reply');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_validates_reply_callback_rejects_invalid_url(): void
    {
        $this->expectException(ValidationException::class);

        (new SendSmsRequest('Test'))->replyCallback('');
    }

    public function test_validates_link_hits_callback_accepts_valid_url(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->linkHitsCallback('https://myapp.com/webhook/clicks');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_validates_link_hits_callback_rejects_invalid_url(): void
    {
        $this->expectException(ValidationException::class);

        (new SendSmsRequest('Test'))->linkHitsCallback('javascript:alert(1)');
    }

    // -----------------------------------------------------------------
    // callback URL SSRF protection
    // -----------------------------------------------------------------

    public function test_dlr_callback_rejects_localhost(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('localhost');

        (new SendSmsRequest('Test'))->dlrCallback('http://localhost/webhook');
    }

    public function test_dlr_callback_rejects_private_ips(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('internal or private');

        (new SendSmsRequest('Test'))->dlrCallback('http://192.168.1.1/webhook');
    }

    public function test_reply_callback_rejects_localhost(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('localhost');

        (new SendSmsRequest('Test'))->replyCallback('http://localhost/webhook');
    }

    public function test_link_hits_callback_rejects_localhost(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('localhost');

        (new SendSmsRequest('Test'))->linkHitsCallback('http://localhost/webhook');
    }

    public function test_tracked_link_url_allows_localhost_not_a_callback(): void
    {
        // trackedLinkUrl is for the user to click, not for Kudosity to hit
        // so it doesn't need SSRF protection
        $request = (new SendSmsRequest('Test'))
            ->trackedLinkUrl('http://localhost/page');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    // -----------------------------------------------------------------
    // email validation
    // -----------------------------------------------------------------

    public function test_validates_replies_to_email_accepts_valid_email(): void
    {
        $request = (new SendSmsRequest('Test'))
            ->repliesToEmail('test@example.com');

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_validates_replies_to_email_rejects_invalid_email(): void
    {
        $this->expectException(ValidationException::class);

        (new SendSmsRequest('Test'))->repliesToEmail('not-an-email');
    }

    public function test_validates_replies_to_email_rejects_empty_email(): void
    {
        $this->expectException(ValidationException::class);

        (new SendSmsRequest('Test'))->repliesToEmail('');
    }

    // -----------------------------------------------------------------
    // recipient validation
    // -----------------------------------------------------------------

    public function test_accepts_valid_number_of_recipients(): void
    {
        $recipients = implode(',', array_fill(0, 100, '61400000000'));
        $request = (new SendSmsRequest('Test'))->to($recipients);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_rejects_more_than_500_recipients(): void
    {
        $recipients = implode(',', array_fill(0, 501, '61400000000'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds maximum');

        (new SendSmsRequest('Test'))->to($recipients);
    }

    // -----------------------------------------------------------------
    // validity validation
    // -----------------------------------------------------------------

    public function test_accepts_valid_validity_period(): void
    {
        $request = (new SendSmsRequest('Test'))->validity(60);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_accepts_zero_validity_maximum_period(): void
    {
        $request = (new SendSmsRequest('Test'))->validity(0);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_accepts_max_validity_72_hours(): void
    {
        $request = (new SendSmsRequest('Test'))->validity(4320);

        $this->assertInstanceOf(SendSmsRequest::class, $request);
    }

    public function test_rejects_negative_validity(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be negative');

        (new SendSmsRequest('Test'))->validity(-1);
    }

    public function test_rejects_validity_exceeding_max(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds maximum');

        (new SendSmsRequest('Test'))->validity(4321);
    }
}
