<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\Content\TemplateContent;
use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMmsChannel;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMmsMessage;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityRcsChannel;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityRcsMessage;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityWhatsAppChannel;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityWhatsAppMessage;
use ExpertSystems\Kudosity\Resources\MmsResource;
use ExpertSystems\Kudosity\Resources\RcsResource;
use ExpertSystems\Kudosity\Resources\WhatsAppResource;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;

/** A notifiable that routes every channel to one number. */
function v2Notifiable(?string $number = '61400000000'): object
{
    return new class($number)
    {
        public function __construct(private ?string $number) {}

        public function routeNotificationFor($channel, $notification)
        {
            return $this->number;
        }
    };
}

/** A notification whose builder method is chosen by name. */
function v2Notification(string $method, object $message): Notification
{
    return new class($method, $message) extends Notification
    {
        public function __construct(private string $method, private object $message) {}

        public function __call($name, $arguments)
        {
            return $name === $this->method ? $this->message : parent::__call($name, $arguments);
        }
    };
}

// ---------------------------------------------------------------------------
// MMS
// ---------------------------------------------------------------------------

it('sends an MMS through the V2 resource', function () {
    $resource = Mockery::mock(MmsResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('mms')->andReturn($resource);

    $sent = MmsMessageData::fromArray(['id' => 'mms-1', 'recipient' => '61400000000', 'sender' => '61481074185']);

    $resource->shouldReceive('send')->once()->withArgs(
        fn (string $to, string $from, array $contentUrls, ?string $subject) => $to === '61400000000'
            && $from === '61426309571'
            && $contentUrls === ['https://example.com/a.jpg']
            && $subject === 'Sale'
    )->andReturn($sent);

    config()->set('kudosity.mms.sender', '61426309571');

    $message = KudosityMmsMessage::create('Look at this')
        ->media('https://example.com/a.jpg')
        ->subject('Sale');

    expect((new KudosityMmsChannel($client))->send(v2Notifiable(), v2Notification('toKudosityMms', $message)))
        ->toBe($sent);
});

it('prefers the MMS sender key over the shared SMS from', function () {
    // An alphanumeric sender that works for SMS is not a valid MMS sender, so one
    // shared default cannot serve both.
    $resource = Mockery::mock(MmsResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('mms')->andReturn($resource);

    config()->set('kudosity.from', 'MyBrand');
    config()->set('kudosity.mms.sender', '61426309571');

    $resource->shouldReceive('send')->once()->withArgs(
        fn (string $to, string $from) => $from === '61426309571'
    )->andReturn(MmsMessageData::fromArray(['id' => 'x', 'recipient' => '1', 'sender' => '2']));

    (new KudosityMmsChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityMms', KudosityMmsMessage::create('Hi')->media('https://e.com/a.jpg')),
    );
});

it('falls back to the shared from when no MMS sender is configured', function () {
    $resource = Mockery::mock(MmsResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('mms')->andReturn($resource);

    config()->set('kudosity.from', '61426309571');
    config()->set('kudosity.mms.sender', null);

    $resource->shouldReceive('send')->once()->withArgs(
        fn (string $to, string $from) => $from === '61426309571'
    )->andReturn(MmsMessageData::fromArray(['id' => 'x', 'recipient' => '1', 'sender' => '2']));

    (new KudosityMmsChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityMms', KudosityMmsMessage::create('Hi')->media('https://e.com/a.jpg')),
    );
});

it('refuses an MMS with no media file, naming the method to call', function () {
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('mms')->andReturn(Mockery::mock(MmsResource::class));

    (new KudosityMmsChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityMms', KudosityMmsMessage::create('No media')),
    );
})->throws(ValidationException::class, 'media()');

// ---------------------------------------------------------------------------
// WhatsApp
// ---------------------------------------------------------------------------

it('sends WhatsApp free-form text through the V2 resource', function () {
    $resource = Mockery::mock(WhatsAppResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('whatsapp')->andReturn($resource);

    $sent = WhatsAppMessageData::fromArray(['id' => 'wa-1']);

    $resource->shouldReceive('send')->once()->withArgs(
        fn ($content, string $to) => $content instanceof TextContent && $to === '61400000000'
    )->andReturn($sent);

    expect((new KudosityWhatsAppChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityWhatsApp', KudosityWhatsAppMessage::create()->text('Hi')),
    ))->toBe($sent);
});

it('sends a WhatsApp template, which is the only way to open a conversation', function () {
    $resource = Mockery::mock(WhatsAppResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('whatsapp')->andReturn($resource);

    $resource->shouldReceive('send')->once()->withArgs(
        fn ($content) => $content instanceof TemplateContent
    )->andReturn(WhatsAppMessageData::fromArray(['id' => 'wa-2']));

    (new KudosityWhatsAppChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityWhatsApp', KudosityWhatsAppMessage::create()->template('order_update', ['ACME'])),
    );
});

it('lets the last WhatsApp content variant win rather than sending two', function () {
    // content_type and content must agree on the wire. One property, so they
    // cannot desynchronise.
    $message = KudosityWhatsAppMessage::create()->text('Hi')->template('order_update', ['ACME']);

    expect($message->getContent())->toBeInstanceOf(TemplateContent::class);
});

it('omits the WhatsApp sender rather than defaulting it to the SMS one', function () {
    // WhatsApp needs a registered WhatsApp Business number; an SMS sender ID would
    // be rejected. Null lets the account default apply, which is the API's own
    // behaviour.
    $resource = Mockery::mock(WhatsAppResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('whatsapp')->andReturn($resource);

    config()->set('kudosity.from', 'MyBrand');
    config()->set('kudosity.whatsapp.sender', null);

    $resource->shouldReceive('send')->once()->withArgs(
        fn ($content, string $to, ?string $from) => $from === null
    )->andReturn(WhatsAppMessageData::fromArray(['id' => 'wa-3']));

    (new KudosityWhatsAppChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityWhatsApp', KudosityWhatsAppMessage::create()->text('Hi')),
    );
});

it('refuses a WhatsApp message with no content, pointing at the 24-hour window', function () {
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('whatsapp')->andReturn(Mockery::mock(WhatsAppResource::class));

    (new KudosityWhatsAppChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityWhatsApp', KudosityWhatsAppMessage::create()),
    );
})->throws(ValidationException::class, '24-hour service window');

// ---------------------------------------------------------------------------
// RCS
// ---------------------------------------------------------------------------

it('sends RCS with the configured agent ID', function () {
    $resource = Mockery::mock(RcsResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('rcs')->andReturn($resource);

    config()->set('kudosity.rcs.agent_id', 'DemoSender');

    $sent = RcsMessageData::fromArray(['id' => 'rcs-1']);

    $resource->shouldReceive('send')->once()->withArgs(
        fn (string $message, string $to, string $agentId) => $agentId === 'DemoSender' && $to === '61400000000'
    )->andReturn($sent);

    expect((new KudosityRcsChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityRcs', KudosityRcsMessage::create('Shipped')),
    ))->toBe($sent);
});

it('lets the message override the configured agent ID', function () {
    $resource = Mockery::mock(RcsResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('rcs')->andReturn($resource);

    config()->set('kudosity.rcs.agent_id', 'ConfigAgent');

    $resource->shouldReceive('send')->once()->withArgs(
        fn (string $message, string $to, string $agentId) => $agentId === 'MessageAgent'
    )->andReturn(RcsMessageData::fromArray(['id' => 'rcs-2']));

    (new KudosityRcsChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityRcs', KudosityRcsMessage::create('Hi')->agentId('MessageAgent')),
    );
});

it('refuses an RCS send with no agent ID anywhere, naming the config key', function () {
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('rcs')->andReturn(Mockery::mock(RcsResource::class));

    config()->set('kudosity.rcs.agent_id', null);

    (new KudosityRcsChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityRcs', KudosityRcsMessage::create('Hi')),
    );
})->throws(ValidationException::class, 'kudosity.rcs.agent_id');

it('still rejects a phone-number-shaped RCS agent ID through the channel', function () {
    // Phase 3's guard lives on SendRcsRequest, so it fires whether the value came
    // from the message or from config. Asserted here so a channel that bypassed
    // the request class would be caught.
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('rcs')->andReturn(new RcsResource(new KudosityV2Connector('key')));

    config()->set('kudosity.rcs.agent_id', '61426309571');

    (new KudosityRcsChannel($client))->send(
        v2Notifiable(),
        v2Notification('toKudosityRcs', KudosityRcsMessage::create('Hi')),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Shared behaviour
// ---------------------------------------------------------------------------

it('returns null when no recipient can be resolved, on every channel', function (string $channelClass, string $method, object $message, string $resourceMethod, string $resourceClass) {
    // Matching the SMS channel's existing behaviour: a notifiable with no route
    // for this channel is a skip, not an error.
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive($resourceMethod)->andReturn(Mockery::mock($resourceClass));

    expect((new $channelClass($client))->send(v2Notifiable(null), v2Notification($method, $message)))
        ->toBeNull();
})->with([
    'mms' => [KudosityMmsChannel::class, 'toKudosityMms', new KudosityMmsMessage('Hi'), 'mms', MmsResource::class],
    'whatsapp' => [KudosityWhatsAppChannel::class, 'toKudosityWhatsApp', new KudosityWhatsAppMessage, 'whatsapp', WhatsAppResource::class],
    'rcs' => [KudosityRcsChannel::class, 'toKudosityRcs', new KudosityRcsMessage('Hi'), 'rcs', RcsResource::class],
]);

it('registers all four channels on the notification manager', function () {
    $manager = app(ChannelManager::class);

    foreach ([
        'kudosity' => KudosityChannel::class,
        'kudosity-mms' => KudosityMmsChannel::class,
        'kudosity-whatsapp' => KudosityWhatsAppChannel::class,
        'kudosity-rcs' => KudosityRcsChannel::class,
    ] as $name => $class) {
        expect($manager->driver($name))->toBeInstanceOf($class);
    }
});
