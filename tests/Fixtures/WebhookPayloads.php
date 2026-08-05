<?php

declare(strict_types=1);

/**
 * Loaders for the captured V2 webhook payloads.
 *
 * Shared by V2WebhookEventTest.php, V2StatusPrecedenceTest.php and the webhook
 * resource spec. Lives here rather than being declared in one spec and
 * `require_once`'d by the others, for the same reason as
 * {@see StubV2SendRequest} — this file is loaded once, up front, by
 * tests/Pest.php, so running a single spec file in isolation still works.
 */

/**
 * A real delivery, captured against the live API.
 *
 * Read from disk rather than inlined: the fixture is the evidence, and a pasted
 * copy stops tracking it the moment either drifts. See
 * tests/Fixtures/V2Webhooks/README.md for what each one pins.
 *
 * @return array<string, mixed>
 */
function webhookFixture(string $name): array
{
    $path = __DIR__.'/V2Webhooks/'.$name.'.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A documented payload shape with no fixture yet — built from the skill's own
 * example.
 *
 * Used only for `OPT_OUT`, which is deliberately never captured (triggering it
 * means replying STOP, which opts the test handset out), and for `MMS_INBOUND`,
 * which never arrived and is an open question with Kudosity.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function documentedWebhook(string $eventType, array $payload): array
{
    return array_merge([
        'event_type' => $eventType,
        'timestamp' => '2026-08-05T12:00:00Z',
        'webhook_id' => '8aa2842d-8931-4f6f-b55a-826a6698699a',
        'webhook_name' => 'documented example',
    ], $payload);
}
