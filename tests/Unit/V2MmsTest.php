<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\GetMmsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendMmsRequest;
use ExpertSystems\Kudosity\Resources\MmsResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** Verbatim from .agents/skills/kudosity-mms/SKILL.md — note the FLAT envelope. */
function mmsSendBody(array $overrides = []): array
{
    return array_merge([
        'id' => '6fdae71c-dad7-4c36-9734-a69693ec2318',
        'recipient' => '61435795809',
        'sender' => '61481074185',
        'country' => 'AU',
        'subject' => 'USS Enterprise',
        'message' => 'Check out this amazing specimen.',
        'message_ref' => 'ncc1701d',
        'content_urls' => ['https://example.com/product.jpg'],
        'status' => 'pending',
        'track_links' => true,
        'created_at' => '2022-03-29T04:42:01.631708761Z',
        'updated_at' => '2022-03-29T04:42:01.631708761Z',
    ], $overrides);
}

function mmsResource(array $responses): MmsResource
{
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient(new MockClient($responses));

    return new MmsResource($connector);
}

it('sends a single-recipient MMS and returns a typed DTO', function () {
    $mock = new MockClient([SendMmsRequest::class => MockResponse::make(mmsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $mms = (new MmsResource($connector))->send('61435795809', '61481074185', ['https://example.com/product.jpg']);

    expect($mms)->toBeInstanceOf(MmsMessageData::class)
        ->and($mms->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ec2318')
        ->and($mms->country)->toBe('AU')
        ->and($mms->contentUrls)->toBe(['https://example.com/product.jpg']);

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body)->toBe([
        'sender' => '61481074185',
        'recipient' => '61435795809',
        'content_urls' => ['https://example.com/product.jpg'],
    ]);
});

it('resolves the flat envelope through payload()', function () {
    // MMS is flat like SMS (unlike WhatsApp/RCS), but still routes through
    // payload() so all four V2 channels stay identical regardless of shape.
    $mms = mmsResource([SendMmsRequest::class => MockResponse::make(mmsSendBody(), 200)])
        ->send('61435795809', '61481074185', ['https://example.com/product.jpg']);

    expect($mms->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ec2318');
});

it('maps status: pending on the immediate response without treating it as a failure', function () {
    // The skill is explicit: submission-pending is not an error. A pending
    // MMS response must resolve successfully to MessageStatus::Pending, not
    // throw.
    $mms = mmsResource([SendMmsRequest::class => MockResponse::make(mmsSendBody(['status' => 'pending']), 200)])
        ->send('61435795809', '61481074185', ['https://example.com/product.jpg']);

    expect($mms->status)->toBe(MessageStatus::Pending);
});

it('sends optional fields when given', function () {
    $mock = new MockClient([SendMmsRequest::class => MockResponse::make(mmsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new MmsResource($connector))->send(
        '61435795809',
        '61481074185',
        ['https://example.com/product.jpg'],
        subject: 'New Arrival',
        message: 'Check out our latest product!',
        messageRef: 'order-1',
        trackLinks: true,
    );

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body['subject'])->toBe('New Arrival')
        ->and($body['message'])->toBe('Check out our latest product!')
        ->and($body['message_ref'])->toBe('order-1')
        ->and($body['track_links'])->toBeTrue();
});

it('omits optional fields from the body rather than sending nulls', function () {
    $mock = new MockClient([SendMmsRequest::class => MockResponse::make(mmsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new MmsResource($connector))->send('61435795809', '61481074185', ['https://example.com/product.jpg']);

    $body = $mock->getLastPendingRequest()->body()->all();

    expect($body)->not->toHaveKey('subject')
        ->and($body)->not->toHaveKey('message')
        ->and($body)->not->toHaveKey('message_ref')
        ->and($body)->not->toHaveKey('track_links');
});

it('rejects an empty content_urls array', function () {
    new SendMmsRequest('61435795809', '61481074185', []);
})->throws(ValidationException::class);

it('rejects more than one content url, naming the one-file limit', function () {
    new SendMmsRequest('61435795809', '61481074185', [
        'https://example.com/product.jpg',
        'https://example.com/other.jpg',
    ]);
})->throws(ValidationException::class, 'one');

it('rejects a relative content url', function () {
    new SendMmsRequest('61435795809', '61481074185', ['/relative/path.jpg']);
})->throws(ValidationException::class);

it('rejects a subject longer than 20 characters', function () {
    new SendMmsRequest(
        '61435795809',
        '61481074185',
        ['https://example.com/product.jpg'],
        subject: str_repeat('a', 21),
    );
})->throws(ValidationException::class, '20');

it('rejects a non-ASCII subject even within the 20 character limit', function () {
    // The one a caller will not expect: length alone is not enough, the API
    // documents subject as ASCII-only.
    new SendMmsRequest(
        '61435795809',
        '61481074185',
        ['https://example.com/product.jpg'],
        subject: 'Café Launch',
    );
})->throws(ValidationException::class, 'ASCII');

it('rejects a message longer than 1000 characters', function () {
    new SendMmsRequest(
        '61435795809',
        '61481074185',
        ['https://example.com/product.jpg'],
        message: str_repeat('a', 1001),
    );
})->throws(ValidationException::class, '1000');

it('rejects a message_ref longer than the documented 500 characters', function () {
    new SendMmsRequest(
        '61435795809',
        '61481074185',
        ['https://example.com/product.jpg'],
        messageRef: str_repeat('a', 501),
    );
})->throws(ValidationException::class, '500');

it('does not reject a non-AU recipient client-side, because MMS-is-AU-only is the APIs judgement to make, not the SDKs', function () {
    $mock = new MockClient([SendMmsRequest::class => MockResponse::make(mmsSendBody(['recipient' => '14155552671', 'country' => 'US']), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $mms = (new MmsResource($connector))->send('14155552671', '61481074185', ['https://example.com/product.jpg']);

    expect($mms)->toBeInstanceOf(MmsMessageData::class)
        ->and($mms->recipient)->toBe('14155552671');
});

it('reads one MMS by id', function () {
    $mock = new MockClient([GetMmsRequest::class => MockResponse::make(mmsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $mms = (new MmsResource($connector))->get('6fdae71c-dad7-4c36-9734-a69693ec2318');

    expect($mms->id)->toBe('6fdae71c-dad7-4c36-9734-a69693ec2318')
        ->and((string) $mock->getLastPendingRequest()->getUri())
        ->toBe('https://api.transmitmessage.com/v2/mms/6fdae71c-dad7-4c36-9734-a69693ec2318');
});

it('sends no body on the GET reader', function () {
    // Same split as SMS: readers extend KudosityV2Request (no body) so a V2
    // GET never carries one, which some gateways strip or reject.
    $mock = new MockClient([GetMmsRequest::class => MockResponse::make(mmsSendBody(), 200)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    (new MmsResource($connector))->get('abc');

    $pending = $mock->getLastPendingRequest();

    expect($pending->headers()->get('Content-Type'))->toBeNull()
        ->and((string) $pending->body())->toBe('');
});

it('turns a 404 into a NotFoundException', function () {
    mmsResource([GetMmsRequest::class => MockResponse::make(['error' => 'MMS not found'], 404)])->get('nope');
})->throws(NotFoundException::class, 'MMS not found');

it('casts track_links to a bool', function () {
    $mms = MmsMessageData::fromArray(mmsSendBody(['track_links' => true]));

    expect($mms->trackLinks)->toBeTrue()->and($mms->trackLinks)->toBeBool();
});

it('parses the nine-fractional-digit created_at timestamp that RFC3339_EXTENDED rejects', function () {
    $mms = MmsMessageData::fromArray(mmsSendBody(['created_at' => '2022-03-29T04:42:01.631708761Z']));

    expect($mms->createdAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($mms->createdAt)->not->toBeNull();
});

it('returns null for a malformed created_at rather than throwing', function () {
    $mms = MmsMessageData::fromArray(mmsSendBody(['created_at' => 'not-a-date']));

    expect($mms->createdAt)->toBeNull();
});
