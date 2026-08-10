<?php

use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use ExpertSystems\Kudosity\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Shared test doubles, loaded once regardless of which spec file(s) run —
// see tests/Fixtures/ for why these aren't declared in one spec and
// require_once'd by the others.
require_once __DIR__.'/Fixtures/WebhookPayloads.php';

/**
 * Bind a mocked webhooks resource into the container the commands resolve from.
 *
 * Shared by WebhookCommandsTest and WebhookEnvironmentGateTest — declared once
 * here rather than in either spec, because Pest loads every test file into one
 * process and a duplicate declaration in either file would collide with it.
 */
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
