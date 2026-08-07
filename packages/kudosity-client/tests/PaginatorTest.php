<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;
use ExpertSystems\Kudosity\Requests\GetNumbersRequest;
use ExpertSystems\Kudosity\Requests\V2\ListSenderRegistrationsRequest;
use ExpertSystems\Kudosity\Requests\V2\ListSmsV2Request;
use ExpertSystems\Kudosity\Requests\V2\ListWhatsAppRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Three paginators, three schemes.
 *
 * 1.9.0 shipped a bug where {@see V1PagedPaginator} hardcoded the `responses`
 * envelope key, so every endpoint reporting its items under a different key
 * iterated to zero — silently, because zero results is a legal answer.
 * Termination and a short final page are the two cases that hide that class
 * of bug, so every paginator here is exercised against both, plus an empty
 * page and (for the page-based schemes) the total field itself.
 */
#[CoversClass(V1PagedPaginator::class)]
#[CoversClass(V2PagedPaginator::class)]
#[CoversClass(V2CursorPaginator::class)]
final class PaginatorTest extends TestCase
{
    // -----------------------------------------------------------------
    // V2PagedPaginator (GET /v2/sms, GET /v2/senders/registrations)
    // -----------------------------------------------------------------

    public function test_a_paged_list_stops_at_the_last_page(): void
    {
        // Termination is the whole risk: an off-by-one either drops the last page
        // or loops forever against a live API.
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient([
            MockResponse::make(['smses' => [['id' => '1'], ['id' => '2']],
                'meta' => ['pagination' => ['page' => 1, 'limit' => 2, 'total' => 3]]], 200),
            MockResponse::make(['smses' => [['id' => '3']],
                'meta' => ['pagination' => ['page' => 2, 'limit' => 2, 'total' => 3]]], 200),
        ]));

        $seen = [];
        foreach ($connector->paginate(new ListSmsV2Request)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertCount(3, $seen, 'A short final page must still be yielded.');
    }

    public function test_the_paged_paginator_reads_a_total_from_either_documented_key(): void
    {
        // GET /v2/sms reports its total as `total_records` — a string — at the
        // top level. The per-page limit is forced down to equal the page size
        // so a full (non-short) page cannot be mistaken for the last one by
        // the no-total fallback: only reading total_records stops this after
        // one request, which is also the only mock response supplied.
        $sms = new KudosityV2Connector('key');
        $sms->withMockClient(new MockClient([
            MockResponse::make([
                'smses' => array_fill(0, 5, ['id' => 'x']),
                'total_records' => '5',
            ], 200),
        ]));

        $seenSms = [];
        foreach ($sms->paginate(new ListSmsV2Request)->setPerPageLimit(5)->items() as $row) {
            $seenSms[] = $row;
        }

        $this->assertCount(5, $seenSms);

        // GET /v2/senders/registrations reports meta.pagination.total_count —
        // an int, in the place the cursor paginator looks — and defaults to 25
        // per page rather than this paginator's own 100. Both pages here are a
        // full 25/25 split of a 50-item total: reading total_count wrong (or
        // trusting the 100 default over the reported limit of 25) either
        // stops after page one (25, not 50) or asks for an unmocked third
        // page.
        $senders = new KudosityV2Connector('key');
        $senders->withMockClient(new MockClient([
            MockResponse::make([
                'data' => ['registrations' => array_fill(0, 25, ['id' => 'r'])],
                'meta' => ['pagination' => ['limit' => 25, 'page' => 1, 'total_count' => 50, 'type' => 'page']],
            ], 200),
            MockResponse::make([
                'data' => ['registrations' => array_fill(0, 25, ['id' => 'r'])],
                'meta' => ['pagination' => ['limit' => 25, 'page' => 2, 'total_count' => 50, 'type' => 'page']],
            ], 200),
        ]));

        $seenRegistrations = [];
        foreach ($senders->paginate(new ListSenderRegistrationsRequest)->items() as $row) {
            $seenRegistrations[] = $row;
        }

        $this->assertCount(50, $seenRegistrations);
    }

    public function test_an_empty_page_terminates_rather_than_looping(): void
    {
        // GET /v2/webhook returns {} when empty, omitting the collection key
        // entirely rather than sending an empty array. That endpoint is not
        // itself paginated, but any V2 list response can be truncated the
        // same way. `total_records` here implies several more pages at the
        // default limit of 100 — if the explicit "items are empty" check in
        // isLastPage() were ever dropped, the total/limit arithmetic alone
        // would say there is more to fetch, and the single mock response
        // supplied means that attempt fails the test outright rather than
        // this passing by coincidence of the fallback also saying "done".
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient([
            MockResponse::make(['total_records' => '1000'], 200),
        ]));

