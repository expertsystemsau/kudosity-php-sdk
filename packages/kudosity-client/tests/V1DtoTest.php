<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\BalanceData;
use ExpertSystems\Kudosity\Data\ContactData;
use ExpertSystems\Kudosity\Data\ListData;
use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Data\SmsListData;
use ExpertSystems\Kudosity\Data\SmsStatsData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/DtoTest.php. Renamed to
 * V1DtoTest because the client suite's DtoTest.php already covers
 * Data\V2\* exclusively — zero class overlap with the V1 DTOs tested here.
 */
#[CoversClass(BalanceData::class)]
#[CoversClass(ContactData::class)]
#[CoversClass(ListData::class)]
#[CoversClass(SmsData::class)]
#[CoversClass(SmsListData::class)]
#[CoversClass(SmsStatsData::class)]
final class V1DtoTest extends TestCase
{
    // -----------------------------------------------------------------
    // BalanceData
    // -----------------------------------------------------------------

    public function test_balance_data_creates_from_api_response(): void
    {
        $dto = BalanceData::fromResponse(['balance' => 150.50, 'currency' => 'AUD']);

        $this->assertSame(150.50, $dto->balance);
        $this->assertSame('AUD', $dto->currency);
    }

    public function test_balance_data_accepts_different_currencies(): void
    {
        $dto = BalanceData::fromResponse(['balance' => 100.00, 'currency' => 'USD']);

        $this->assertSame(100.00, $dto->balance);
        $this->assertSame('USD', $dto->currency);
    }

    public function test_balance_data_coerces_string_balance_to_float(): void
    {
        $dto = BalanceData::fromResponse(['balance' => '75.25', 'currency' => 'USD']);

        $this->assertSame(75.25, $dto->balance);
    }

    // -----------------------------------------------------------------
    // SmsData
    // -----------------------------------------------------------------

    public function test_sms_data_creates_from_send_sms_response(): void
    {
        $dto = SmsData::fromResponse([
            'message_id' => 12345,
            'send_at' => '2024-01-15 10:30:00',
            'recipients' => 5,
            'cost' => 0.25,
            'sms' => 1,
        ]);

        $this->assertSame(12345, $dto->messageId);
        $this->assertSame('2024-01-15 10:30:00', $dto->sendAt);
        $this->assertSame(5, $dto->recipients);
        $this->assertSame(0.25, $dto->cost);
        $this->assertSame(1, $dto->sms);
        $this->assertNull($dto->list);
    }

    public function test_sms_data_includes_list_data_when_present(): void
    {
        $dto = SmsData::fromResponse([
            'message_id' => 12345,
            'send_at' => '2024-01-15 10:30:00',
            'recipients' => 100,
            'cost' => 5.00,
            'sms' => 1,
            'list' => [
                'id' => 999,
                'name' => 'Test List',
            ],
        ]);

        $this->assertNotNull($dto->list);
        $this->assertSame(999, $dto->list->id);
        $this->assertSame('Test List', $dto->list->name);
    }

    // -----------------------------------------------------------------
    // ContactData
    // -----------------------------------------------------------------

    public function test_contact_data_creates_from_contact_response(): void
    {
        $dto = ContactData::fromResponse([
            'contact' => [
                'mobile' => '61491570006',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'status' => 'active',
                'date_added' => '2024-01-01 00:00:00',
            ],
        ]);

        $this->assertSame('61491570006', $dto->mobile);
        $this->assertSame('John', $dto->firstName);
        $this->assertSame('Doe', $dto->lastName);
        $this->assertSame('active', $dto->status);
        $this->assertTrue($dto->isActive());
        $this->assertFalse($dto->isOptedOut());
    }

    public function test_contact_data_extracts_custom_fields(): void
    {
        $dto = ContactData::fromResponse([
            'mobile' => '61491570006',
            'firstname' => '',
            'lastname' => '',
            'status' => 'active',
            'field_1' => 'Company ABC',
            'field_2' => 'Manager',
            'field_3' => '',
        ]);

        $this->assertArrayHasKey('field_1', $dto->customFields);
        $this->assertSame('Company ABC', $dto->customFields['field_1']);
        $this->assertArrayHasKey('field_2', $dto->customFields);
        $this->assertArrayNotHasKey('field_3', $dto->customFields); // Empty values excluded
    }

    public function test_contact_data_handles_msisdn_field_alias(): void
    {
        $dto = ContactData::fromResponse([
            'msisdn' => '61491570006',
            'firstname' => 'Jane',
            'lastname' => 'Smith',
            'status' => 'optout',
        ]);

        $this->assertSame('61491570006', $dto->mobile);
        $this->assertTrue($dto->isOptedOut());
    }

    public function test_contact_data_throws_exception_when_mobile_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid mobile number');

        ContactData::fromResponse([
            'firstname' => 'John',
            'lastname' => 'Doe',
            'status' => 'active',
        ]);
    }

    public function test_contact_data_throws_exception_when_mobile_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid mobile number');

        ContactData::fromResponse([
            'mobile' => '',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'status' => 'active',
        ]);
    }

    // -----------------------------------------------------------------
    // ListData
    // -----------------------------------------------------------------

    public function test_list_data_creates_from_list_response(): void
    {
        $dto = ListData::fromResponse([
            'list' => [
                'id' => 123,
                'name' => 'My Contacts',
                'members' => 500,
                'field_1' => 'Company',
                'field_2' => 'Department',
            ],
        ]);

        $this->assertSame(123, $dto->id);
        $this->assertSame('My Contacts', $dto->name);
        $this->assertSame(500, $dto->members);
        $this->assertArrayHasKey('field_1', $dto->fields);
        $this->assertSame('Company', $dto->fields['field_1']);
    }

    public function test_list_data_handles_list_id_alias(): void
    {
        $dto = ListData::fromResponse(['list_id' => 456, 'name' => 'Test', 'members' => 0]);

        $this->assertSame(456, $dto->id);
    }

    // -----------------------------------------------------------------
    // SmsStatsData
    // -----------------------------------------------------------------

    public function test_sms_stats_data_creates_from_stats_response(): void
    {
        $dto = SmsStatsData::fromResponse([
            'stats' => [
                'sent' => 100,
                'delivered' => 95,
                'pending' => 3,
                'bounced' => 2,
                'responses' => 10,
                'optouts' => 1,
            ],
        ]);

        $this->assertSame(100, $dto->sent);
        $this->assertSame(95, $dto->delivered);
        $this->assertSame(3, $dto->pending);
        $this->assertSame(2, $dto->bounced);
        $this->assertSame(10, $dto->responses);
        $this->assertSame(1, $dto->optouts);
    }

    public function test_sms_stats_data_calculates_delivery_rate(): void
    {
        $dto = SmsStatsData::fromResponse([
            'sent' => 100,
            'delivered' => 80,
            'pending' => 10,
            'bounced' => 10,
            'responses' => 5,
            'optouts' => 0,
        ]);

        $this->assertSame(80.0, $dto->getDeliveryRate());
        $this->assertSame(10.0, $dto->getBounceRate());
        $this->assertSame(5.0, $dto->getResponseRate());
    }

    public function test_sms_stats_data_handles_zero_sent_for_rate_calculations(): void
    {
        $dto = SmsStatsData::fromResponse([
            'sent' => 0,
            'delivered' => 0,
            'pending' => 0,
            'bounced' => 0,
            'responses' => 0,
            'optouts' => 0,
        ]);

        $this->assertSame(0.0, $dto->getDeliveryRate());
        $this->assertSame(0.0, $dto->getBounceRate());
    }
}
