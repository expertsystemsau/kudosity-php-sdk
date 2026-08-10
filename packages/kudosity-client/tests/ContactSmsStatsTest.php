<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Data\ContactSmsRecordData;
use ExpertSystems\Kudosity\Data\ContactSmsSummaryData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Requests\GetContactSmsStatsRequest;
use ExpertSystems\Kudosity\Resources\ReportingResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Payloads below are the real live response from get-contact-sms-stats.json,
 * captured 2026-08-10. Field names are not guessed.
 */
#[CoversClass(ContactSmsRecordData::class)]
#[CoversClass(ContactSmsSummaryData::class)]
#[CoversClass(GetContactSmsStatsRequest::class)]
final class ContactSmsStatsTest extends TestCase
{
    private function connector(MockClient $mock): KudosityV1Connector
    {
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient($mock);

        return $connector;
    }

    /** One page, captured shape verbatim. */
    private static function page(int $number, int $count, array $records): MockResponse
    {
        return MockResponse::make([
            'page' => ['count' => $count, 'number' => $number],
            'total' => 32,
            'records' => $records,
            'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
        ], 200);
    }

    public function test_it_reads_the_three_fields_a_record_actually_has(): void
    {
        $record = ContactSmsRecordData::fromResponse([
            'message_id' => 1528493890,
            'datetime_send' => '2025-12-05 15:24:57',
            'delivery_status' => 'delivered',
        ]);

        $this->assertSame(1528493890, $record->messageId);
        $this->assertSame('delivered', $record->deliveryStatus);
        $this->assertNotNull($record->sentAt);
        $this->assertSame('2025-12-05 15:24:57', $record->sentAt->format('Y-m-d H:i:s'));
    }

    public function test_it_keeps_an_unrecognised_status_verbatim_rather_than_dropping_it(): void
    {
        // This endpoint's status vocabulary is undocumented upstream, so a
        // value the enum does not model must survive as a string.
        $record = ContactSmsRecordData::fromResponse([
            'message_id' => 1,
            'datetime_send' => '2025-12-05 15:24:57',
            'delivery_status' => 'some-status-nobody-documented',
        ]);

        $this->assertSame('some-status-nobody-documented', $record->deliveryStatus);
        $this->assertSame(
            MessageStatus::Unknown,
            $record->status(),
            'an unmodelled status becomes Unknown, never a wrong case — and the raw string survives beside it'
        );
    }

    public function test_it_returns_null_for_an_unparseable_send_time_instead_of_a_wrong_instant(): void
    {
        $record = ContactSmsRecordData::fromResponse([
            'message_id' => 1,
            'datetime_send' => 'not a date',
            'delivery_status' => 'delivered',
        ]);

        $this->assertNull($record->sentAt);
    }

    public function test_it_tallies_statuses_across_every_page(): void
    {
        $mock = new MockClient([
            self::page(1, 2, [
                ['message_id' => 1, 'datetime_send' => '2025-12-05 15:24:57', 'delivery_status' => 'delivered'],
                ['message_id' => 2, 'datetime_send' => '2025-12-06 15:24:57', 'delivery_status' => 'delivered'],
            ]),
            self::page(2, 2, [
                ['message_id' => 3, 'datetime_send' => '2025-12-07 15:24:57', 'delivery_status' => 'bounced'],
            ]),
        ]);

        $summary = (new ReportingResource($this->connector($mock)))->getContactStats('61400000000');

        $this->assertSame(3, $summary->total, 'both pages counted, not just the first');
        $this->assertSame(2, $summary->delivered());
        $this->assertSame(1, $summary->bounced());
        $this->assertTrue($summary->complete);
        $this->assertEqualsWithDelta(66.667, $summary->getDeliveryRate(), 0.001);
    }

    public function test_it_flags_an_incomplete_summary_when_the_record_cap_stops_it_early(): void
    {
        $mock = new MockClient([
            self::page(1, 2, [
                ['message_id' => 1, 'datetime_send' => '2025-12-05 15:24:57', 'delivery_status' => 'delivered'],
                ['message_id' => 2, 'datetime_send' => '2025-12-06 15:24:57', 'delivery_status' => 'delivered'],
            ]),
            self::page(2, 2, [
                ['message_id' => 3, 'datetime_send' => '2025-12-07 15:24:57', 'delivery_status' => 'bounced'],
            ]),
        ]);

        $summary = (new ReportingResource($this->connector($mock)))
            ->getContactStats('61400000000', null, maxRecords: 1);

        $this->assertSame(1, $summary->total);
        $this->assertFalse(
            $summary->complete,
            'a capped summary must not claim to be a total — a caller cannot otherwise tell'
        );
    }

    public function test_it_counts_a_status_the_enum_does_not_model(): void
    {
        $mock = new MockClient([
            self::page(1, 1, [
                ['message_id' => 1, 'datetime_send' => '2025-12-05 15:24:57', 'delivery_status' => 'mystery'],
            ]),
        ]);

        $summary = (new ReportingResource($this->connector($mock)))->getContactStats('61400000000');

        $this->assertSame(1, $summary->total);
        $this->assertSame(1, $summary->countOf('mystery'), 'unknown statuses are counted, not discarded');
        $this->assertSame(0, $summary->delivered());
    }

    public function test_the_request_declares_records_as_its_items_key(): void
    {
        // 'stats' would be the intuitive guess and is wrong; the endpoint
        // returns {page, total, records[]} despite its name.
        $this->assertSame('records', (new GetContactSmsStatsRequest('61400000000'))->paginationItemsKey());
    }

    public function test_summary_reports_zero_rate_rather_than_dividing_by_zero(): void
    {
        $summary = new ContactSmsSummaryData('61400000000', 0, []);

        $this->assertSame(0.0, $summary->getDeliveryRate());
        $this->assertSame(0, $summary->countOf('delivered'));
    }
}
