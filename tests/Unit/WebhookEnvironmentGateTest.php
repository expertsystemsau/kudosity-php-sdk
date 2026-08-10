<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Resources\WebhooksResource;

/**
 * Bind a webhooks resource that fails the test if anything touches it.
 *
 * The gate's contract is not "it prints a message" but "it makes no request".
 * A Mockery mock with no expectations throws on any call, so this asserts the
 * absence of API traffic rather than the presence of output.
 */
function forbiddenWebhooks(): void
{
    $resource = Mockery::mock(WebhooksResource::class);
    $resource->shouldNotReceive('all', 'create', 'update', 'delete', 'ensure');

    $client = Mockery::mock(KudosityClient::class);
    $client->shouldReceive('webhooks')->andReturn($resource);
    app()->instance(KudosityClient::class, $client);
}

it('refuses to install from an environment that is not permitted to write', function () {
    // One account backs every environment, so a staging registration receives
    // production's delivery receipts and inbound replies in full.
    app()['env'] = 'staging';
    config()->set('app.url', 'https://staging.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')
        ->expectsOutputToContain('staging')
        ->expectsOutputToContain('one Kudosity account')
        ->assertExitCode(1);
});

it('names the config key so the refusal is actionable', function () {
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')
        ->expectsOutputToContain('kudosity.webhooks.sync.environments')
        ->assertExitCode(1);
});

it('refuses to delete from an environment that is not permitted to write', function () {
    // delete writes to the same shared account. An operator on a staging box
    // deleting "a stale webhook" would be deleting production's.
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:delete', ['id' => 'wh_1'])->assertExitCode(1);
});

it('does not let --force bypass the environment gate', function () {
    // --force skips the confirmation prompt. It is not, and must never become,
    // an environment override — the gate is a security control.
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:delete', ['id' => 'wh_1', '--force' => true])->assertExitCode(1);
});

it('permits writes from a listed environment', function () {
    app()['env'] = 'production';
    config()->set('app.url', 'https://app.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production']);

    fakeWebhooks()->shouldReceive('create')->once()->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')->assertExitCode(0);
});

it('permits writes from any of several listed environments', function () {
    app()['env'] = 'staging';
    config()->set('app.url', 'https://staging.example.com');
    config()->set('kudosity.webhooks.sync.environments', ['production', 'staging']);

    fakeWebhooks()->shouldReceive('create')->once()->andReturn(fakeHook());

    $this->artisan('kudosity:webhook:install')->assertExitCode(0);
});

it('fails closed when the allowlist is empty', function () {
    // An empty list is a refusal, not a wildcard. Getting this backwards would
    // turn a misconfiguration into an exposure.
    app()['env'] = 'production';
    config()->set('kudosity.webhooks.sync.environments', []);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')->assertExitCode(1);
});

it('fails closed when the allowlist is absent entirely', function () {
    // A config published before this feature existed has no key at all. It must
    // refuse rather than default to permitting every environment.
    app()['env'] = 'production';
    config()->set('kudosity.webhooks', ['prefix' => 'webhooks/kudosity']);
    forbiddenWebhooks();

    $this->artisan('kudosity:webhook:install')->assertExitCode(1);
});

it('leaves the read-only list command ungated', function () {
    // Reading the account changes nothing, and an operator diagnosing a staging
    // problem needs to see what is registered.
    app()['env'] = 'staging';
    config()->set('kudosity.webhooks.sync.environments', ['production']);

    fakeWebhooks()->shouldReceive('all')->once()->andReturn([]);

    $this->artisan('kudosity:webhook:list')->assertExitCode(0);
});
