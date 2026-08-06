<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use ExpertSystems\Kudosity\Requests\GetKeywordsRequest;
use ExpertSystems\Kudosity\Requests\GetListRequest;
use ExpertSystems\Kudosity\Requests\GetListsRequest;
use ExpertSystems\Kudosity\Requests\GetNumbersRequest;
use ExpertSystems\Kudosity\Requests\GetSmsResponsesRequest;
use ExpertSystems\Kudosity\Requests\GetSmsSentRequest;
use ExpertSystems\Kudosity\Requests\GetUserSmsResponsesRequest;
use ExpertSystems\Kudosity\Requests\GetUserSmsSentRequest;
use ExpertSystems\Kudosity\Resources\KeywordsResource;
use ExpertSystems\Kudosity\Resources\ListsResource;
use ExpertSystems\Kudosity\Resources\NumbersResource;
use ExpertSystems\Kudosity\Resources\ReportingResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/PaginationTest.php. Renamed to
 * V1PaginationTest because the client suite's PaginatorTest.php (Task 5)
 * already covers all three paginators' termination behaviour, driven only
 * through GetNumbersRequest/numbers.
 *
 * This is the regression suite for a real 1.9.0 bug: V1PagedPaginator
 * hardcoded the `responses` envelope key, so every endpoint reporting its
 * items under a different key iterated to zero — silently, because zero
 * results is a legal answer. Each case below drives a real resource method
 * (not the paginator in isolation) so a class that stops declaring its
 * correct paginationItemsKey() fails here, exactly as it did in production.
 */
#[CoversClass(V1PagedPaginator::class)]
#[CoversClass(KudosityClient::class)]
#[CoversClass(NumbersResource::class)]
#[CoversClass(ListsResource::class)]
#[CoversClass(KeywordsResource::class)]
#[CoversClass(ReportingResource::class)]
final class V1PaginationTest extends TestCase
{
    /**
     * Build a single-page response envelope for a given items key.
     *
     * @param  array<int, mixed>  $items
     * @return array<string, mixed>
     */
    private static function smsPage(string $key, array $items): array
    {
        return [
            'page' => ['number' => 1, 'count' => 1],
            $key => $items,
            'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
        ];
    }

    /**
     * @param  array<class-string, MockResponse>  $responses
     */
    private static function mockedSmsClient(array $responses): KudosityClient
    {
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient(new MockClient($responses));

        return KudosityClient::fromConnector($connector);
    }

    public function test_reads_numbers_from_the_numbers_key(): void
    {
        $client = self::mockedSmsClient([
            GetNumbersRequest::class => MockResponse::make(self::smsPage('numbers', [
                ['number' => '+61400000001'],
                ['number' => '+61400000002'],
            ])),
        ]);

        $items = iterator_to_array($client->numbers()->all()->items(), false);

        $this->assertCount(2, $items);
        $this->assertSame('+61400000001', $items[0]['number']);
    }

    public function test_reads_lists_from_the_lists_key(): void
    {
        $client = self::mockedSmsClient([
            GetListsRequest::class => MockResponse::make(self::smsPage('lists', [
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
            ])),
        ]);

        $items = iterator_to_array($client->lists()->all()->items(), false);

        $this->assertCount(2, $items);
        $this->assertSame('B', $items[1]['name']);
    }

    public function test_reads_keywords_from_the_keywords_key(): void
    {
        $client = self::mockedSmsClient([
            GetKeywordsRequest::class => MockResponse::make(self::smsPage('keywords', [
                ['keyword' => 'STOP'],
            ])),
        ]);

        $items = iterator_to_array($client->keywords()->all()->items(), false);

        $this->assertCount(1, $items);
        $this->assertSame('STOP', $items[0]['keyword']);
    }

    public function test_reads_message_recipients_from_the_recipients_key(): void
    {
        $client = self::mockedSmsClient([
            GetSmsSentRequest::class => MockResponse::make(self::smsPage('recipients', [
                ['msisdn' => '+61400000001', 'delivery_status' => 'delivered'],
                ['msisdn' => '+61400000002', 'delivery_status' => 'pending'],
            ])),
        ]);

        $items = iterator_to_array($client->reporting()->getSent(49606)->items(), false);

        $this->assertCount(2, $items);
        $this->assertSame('delivered', $items[0]['delivery_status']);
    }

    public function test_reads_user_sent_messages_from_the_messages_key(): void
    {
        $client = self::mockedSmsClient([
            GetUserSmsSentRequest::class => MockResponse::make(self::smsPage('messages', [
                ['id' => 10],
                ['id' => 11],
            ])),
        ]);

        $items = iterator_to_array($client->reporting()->getUserSent()->items(), false);

        $this->assertCount(2, $items);
        $this->assertSame(11, $items[1]['id']);
    }

    public function test_reads_list_members_from_the_members_key_get_list(): void
    {
        $client = self::mockedSmsClient([
            GetListRequest::class => MockResponse::make(self::smsPage('members', [
                ['msisdn' => '+61400000001', 'first_name' => 'Ada'],
            ])),
        ]);

        // Regression: getContacts() paginated a non-Paginatable request and
        // threw InvalidArgumentException before GetListRequest was made
        // paginatable.
        $items = iterator_to_array($client->lists()->getContacts(6191)->items(), false);

        $this->assertCount(1, $items);
        $this->assertSame('Ada', $items[0]['first_name']);
    }

    public function test_reads_message_responses_from_the_responses_key(): void
    {
        $client = self::mockedSmsClient([
            GetSmsResponsesRequest::class => MockResponse::make(self::smsPage('responses', [
                ['response' => 'YES'],
            ])),
        ]);

        $items = iterator_to_array($client->reporting()->getResponses(49484)->items(), false);

        $this->assertCount(1, $items);
        $this->assertSame('YES', $items[0]['response']);
    }

    public function test_reads_account_responses_from_the_responses_key(): void
    {
        $client = self::mockedSmsClient([
            GetUserSmsResponsesRequest::class => MockResponse::make(self::smsPage('responses', [
                ['response' => 'STOP'],
                ['response' => 'INFO'],
            ])),
        ]);

        $items = iterator_to_array($client->reporting()->getAllResponses()->items(), false);

        $this->assertCount(2, $items);
        $this->assertSame('STOP', $items[0]['response']);
    }
}
