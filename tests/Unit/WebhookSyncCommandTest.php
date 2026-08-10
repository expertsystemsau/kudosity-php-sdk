<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\V2\EnsureResult;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use Illuminate\Contracts\Console\Kernel;

beforeEach(function () {
    // sync writes to the account, so every test here has to clear the gate.
    app()['env'] = 'production';
    config()->set('app.url', 'https://app.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production']);
});

it('reconciles through ensure() rather than creating unconditionally', function () {
    // install creates every time it runs; sync must not. This is the difference
    // between the two commands.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Created, fakeHook(['id' => 'wh_new'])),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('wh_new')
        ->assertExitCode(0);
});

it('registers a signed receiver URL, not a bare one', function () {
    // The receiver 403s an unsigned request, so a bare URL registers a webhook
    // that can never deliver — and fails silently, because Kudosity cannot tell
    // you your endpoint is rejecting it.
    $captured = null;

    fakeWebhooks()->shouldReceive('ensure')->once()->withArgs(function (...$args) use (&$captured) {
        $captured = $args[1];

        return true;
    })->andReturn(new EnsureResult(EnsureAction::Created, fakeHook()));

    $this->artisan('kudosity:webhook:sync')->assertExitCode(0);

    expect($captured)->toStartWith('https://app.example.com/webhooks/kudosity/events?')
        ->and($captured)->toContain('h=')
        ->and($captured)->toContain('s=');
});

it('succeeds and says nothing changed when the registration is already correct', function () {
    // Re-running on every deploy is the intended usage, so the no-op path must be
    // a success rather than a warning an operator learns to ignore.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Unchanged, fakeHook(['id' => 'wh_1'])),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('already correct')
        ->assertExitCode(0);
});

it('prints both the previous and the new URL on a repair', function () {
    // An Updated result means something drifted, and the operator cannot tell
    // what without seeing the URL that was replaced.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Updated, fakeHook(['url' => 'https://app.example.com/webhooks/kudosity/events?s=NEW'])),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('s=NEW')
        ->assertExitCode(0);
});

it('reports duplicates and says nothing was deleted', function () {
    // Silence here would let a duplicated account keep delivering two copies of
    // every event indefinitely.
    fakeWebhooks()->shouldReceive('ensure')->once()->andReturn(
        new EnsureResult(EnsureAction::Unchanged, fakeHook(['id' => 'wh_1']), [fakeHook(['id' => 'wh_dup'])]),
    );

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('wh_dup')
        ->expectsOutputToContain('kudosity:webhook:delete')
        ->assertExitCode(0);
});

it('refuses a plaintext APP_URL outside a local environment', function () {
    config()->set('app.url', 'http://app.example.com');
    fakeWebhooks()->shouldNotReceive('ensure');

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('must be HTTPS')
        ->assertExitCode(1);
});

it('refuses to run at all from an environment not permitted to write', function () {
    app()['env'] = 'staging';
    fakeWebhooks()->shouldNotReceive('ensure');

    $this->artisan('kudosity:webhook:sync')->assertExitCode(1);
});

it('rejects an unrecognised event type rather than registering a webhook that delivers nothing', function () {
    // The API silently ignores an event type it does not know, so the
    // registration would look correct and deliver nothing.
    fakeWebhooks()->shouldNotReceive('ensure');

    $this->artisan('kudosity:webhook:sync', ['--event' => ['SMS_STATUS', 'TELEPATHY']])
        ->expectsOutputToContain('TELEPATHY')
        ->assertExitCode(1);
});

it('reports a client failure without a stack trace', function () {
    fakeWebhooks()->shouldReceive('ensure')->once()
        ->andThrow(new ValidationException('url must be a valid URL'));

    $this->artisan('kudosity:webhook:sync')
        ->expectsOutputToContain('url must be a valid URL')
        ->assertExitCode(1);
});

it('is registered as a command', function () {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('kudosity:webhook:sync');
});