        $seen = [];
        foreach ($connector->paginate(new ListSmsV2Request)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertSame([], $seen);
    }

    // -----------------------------------------------------------------
    // V1PagedPaginator (get-numbers.json and friends)
    // -----------------------------------------------------------------

    public function test_v1_paginator_resolves_the_per_endpoint_items_key(): void
    {
        // The 1.9.0 regression: this class's default items key is `responses`,
        // but GetNumbersRequest declares `numbers`. If the declared key is not
        // honoured, resolveItemsKey() falls back to `responses`, which is
        // absent here, and every item below is silently dropped.
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient(new MockClient([
            MockResponse::make([
                'numbers' => [['number' => '61491570015'], ['number' => '61491570016']],
                'page' => ['number' => 1, 'count' => 1],
            ], 200),
        ]));

        $seen = [];
        foreach ($connector->paginate(new GetNumbersRequest)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertCount(2, $seen);
    }

    public function test_v1_paginator_stops_at_the_last_page(): void
    {
        // page.number reaching page.count is what ends the loop; a short
        // final page must still be yielded rather than dropped.
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient(new MockClient([
            MockResponse::make([
                'numbers' => [['number' => '1'], ['number' => '2']],
                'page' => ['number' => 1, 'count' => 2],
            ], 200),
            MockResponse::make([
                'numbers' => [['number' => '3']],
                'page' => ['number' => 2, 'count' => 2],
            ], 200),
        ]));

        $seen = [];
        foreach ($connector->paginate(new GetNumbersRequest)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertCount(3, $seen, 'A short final page must still be yielded.');
    }

    public function test_v1_paginator_terminates_on_an_empty_page_even_if_page_count_says_otherwise(): void
    {
        // isLastPage() checks for empty items before it ever looks at
        // page.count, so a truncated response cannot cause a second request
        // even when page.count claims there is more.
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient(new MockClient([
            MockResponse::make(['numbers' => [], 'page' => ['number' => 1, 'count' => 5]], 200),
        ]));

        $seen = [];
        foreach ($connector->paginate(new GetNumbersRequest)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertSame([], $seen);
    }

    // -----------------------------------------------------------------
    // V2CursorPaginator (GET /v2/whatsapp/messages, GET /v2/rcs/messages)
    // -----------------------------------------------------------------

    public function test_v2_cursor_paginator_walks_the_cursor_until_has_next_is_false(): void
    {
        $connector = new KudosityV2Connector('key');
        $mock = new MockClient([
            MockResponse::make([
                'data' => ['messages' => [['id' => '1'], ['id' => '2']]],
                'meta' => ['pagination' => ['has_next' => true, 'next_cursor' => 'abc']],
            ], 200),
            MockResponse::make([
                'data' => ['messages' => [['id' => '3']]],
                'meta' => ['pagination' => ['has_next' => false, 'next_cursor' => null]],
            ], 200),
        ]);
        $connector->withMockClient($mock);

        $seen = [];
        foreach ($connector->paginate(new ListWhatsAppRequest)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertCount(3, $seen, 'A short final page must still be yielded.');

        // The second request has to carry the cursor the first page reported,
        // or "walks the cursor" is just re-requesting page one.
        $lastRequest = $mock->getLastPendingRequest();
        $this->assertNotNull($lastRequest);
        $this->assertSame('abc', $lastRequest->query()->all()['cursor'] ?? null);
    }

    public function test_v2_cursor_paginator_terminates_when_has_next_is_true_but_the_cursor_is_missing(): void
    {
        // has_next promises more, but with no cursor to advance by a second
        // request would just replay page one forever — so this stops
        // instead. A single mock response means doing otherwise fails the
        // test rather than looping.
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient([
            MockResponse::make([
                'data' => ['messages' => [['id' => '1']]],
                'meta' => ['pagination' => ['has_next' => true, 'next_cursor' => null]],
            ], 200),
        ]));

        $seen = [];
        foreach ($connector->paginate(new ListWhatsAppRequest)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertCount(1, $seen);
    }

    public function test_v2_cursor_paginator_terminates_on_an_empty_page(): void
    {
        // Empty items end the loop before has_next is even consulted.
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient([
            MockResponse::make([
                'data' => ['messages' => []],
                'meta' => ['pagination' => ['has_next' => true, 'next_cursor' => 'abc']],
            ], 200),
        ]));

        $seen = [];
        foreach ($connector->paginate(new ListWhatsAppRequest)->items() as $row) {
            $seen[] = $row;
        }

        $this->assertSame([], $seen);
    }
}
