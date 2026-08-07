<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/V2PaginationTest.php.
 *
 * Covers paginator dispatch from the request's marker interfaces, the
 * KudosityException raised for a request declaring no scheme, page starting
 * at 1, the limit/direction query parameters, and a missing meta.pagination
 * entirely — none of which the client suite's own PaginatorTest.php (which
 * drives termination behaviour only, via ListSmsV2Request/ListWhatsAppRequest)
 * exercises.
 *
 * StubListSmsRequest, StubListRcsRequest and StubUnpaginatedRequest are local
 * to this file only, exactly as they were in the root spec.
 */
#[CoversClass(V2PagedPaginator::class)]
#[CoversClass(V2CursorPaginator::class)]
#[CoversClass(KudosityV2Connector::class)]
final class V2PaginationTest extends TestCase
{
    /**
     * @param  array<class-string, MockResponse>  $responses
     */
    private static function pagedConnector(array $responses): KudosityV2Connector
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return $connector;
    }

    /**
     * `total_records` is a string in real responses — that is the point.
     *
     * Named `v2SmsPage` (not `smsPage`) to avoid colliding with the V1
     * `smsPage()` helper already declared by V1PaginationTest.
     *
     * @param  array<int, string>  $ids
     */
    private static function v2SmsPage(array $ids, string $totalRecords): MockResponse
    {
        return MockResponse::make([
            'smses' => array_map(static fn (string $id): array => ['id' => $id], $ids),
            'total_records' => $totalRecords,
            'total_segments' => $totalRecords,
        ], 200);
    }

    /**
     * @param  array<int, string>  $ids
     */
    private static function rcsPage(array $ids, bool $hasNext, ?string $nextCursor): MockResponse
    {
        return MockResponse::make([
            'data' => ['messages' => array_map(static fn (string $id): array => ['id' => $id], $ids)],
            'meta' => ['pagination' => [
                'type' => 'cursor',
                'next_cursor' => $nextCursor,
                'prev_cursor' => null,
                'has_next' => $hasNext,
                'has_prev' => false,
            ]],
        ], 200);
    }

    public function test_dispatches_to_the_right_paginator_from_the_request_markers(): void
    {
        $connector = self::pagedConnector([StubListSmsRequest::class => self::v2SmsPage(['a'], '1')]);

        $this->assertInstanceOf(V2PagedPaginator::class, $connector->paginate(new StubListSmsRequest));
        $this->assertInstanceOf(V2CursorPaginator::class, $connector->paginate(new StubListRcsRequest));
    }

    public function test_refuses_to_paginate_a_request_that_declares_no_scheme(): void
    {
        $this->expectException(KudosityException::class);

        self::pagedConnector([])->paginate(new StubUnpaginatedRequest);
    }

    public function test_sends_page_and_limit_and_walks_pages_until_total_records_is_exhausted(): void
    {
        $connector = self::pagedConnector([
            StubListSmsRequest::class => MockResponse::make(['smses' => [['id' => 'a'], ['id' => 'b']], 'total_records' => '3'], 200),
        ]);

        $paginator = $connector->paginate(new StubListSmsRequest);
        $paginator->setPerPageLimit(2);

        $ids = [];
        foreach ($paginator->items() as $item) {
            $ids[] = $item['id'];
            if (count($ids) >= 4) {
                break; // guard against a runaway paginator failing the suite slowly
            }
        }

        $this->assertSame(['a', 'b', 'a', 'b'], $ids);
    }

    public function test_starts_at_page_1_not_page_0(): void
    {
        $mock = new MockClient([StubListSmsRequest::class => self::v2SmsPage(['a'], '1')]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        iterator_to_array($connector->paginate(new StubListSmsRequest)->items());

        $this->assertSame(1, $mock->getLastPendingRequest()->query()->get('page'));
    }

    public function test_stops_on_an_empty_page(): void
    {
        $connector = self::pagedConnector([
            StubListSmsRequest::class => MockResponse::make(['smses' => [], 'total_records' => '0'], 200),
        ]);

        $this->assertSame([], iterator_to_array($connector->paginate(new StubListSmsRequest)->items()));
    }

    public function test_stops_on_a_short_page_when_total_records_is_absent(): void
    {
        $connector = self::pagedConnector([
            StubListSmsRequest::class => MockResponse::make(['smses' => [['id' => 'a']]], 200),
        ]);

        $paginator = $connector->paginate(new StubListSmsRequest);
        $paginator->setPerPageLimit(10);

        $this->assertCount(1, iterator_to_array($paginator->items()));
    }

    public function test_does_arithmetic_on_the_string_total_records_without_concatenating_it(): void
    {
        // total_records arrives as "10", not 10. ceil(10/5) is 2 pages; a
        // version that treated the total as a string would not land on 2.
        $connector = self::pagedConnector([
            StubListSmsRequest::class => self::v2SmsPage(['a', 'b', 'c', 'd', 'e'], '10'),
        ]);

        $paginator = $connector->paginate(new StubListSmsRequest);
        $paginator->setPerPageLimit(5);

        $this->assertCount(10, iterator_to_array($paginator->items()));
    }

    public function test_omits_the_cursor_on_the_first_request_and_sends_limit_and_direction(): void
    {
        $mock = new MockClient([StubListRcsRequest::class => self::rcsPage(['a'], false, null)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $paginator = $connector->paginate(new StubListRcsRequest);
        $paginator->setPerPageLimit(25);
        iterator_to_array($paginator->items());

        $query = $mock->getLastPendingRequest()->query();

        $this->assertNull($query->get('cursor'));
        $this->assertSame(25, $query->get('limit'));
        $this->assertSame('next', $query->get('direction'));
    }

    public function test_follows_the_returned_cursor_onto_the_next_request_and_stops_when_has_next_goes_false(): void
    {
        $mock = new MockClient([
            self::rcsPage(['a', 'b'], true, 'cursor-2'),
            self::rcsPage(['c'], false, null),
        ]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $paginator = $connector->paginate(new StubListRcsRequest);
        $paginator->setPerPageLimit(25);

        $ids = array_column(iterator_to_array($paginator->items()), 'id');
        $this->assertSame(['a', 'b', 'c'], $ids);

        $recorded = $mock->getRecordedResponses();
        $this->assertCount(2, $recorded);
        $this->assertNull($recorded[0]->getPendingRequest()->query()->get('cursor'));
        $this->assertSame('cursor-2', $recorded[1]->getPendingRequest()->query()->get('cursor'));
    }

    public function test_reads_items_from_the_nested_data_messages_path(): void
    {
        $connector = self::pagedConnector([StubListRcsRequest::class => self::rcsPage(['a', 'b'], false, null)]);

        $this->assertCount(2, iterator_to_array($connector->paginate(new StubListRcsRequest)->items()));
    }

    public function test_stops_when_has_next_is_false(): void
    {
        $connector = self::pagedConnector([StubListRcsRequest::class => self::rcsPage(['a'], false, 'ignored')]);

        $this->assertCount(1, iterator_to_array($connector->paginate(new StubListRcsRequest)->items()));
    }

    public function test_stops_when_has_next_is_true_but_no_cursor_came_back(): void
    {
        $connector = self::pagedConnector([StubListRcsRequest::class => self::rcsPage(['a'], true, null)]);

        $this->assertCount(1, iterator_to_array($connector->paginate(new StubListRcsRequest)->items()));
    }

    public function test_stops_when_meta_pagination_is_missing_entirely(): void
    {
        $connector = self::pagedConnector([
            StubListRcsRequest::class => MockResponse::make(['data' => ['messages' => [['id' => 'a']]]], 200),
        ]);

        $this->assertCount(1, iterator_to_array($connector->paginate(new StubListRcsRequest)->items()));
    }
}

class StubListSmsRequest extends KudosityV2Request implements PaginatesV2Pages
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/sms';
    }

    public function paginationItemsKey(): string
    {
        return 'smses';
    }
}

class StubListRcsRequest extends KudosityV2Request implements PaginatesV2Cursor
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/rcs/messages';
    }

    public function paginationItemsKey(): string
    {
        return 'data.messages';
    }

    public function paginationDirection(): string
    {
        return 'next';
    }
}

class StubUnpaginatedRequest extends KudosityV2Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v2/sms/abc';
    }
}
