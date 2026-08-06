<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Laravel\Console\Commands\WebhookInstallCommand;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use Illuminate\Contracts\Console\Kernel;

/** Bind a mocked webhooks resource into the container the commands resolve from. */
function fakeWebhooks(): WebhooksResource
{
    $resource = Mockery::mock(WebhooksResource::class);
    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('webhooks')->andReturn($resource);
    app()->instance(KudosityClient::class, $client);

    return $resource;
}

/** @param array<string, mixed> $overrides */
function fakeHook(array $overrides = []): WebhookData
{
    return WebhookData::fromArray(array_merge([
        'id' => '8ab7060c-6c74-482b-baf6-8e7ef36cdf63',
        'name' => 'Prod events',
        'url' => 'https://e.test/h',
        'filter' => ['event_type' => ['SMS_STATUS']],
        'rate_limit' => 0,
        'is_sandbox' => false,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// list
// ---------------------------------------------------------------------------

it('says so in a sentence when no webhooks are registered', function () {
    // The API answers `{}` for an empty account, and an empty table reads like a
    // broken command.
    fakeWebhooks()->shouldReceive('all')->once()->andReturn([]);

    $this->artisan('kudosity:webhook:list')
        ->expectsOutputToContain('No webhooks registered')
        ->assertExitCode(0);
});

it('lists a registration', function () {
    // Asserts the id and the exit code only. The other columns — events, rate
    // limit, TLS — are NOT asserted, deliberately: Laravel truncates the table to
    // the terminal width, so those cells are absent from captured output and an
    // assertion on them either fails or, worse, passes on an incidental match
    // elsewhere in the output. A short string like "all" did exactly that here.
    //
    // The values themselves are covered where they are computed, on WebhookData
    // and WebhookFilter, in V2WebhooksResourceTest.
    fakeWebhooks()->shouldReceive('all')->once()->andReturn([fakeHook(['id' => 'hook-1'])]);

    $this->artisan('kudosity:webhook:list')
        ->expectsOutputToContain('hook-1')
        ->assertExitCode(0);
});

it('warns about a plaintext registration rather than just listing it', function () {
    // The platform accepts http:// even though the docs forbid it, so one made by
    // another tool can exist on the account.
    fakeWebhooks()->shouldReceive('all')->once()->andReturn([
        fakeHook(['url' => 'http://e.test/h']),
    ]);

    $this->artisan('kudosity:webhook:list')
        ->expectsOutputToContain('plaintext')
        ->assertExitCode(0);
});

it('exits non-zero when listing fails', function () {
    fakeWebhooks()->shouldReceive('all')->once()->andThrow(new NotFoundException('nope'));

    $this->artisan('kudosity:webhook:list')->assertExitCode(1);
});

// ---------------------------------------------------------------------------
// install
// ---------------------------------------------------------------------------

it('installs a webhook at a signed receiver URL, not a bare one', function () {
    // THE point of this command. The receiver 403s an unsigned request, so a
    // hand-assembled URL registers a webhook that can never deliver — and fails
    // silently, because Kudosity cannot tell you your endpoint is rejecting it.
    config()->set('app.url', 'https://app.example.com');

    $captured = null;

    fakeWebhooks()->shouldReceive('create')->once()->withArgs(function (...$args) use (&$captured) {
        $captured = $args[1];

        return true;
    })->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install', ['--event' => ['SMS_STATUS']])->assertExitCode(0);

    expect($captured)->toStartWith('https://app.example.com/webhooks/kudosity/events?')
        // h and s are what the receiver requires; without them it returns 403.
        ->and($captured)->toContain('h=')
        ->and($captured)->toContain('s=');
});

it('refuses a plaintext APP_URL outside a local environment', function () {
    // The real-environment rule: HTTPS only. Deliveries carry message content and
    // phone numbers and are unsigned.
    app()['env'] = 'production';
    config()->set('app.url', 'http://app.example.test');
    fakeWebhooks();

    $this->artisan('kudosity:webhook:install')
        ->expectsOutputToContain('must be HTTPS')
        ->expectsOutputToContain('APP_URL')
        ->assertExitCode(1);
});

it('allows a plaintext receiver on a local environment, with a warning', function () {
    // Local development often has no TLS — a container, or a tunnel terminating
    // elsewhere — and the traffic never leaves the machine. Permitted, but never
    // silently: the warning says why it is acceptable here and nowhere else.
    app()['env'] = 'local';
    config()->set('app.url', 'http://kudosity.test');

    $insecure = null;

    fakeWebhooks()->shouldReceive('create')->once()->withArgs(function (...$args) use (&$insecure) {
        $insecure = $args[5] ?? null;

        return true;
    })->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')
        ->expectsOutputToContain('APP_ENV=local')
        ->assertExitCode(0);

    // The opt-in must actually reach the request, or the client-side guard rejects
    // it after the command has said it was fine.
    expect($insecure)->toBeTrue();
});

it('does not opt in to plaintext when the local URL is already HTTPS', function () {
    app()['env'] = 'local';
    config()->set('app.url', 'https://kudosity.test');

    $insecure = null;

    fakeWebhooks()->shouldReceive('create')->once()->withArgs(function (...$args) use (&$insecure) {
        $insecure = $args[5] ?? null;

        return true;
    })->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')->assertExitCode(0);

    expect($insecure)->toBeFalse();
});

it('rejects an unrecognised event type instead of registering a webhook that delivers nothing', function () {
    // An event type the API does not know is silently ignored, so the
    // registration would look correct and never fire.
    config()->set('app.url', 'https://app.example.com');
    $resource = fakeWebhooks();
    $resource->shouldNotReceive('create');

    $this->artisan('kudosity:webhook:install', ['--event' => ['SMS_STATUS', 'TELEPATHY']])
        ->expectsOutputToContain('Unrecognised event type: TELEPATHY')
        ->expectsOutputToContain('LINK_HIT')
        ->assertExitCode(1);
});

it('subscribes to every event type when none is named', function () {
    config()->set('app.url', 'https://app.example.com');

    $events = 'unset';

    fakeWebhooks()->shouldReceive('create')->once()->withArgs(function (...$args) use (&$events) {
        $events = $args[2];

        return true;
    })->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')->assertExitCode(0);

    expect($events)->toBe([]);
});

it('honours an explicit --url override', function () {
    config()->set('app.url', 'https://app.example.com');

    $url = null;

    fakeWebhooks()->shouldReceive('create')->once()->withArgs(function (...$args) use (&$url) {
        $url = $args[1];

        return true;
    })->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install', ['--url' => 'https://elsewhere.example.com/hook?s=x&h=y'])
        ->assertExitCode(0);

    expect($url)->toBe('https://elsewhere.example.com/hook?s=x&h=y');
});

it('passes a rate limit through only when given', function () {
    config()->set('app.url', 'https://app.example.com');

    $limits = [];

    $resource = fakeWebhooks();
    $resource->shouldReceive('create')->twice()->withArgs(function (...$args) use (&$limits) {
        $limits[] = $args[4] ?? null;

        return true;
    })->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')->assertExitCode(0);
    $this->artisan('kudosity:webhook:install', ['--rate-limit' => '250'])->assertExitCode(0);

    expect($limits)->toBe([null, 250]);
});

it('exits non-zero when the API rejects the registration', function () {
    config()->set('app.url', 'https://app.example.com');

    fakeWebhooks()->shouldReceive('create')->once()
        ->andThrow(new ValidationException('name too short', errorCode: 'FIELD_INVALID'));

    $this->artisan('kudosity:webhook:install')
        ->expectsOutputToContain('name too short')
        ->assertExitCode(1);
});

it('uses the marker handler, since V2 dispatches events rather than a handler class', function () {
    expect(WebhookInstallCommand::HANDLER_MARKER)->toBe('kudosity.v2.events');
});

// ---------------------------------------------------------------------------
// delete
// ---------------------------------------------------------------------------

it('confirms before deleting, and does nothing when declined', function () {
    $resource = fakeWebhooks();
    $resource->shouldNotReceive('delete');

    $this->artisan('kudosity:webhook:delete', ['id' => 'abc'])
        ->expectsConfirmation('Delete webhook abc? V2 delivery reports and inbound messages will stop arriving.', 'no')
        ->expectsOutputToContain('Left alone')
        ->assertExitCode(0);
});

it('deletes when confirmed', function () {
    fakeWebhooks()->shouldReceive('delete')->once()->with('abc')->andReturn(true);

    $this->artisan('kudosity:webhook:delete', ['id' => 'abc'])
        ->expectsConfirmation('Delete webhook abc? V2 delivery reports and inbound messages will stop arriving.', 'yes')
        ->expectsOutputToContain('Deleted webhook abc')
        ->assertExitCode(0);
});

it('skips the confirmation with --force', function () {
    fakeWebhooks()->shouldReceive('delete')->once()->with('abc')->andReturn(true);

    $this->artisan('kudosity:webhook:delete', ['id' => 'abc', '--force' => true])
        ->assertExitCode(0);
});

it('exits non-zero when the registration does not exist', function () {
    fakeWebhooks()->shouldReceive('delete')->once()->andThrow(new NotFoundException('Webhook not found'));

    $this->artisan('kudosity:webhook:delete', ['id' => 'nope', '--force' => true])
        ->expectsOutputToContain('Webhook not found')
        ->assertExitCode(1);
});

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

it('registers all three commands', function () {
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('kudosity:webhook:list')
        ->and($commands)->toContain('kudosity:webhook:install')
        ->and($commands)->toContain('kudosity:webhook:delete');
});
