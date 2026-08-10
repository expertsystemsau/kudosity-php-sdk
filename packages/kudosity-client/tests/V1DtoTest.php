<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\BalanceData;
use ExpertSystems\Kudosity\Data\BulkProgressData;
use ExpertSystems\Kudosity\Data\ContactData;
use ExpertSystems\Kudosity\Data\ContactSmsStatsData;
use ExpertSystems\Kudosity\Data\ListData;
use ExpertSystems\Kudosity\Data\MessageReportData;
use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Data\SmsListData;
use ExpertSystems\Kudosity\Data\SmsSentItemData;
use ExpertSystems\Kudosity\Data\SmsStatsData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/DtoTest.php. Renamed to
 * V1DtoTest because the client suite's DtoTest.php already covers
 * Data\V2\* exclusively — zero class overlap with the V1 DTOs tested here.
 */
#[CoversClass(BalanceData::class)]
#[CoversClass(BulkProgressData::class)]
#[CoversClass(ContactData::class)]
#[CoversClass(ContactSmsStatsData::class)]
#[CoversClass(ListData::class)]
#[CoversClass(MessageReportData::class)]
#[CoversClass(SmsData::class)]
#[CoversClass(SmsListData::class)]
#[CoversClass(SmsSentItemData::class)]
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
        // The real key is 'total', not 'sent', and 'opt-outs' is hyphenated —
        // confirmed live 2026-08-07 (get-sms-stats.json):
        // {"stats":{"hard_bounced":0,"soft_bounced":0,"total":1,
        //  "recipientCount":1,"delivered":1,"pending":0,"bounced":0,
        //  "responses":0,"opt-outs":0,"link_hits":0}}
        // Before this fix, fromResponse() read $stats['sent'] and
        // $stats['optouts'] — both absent from the real shape — so $sent and
        // $optouts were always 0 regardless of activity.
        $dto = SmsStatsData::fromResponse([
            'stats' => [
                'hard_bounced' => 0,
                'soft_bounced' => 0,
                'total' => 100,
                'recipientCount' => 100,
                'delivered' => 95,
                'pending' => 3,
                'bounced' => 2,
                'responses' => 10,
                'opt-outs' => 1,
                'link_hits' => 0,
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
            'total' => 100,
            'delivered' => 80,
            'pending' => 10,
            'bounced' => 10,
            'responses' => 5,
            'opt-outs' => 0,
        ]);

        $this->assertSame(80.0, $dto->getDeliveryRate());
        $this->assertSame(10.0, $dto->getBounceRate());
        $this->assertSame(5.0, $dto->getResponseRate());
    }

    public function test_sms_stats_data_handles_zero_sent_for_rate_calculations(): void
    {
        $dto = SmsStatsData::fromResponse([
            'total' => 0,
            'delivered' => 0,
            'pending' => 0,
            'bounced' => 0,
            'responses' => 0,
            'opt-outs' => 0,
        ]);

        $this->assertSame(0.0, $dto->getDeliveryRate());
        $this->assertSame(0.0, $dto->getBounceRate());
    }

    // -----------------------------------------------------------------
    // SmsSentItemData
    // -----------------------------------------------------------------

    /**
     * Live get-message-report.json (2026-08-07) returns items keyed
     * id/msisdn/sent_at, not message_id/mobile/send_at:
     * {"type":"api","id":1718653641,"sms":1,"cost":-0.027,
     *  "sent_at":"2026-08-07 18:36:00","status":"user cancelled",...,
     *  "msisdn":61447514584}
     * Before this fix, fromResponse() read the absent message_id/mobile/
     * send_at keys — send_at is a non-nullable string constructor param, so
     * the missing key was a fatal TypeError, not a caught KudosityException.
     */
    public function test_sms_sent_item_data_creates_from_message_report_row(): void
    {
        $dto = SmsSentItemData::fromResponse([
            'type' => 'api',
            'id' => 1718653641,
            'sms' => 1,
            'cost' => -0.027,
            'sent_at' => '2026-08-07 18:36:00',
            'status' => 'user cancelled',
            'message' => 'DateTimeInterface scheduling check, cancelled immediately.',
            'pending' => 0,
            'delivered' => 0,
            'msisdn' => 61447514584,
        ]);

        $this->assertSame(1718653641, $dto->messageId);
        $this->assertSame('61447514584', $dto->mobile);
        $this->assertSame('2026-08-07 18:36:00', $dto->sendAt);
        $this->assertSame('user cancelled', $dto->status);
    }

    // -----------------------------------------------------------------
    // BulkProgressData
    // -----------------------------------------------------------------

    /**
     * The vendored kudosity-contacts-lists skill documents the real
     * add-contacts-bulk-progress response:
     * {"list_id":4214121,"status":"completed","importlength":2,
     *  "completed":2,"duplicates":0,"skipped":0,"optout":0,"imported":2}
     * Before this fix, total/processed read the absent total/processed keys
     * (always 0), isComplete() compared against 'complete' (API says
     * 'completed'), and isProcessing() compared against 'processing' (API
     * says 'in progress') — so both predicates were always false and
     * getProgressPercent() always divided by zero.
     */
    public function test_bulk_progress_data_creates_from_real_response_shape(): void
    {
        $dto = BulkProgressData::fromResponse([
            'list_id' => 4214121,
            'status' => 'completed',
            'importlength' => 2,
            'completed' => 2,
            'duplicates' => 0,
            'skipped' => 0,
            'optout' => 0,
            'imported' => 2,
        ]);

        $this->assertSame(4214121, $dto->listId);
        $this->assertSame(2, $dto->total);
        $this->assertSame(2, $dto->processed);
        $this->assertTrue($dto->isComplete());
        $this->assertFalse($dto->isProcessing());
        $this->assertSame(100.0, $dto->getProgressPercent());
    }

    public function test_bulk_progress_data_reports_in_progress_status(): void
    {
        $dto = BulkProgressData::fromResponse([
            'list_id' => 4214121,
            'status' => 'in progress',
            'importlength' => 10,
            'completed' => 4,
        ]);

        $this->assertFalse($dto->isComplete());
        $this->assertTrue($dto->isProcessing());
        $this->assertSame(40.0, $dto->getProgressPercent());
    }

    // -----------------------------------------------------------------
    // ContactSmsStatsData
    // -----------------------------------------------------------------

    /**
     * Live get-contact-sms-stats.json (2026-08-07, confirmed again live
     * 2026-08-10) does not return the {mobile, stats:{sent,delivered,...}}
     * shape this DTO models at all — it returns a paginated list of
     * per-message delivery receipts:
     * {"page":{"count":3,"number":1},"total":27,
     *  "records":[{"message_id":1528493890,"datetime_send":"2025-12-05
     *  15:24:57","delivery_status":"delivered"}, ...],
     *  "error":{"code":"SUCCESS","description":"OK"}}
     * Before this fix, every field silently defaulted (mobile="", every
     * count 0) regardless of real account history — confirmed live against
     * a contact with 27 real delivered messages on record. Silent wrong
     * data is worse than an error: a consumer reads "no activity" for a
     * contact who plainly has some. This is not a one-word rename like the
     * other DTOs in this class — correctly representing records[] needs
     * aggregation logic this DTO does not have (2.1.0 work) — so the patch
     * fix is to fail loudly on the shape it cannot represent instead of
     * lying about it.
     */
    public function test_contact_sms_stats_data_throws_on_the_real_paginated_response_shape(): void
    {
        $this->expectException(KudosityException::class);
        // The message must be self-sufficient for a caller who has never
        // read this DTO's source: what the endpoint actually returns, why
        // this DTO can't represent it, and where aggregate stats are headed.
        $this->expectExceptionMessageMatches('/paginated.*per-message record/i');
        $this->expectExceptionMessageMatches('/ContactSmsStatsData/');
        $this->expectExceptionMessageMatches('/2\.1\.0/');

        ContactSmsStatsData::fromResponse([
            'page' => ['count' => 3, 'number' => 1],
            'total' => 27,
            'records' => [
                ['message_id' => 1528493890, 'datetime_send' => '2025-12-05 15:24:57', 'delivery_status' => 'delivered'],
            ],
            'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
        ]);
    }

    /**
     * A 'records' key alone (no 'page'/'total' alongside it) is not enough
     * to conclude this is the real shape — require all three, matching the
     * live signature exactly, so a hypothetical future response that merely
     * happens to carry an unrelated 'records' field is not misclassified.
     */
    public function test_contact_sms_stats_data_requires_the_full_paginated_signature_to_throw(): void
    {
        $dto = ContactSmsStatsData::fromResponse([
            'mobile' => '61491570006',
            'records' => 'unrelated field, not the paginated shape',
            'stats' => ['sent' => 5, 'delivered' => 5, 'pending' => 0, 'bounced' => 0, 'responses' => 0, 'optouts' => 0],
        ]);

        $this->assertSame(5, $dto->sent);
    }

    // -----------------------------------------------------------------
    // MessageReportData
    // -----------------------------------------------------------------

    /**
     * Live get-message-report.json (2026-08-07/2026-08-10) has no
     * 'total_count' key — the real account-wide total is reported under
     * 'messages_total' (and duplicated under 'sms_total'):
     * {"page":{"count":2,"number":1},"messages_total":12,"sms_total":12,
     *  "messages":[...9 items on this page...]}
     * Before this fix, totalCount fell back to count($messages) — the
     * current PAGE's item count (9), not the account-wide total (12) —
     * silently substituting a page count for a total.
     */
    public function test_message_report_data_reads_the_real_total_key(): void
    {
        $dto = MessageReportData::fromResponse([
            'page' => ['count' => 2, 'number' => 1],
            'messages_total' => 12,
            'sms_total' => 12,
            'messages' => array_fill(0, 9, [
                'id' => 1, 'msisdn' => '61491570006', 'sent_at' => '2026-08-07 00:00:00', 'status' => 'completed',
            ]),
        ]);

        $this->assertSame(12, $dto->totalCount);
        $this->assertSame(2, $dto->pageCount);
        $this->assertSame(1, $dto->page);
        $this->assertCount(9, $dto->messages);
    }

    public function test_contact_sms_stats_data_still_parses_the_documented_shape(): void
    {
        $dto = ContactSmsStatsData::fromResponse([
            'mobile' => '61491570006',
            'stats' => [
                'sent' => 10,
                'delivered' => 9,
                'pending' => 0,
                'bounced' => 1,
                'responses' => 2,
                'optouts' => 0,
            ],
        ]);

        $this->assertSame('61491570006', $dto->mobile);
        $this->assertSame(10, $dto->sent);
        $this->assertSame(9, $dto->delivered);
    }
    // ---- 2.2.0: fields the API returns that these DTOs used to discard ----

    public function test_sms_stats_exposes_the_four_fields_it_used_to_drop(): void
    {
        // Verbatim live get-sms-stats.json body, captured 2026-08-10 against a
        // real message_id. Note recipientCount is camelCase while every sibling
        // key is snake_case — that asymmetry is the API's, not a typo here.
        $dto = SmsStatsData::fromResponse([
            'stats' => [
                'hard_bounced' => 3,
                'soft_bounced' => 2,
                'total' => 10,
                'recipientCount' => 7,
                'delivered' => 4,
                'pending' => 1,
                'bounced' => 5,
                'responses' => 0,
                'opt-outs' => 0,
                'link_hits' => 6,
            ],
        ]);

        $this->assertSame(3, $dto->hardBounced);
        $this->assertSame(2, $dto->softBounced);
        $this->assertSame(6, $dto->linkHits);
        $this->assertSame(7, $dto->recipientCount, 'recipientCount is distinct from sent, which counts SMS parts');
        $this->assertSame(10, $dto->sent);
    }

    public function test_sms_stats_defaults_the_new_fields_to_zero_when_absent(): void
    {
        $dto = SmsStatsData::fromResponse(['stats' => ['total' => 1, 'delivered' => 1]]);

        $this->assertSame(0, $dto->hardBounced);
        $this->assertSame(0, $dto->softBounced);
        $this->assertSame(0, $dto->linkHits);
        $this->assertSame(0, $dto->recipientCount);
    }

    public function test_bulk_progress_exposes_the_four_fields_it_used_to_drop(): void
    {
        // Verbatim live add-contacts-bulk-progress.json, captured 2026-08-10
        // from a deliberately mixed 2-row import (one valid, one invalid) so
        // importlength, imported and skipped could not be confused with each
        // other by all being equal.
        $dto = BulkProgressData::fromResponse([
            'list_id' => 11205319,
            'status' => 'completed',
            'importlength' => 2,
            'completed' => 2,
            'duplicates' => 0,
            'skipped' => 1,
            'optout' => 0,
            'imported' => 1,
        ]);

        $this->assertSame(2, $dto->total, 'importlength counts every row, including invalid ones');
        $this->assertSame(2, $dto->processed);
        $this->assertSame(1, $dto->imported, 'only the valid row was added');
        $this->assertSame(1, $dto->skipped, 'the invalid row was skipped');
        $this->assertSame(0, $dto->duplicates);
        $this->assertSame(0, $dto->optout);
        $this->assertNotSame($dto->total, $dto->imported, 'total and imported diverge whenever a row fails');
    }

    public function test_bulk_progress_defaults_the_new_fields_to_zero_when_absent(): void
    {
        $dto = BulkProgressData::fromResponse(['list_id' => 1, 'status' => 'in progress']);

        $this->assertSame(0, $dto->imported);
        $this->assertSame(0, $dto->duplicates);
        $this->assertSame(0, $dto->skipped);
        $this->assertSame(0, $dto->optout);
    }
}
