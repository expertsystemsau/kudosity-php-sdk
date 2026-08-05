<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Pagination\V2CursorPaginator;
use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;
use ExpertSystems\Kudosity\Requests\KudosityV2Request;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

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

function pagedConnector(array $responses): KudosityV2Connector
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return $connector;
}

/**
 * `total_records` is a string in real responses — that is the point.
 *
 * Named `v2SmsPage` (not `smsPage`) to avoid colliding with the V1
 * `smsPage()` helper already declared globally by PaginationTest.php.
 */
function v2SmsPage(array $ids, string $totalRecords): MockResponse
{
    return MockResponse::make([
        'smses' => array_map(static fn (string $id): array => ['id' => $id], $ids),
        'total_records' => $totalRecords,
        'total_segments' => $totalRecords,
    ], 200);
}

function rcsPage(array $ids, bool $hasNext, ?string $nextCursor): MockResponse
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

it('dispatches to the right paginator from the request markers', function () {
    $connector = pagedConnector([StubListSmsRequest::class => v2SmsPage(['a'], '1')]);

    expect($connector->paginate(new StubListSmsRequest))->toBeInstanceOf(V2PagedPaginator::class)
        ->and($connector->paginate(new StubListRcsRequest))->toBeInstanceOf(V2CursorPaginator::class);
});

it('refuses to paginate a request that declares no scheme', function () {
    pagedConnector([])->paginate(new StubUnpaginatedRequest);
})->throws(KudosityException::class);

it('sends page and limit, and walks pages until total_records is exhausted', function () {
    $connector = pagedConnector([
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

    expect($ids)->toBe(['a', 'b', 'a', 'b']);
});

it('starts at page 1, not page 0', function () {
    $mock = new MockClient([StubListSmsRequest::class => v2SmsPage(['a'], '1')]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    iterator_to_array($connector->paginate(new StubListSmsRequest)->items());

    expect($mock->getLastPendingRequest()->query()->get('page'))->toBe(1);
});

it('stops on an empty page', function () {
    $connector = pagedConnector([
        StubListSmsRequest::class => MockResponse::make(['smses' => [], 'total_records' => '0'], 200),
    ]);

    expect(iterator_to_array($connector->paginate(new StubListSmsRequest)->items()))->toBe([]);
});

it('stops on a short page when total_records is absent', function () {
    $connector = pagedConnector([
        StubListSmsRequest::class => MockResponse::make(['smses' => [['id' => 'a']]], 200),
    ]);

    $paginator = $connector->paginate(new StubListSmsRequest);
    $paginator->setPerPageLimit(10);

    expect(iterator_to_array($paginator->items()))->toHaveCount(1);
});

it('does arithmetic on the string total_records without concatenating it', function () {
    // total_records arrives as "10", not 10. ceil(10/5) is 2 pages; a version
    // that treated the total as a string would not land on 2.
    $connector = pagedConnector([
        StubListSmsRequest::class => v2SmsPage(['a', 'b', 'c', 'd', 'e'], '10'),
    ]);

    $paginator = $connector->paginate(new StubListSmsRequest);
    $paginator->setPerPageLimit(5);

    expect(iterator_to_array($paginator->items()))->toHaveCount(10);
});

it('omits the cursor on the first request and sends limit and direction', function () {
    $mock = new MockClient([StubListRcsRequest::class => rcsPage(['a'], false, null)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $paginator = $connector->paginate(new StubListRcsRequest);
    $paginator->setPerPageLimit(25);
    iterator_to_array($paginator->items());

    $query = $mock->getLastPendingRequest()->query();

    expect($query->get('cursor'))->toBeNull()
        ->and($query->get('limit'))->toBe(25)
        ->and($query->get('direction'))->toBe('next');
});

it('follows the returned cursor onto the next request and stops when has_next goes false', function () {
    $mock = new MockClient([
        rcsPage(['a', 'b'], true, 'cursor-2'),
        rcsPage(['c'], false, null),
    ]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $paginator = $connector->paginate(new StubListRcsRequest);
    $paginator->setPerPageLimit(25);

    $ids = array_column(iterator_to_array($paginator->items()), 'id');
    expect($ids)->toBe(['a', 'b', 'c']);

    $recorded = $mock->getRecordedResponses();
    expect($recorded)->toHaveCount(2)
        ->and($recorded[0]->getPendingRequest()->query()->get('cursor'))->toBeNull()
        ->and($recorded[1]->getPendingRequest()->query()->get('cursor'))->toBe('cursor-2');
});

it('reads items from the nested data.messages path', function () {
    $connector = pagedConnector([StubListRcsRequest::class => rcsPage(['a', 'b'], false, null)]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))
        ->toHaveCount(2);
});

it('stops when has_next is false', function () {
    $connector = pagedConnector([StubListRcsRequest::class => rcsPage(['a'], false, 'ignored')]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))->toHaveCount(1);
});

it('stops when has_next is true but no cursor came back', function () {
    $connector = pagedConnector([StubListRcsRequest::class => rcsPage(['a'], true, null)]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))->toHaveCount(1);
});

it('stops when meta.pagination is missing entirely', function () {
    $connector = pagedConnector([
        StubListRcsRequest::class => MockResponse::make(['data' => ['messages' => [['id' => 'a']]]], 200),
    ]);

    expect(iterator_to_array($connector->paginate(new StubListRcsRequest)->items()))->toHaveCount(1);
});
