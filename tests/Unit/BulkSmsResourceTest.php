<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Requests\CancelSmsRequest;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use ExpertSystems\Kudosity\Resources\BulkSmsResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function sendSmsSuccess(): MockResponse
{
    return MockResponse::make([
        'error' => ['code' => 'SUCCESS', 'description' => 'OK'],
        'message_id' => 7788,
        'send_at' => '2026-08-05 10:00:00',
        'recipients' => 2,
        'cost' => 0.16,
        'sms' => 1,
    ], 200);
}

function bulkResource(array $responses): BulkSmsResource
{
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient(new MockClient($responses));

    return new BulkSmsResource($connector);
}

it('sends to multiple comma-separated recipients', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    $result = (new BulkSmsResource($connector))->send('Sale starts tomorrow', '61400000000,61400000001');

    expect($result)->toBeInstanceOf(SmsData::class)
        ->and($result->messageId)->toBe(7788)
        ->and($result->recipients)->toBe(2)
        ->and($mock->getLastPendingRequest()->body()->all()['to'])
        ->toBe('61400000000,61400000001');
});

it('sends to a contact list', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->sendToList('Sale', 4213644);

    expect($mock->getLastPendingRequest()->body()->all()['list_id'])->toBe(4213644);
});

it('schedules a send', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->schedule('Reminder', '61400000000', '2026-09-01 09:00:00');

    expect($mock->getLastPendingRequest()->body()->all()['send_at'])->toBe('2026-09-01 09:00:00');
});

it('applies the connector default sender and country code', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->setDefaultFrom('MyBrand')->setDefaultCountryCode('AU');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->send('Hi', '0400000000');

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['from'])->toBe('MyBrand')
        ->and($body['countrycode'])->toBe('AU');
});

it('lets an explicit sender override the connector default', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->setDefaultFrom('MyBrand');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->send('Hi', '61400000000', from: 'Override');

    expect($mock->getLastPendingRequest()->body()->all()['from'])->toBe('Override');
});

it('passes the request to the configure closure after defaults are applied', function () {
    $mock = new MockClient([SendSmsRequest::class => sendSmsSuccess()]);
    $connector = new KudosityV1Connector('key', 'secret');
    $connector->withMockClient($mock);

    (new BulkSmsResource($connector))->send(
        'Hi',
        '61400000000',
        configure: fn (SendSmsRequest $r) => $r->validity(60)
    );

    expect($mock->getLastPendingRequest()->body()->all()['validity'])->toBe(60);
});

it('reports whether a cancel succeeded', function () {
    expect(bulkResource([
        CancelSmsRequest::class => MockResponse::make(['error' => ['code' => 'SUCCESS']], 200),
    ])->cancel(7788))->toBeTrue();
});

it('exposes the offline phone helpers', function () {
    $resource = bulkResource([]);

    expect($resource->isValidNumber('61400000000'))->toBeTrue()
        ->and($resource->isValidSenderId('MyBrand'))->toBeTrue()
        ->and($resource->formatNumberLocal('0400000000', 'AU'))->toBe('61400000000');
});
