<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Requests\GetKeywordsRequest;
use ExpertSystems\Kudosity\Requests\GetListRequest;
use ExpertSystems\Kudosity\Requests\GetListsRequest;
use ExpertSystems\Kudosity\Requests\GetNumbersRequest;
use ExpertSystems\Kudosity\Requests\GetSmsResponsesRequest;
use ExpertSystems\Kudosity\Requests\GetSmsSentRequest;
use ExpertSystems\Kudosity\Requests\GetUserSmsResponsesRequest;
use ExpertSystems\Kudosity\Requests\GetUserSmsSentRequest;
use ExpertSystems\Kudosity\TransmitSmsClient;
use ExpertSystems\Kudosity\TransmitSmsConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Build a single-page response envelope for a given items key.
 *
 * @param  array<int, mixed>  $items
 * @return array<string, mixed>
 */
function smsPage(string $key, array $items): array
{
    return [
        'page' => ['number' => 1, 'count' => 1],
        $key => $items,
        'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
    ];
}

function mockedSmsClient(array $responses): TransmitSmsClient
{
    $connector = new TransmitSmsConnector('key', 'secret');
    $connector->withMockClient(new MockClient($responses));

    return TransmitSmsClient::fromConnector($connector);
}

describe('TransmitSmsPaginator item keys', function () {
    // The API uses a different envelope key per endpoint. Before this was
    // resolved per-request, every non-"responses" endpoint iterated to zero
    // items silently. Each case iterates a real page and asserts the items
    // come back through the correct key.
    it('reads numbers from the `numbers` key', function () {
        $client = mockedSmsClient([
            GetNumbersRequest::class => MockResponse::make(smsPage('numbers', [
                ['number' => '+61400000001'],
                ['number' => '+61400000002'],
            ])),
        ]);

        $items = iterator_to_array($client->numbers()->all()->items(), false);

        expect($items)->toHaveCount(2)
            ->and($items[0]['number'])->toBe('+61400000001');
    });

    it('reads lists from the `lists` key', function () {
        $client = mockedSmsClient([
            GetListsRequest::class => MockResponse::make(smsPage('lists', [
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
            ])),
        ]);

        $items = iterator_to_array($client->lists()->all()->items(), false);

        expect($items)->toHaveCount(2)->and($items[1]['name'])->toBe('B');
    });

    it('reads keywords from the `keywords` key', function () {
        $client = mockedSmsClient([
            GetKeywordsRequest::class => MockResponse::make(smsPage('keywords', [
                ['keyword' => 'STOP'],
            ])),
        ]);

        $items = iterator_to_array($client->keywords()->all()->items(), false);

        expect($items)->toHaveCount(1)->and($items[0]['keyword'])->toBe('STOP');
    });

    it('reads message recipients from the `recipients` key', function () {
        $client = mockedSmsClient([
            GetSmsSentRequest::class => MockResponse::make(smsPage('recipients', [
                ['msisdn' => '+61400000001', 'delivery_status' => 'delivered'],
                ['msisdn' => '+61400000002', 'delivery_status' => 'pending'],
            ])),
        ]);

        $items = iterator_to_array($client->reporting()->getSent(49606)->items(), false);

        expect($items)->toHaveCount(2)->and($items[0]['delivery_status'])->toBe('delivered');
    });

    it('reads user-sent messages from the `messages` key', function () {
        $client = mockedSmsClient([
            GetUserSmsSentRequest::class => MockResponse::make(smsPage('messages', [
                ['id' => 10],
                ['id' => 11],
            ])),
        ]);

        $items = iterator_to_array($client->reporting()->getUserSent()->items(), false);

        expect($items)->toHaveCount(2)->and($items[1]['id'])->toBe(11);
    });

    it('reads list members from the `members` key (get-list)', function () {
        $client = mockedSmsClient([
            GetListRequest::class => MockResponse::make(smsPage('members', [
                ['msisdn' => '+61400000001', 'first_name' => 'Ada'],
            ])),
        ]);

        // Regression: getContacts() paginated a non-Paginatable request and
        // threw InvalidArgumentException before GetListRequest was made paginatable.
        $items = iterator_to_array($client->lists()->getContacts(6191)->items(), false);

        expect($items)->toHaveCount(1)->and($items[0]['first_name'])->toBe('Ada');
    });

    it('reads message responses from the `responses` key', function () {
        $client = mockedSmsClient([
            GetSmsResponsesRequest::class => MockResponse::make(smsPage('responses', [
                ['response' => 'YES'],
            ])),
        ]);

        $items = iterator_to_array($client->sms()->getResponses(49484)->items(), false);

        expect($items)->toHaveCount(1)->and($items[0]['response'])->toBe('YES');
    });

    it('reads account responses from the `responses` key', function () {
        $client = mockedSmsClient([
            GetUserSmsResponsesRequest::class => MockResponse::make(smsPage('responses', [
                ['response' => 'STOP'],
                ['response' => 'INFO'],
            ])),
        ]);

        $items = iterator_to_array($client->sms()->getAllResponses()->items(), false);

        expect($items)->toHaveCount(2)->and($items[0]['response'])->toBe('STOP');
    });
});
