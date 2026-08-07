<?php

declare(strict_types=1);

/**
 * Loaders for the captured V2 webhook payloads.
 *
 * The sole remaining consumer is tests/Unit/V2WebhookReceiverTest.php — a
 * Laravel-package test that stays at root permanently. (V2WebhookEventTest.php,
 * V2StatusPrecedenceTest.php and the webhook resource spec were the other
 * consumers; all three were ported into the client package.) Lives here
 * rather than being declared in the one remaining spec and `require_once`'d
 * — this file is loaded once, up front, by tests/Pest.php, so running a
 * single spec file in isolation still works.
 *
 * (Root `StubV2SendRequest.php` used to be loaded the same way and served as
 * the other example of this pattern; it moved to
 * packages/kudosity-client/tests/Fixtures/ in Task 7b batch 3, once the last
 * root spec depending on it was ported, and is loaded there via a composer
 * classmap entry instead.)
 */

/**
 * A real delivery, captured against the live API.
 *
 * Read from disk rather than inlined: the fixture is the evidence, and a pasted
 * copy stops tracking it the moment either drifts. See
 * packages/kudosity-client/tests/Fixtures/V2Webhooks/README.md for what each
 * one pins.
 *
 * **The files live in the client package**, which is the package whose API
 * produced them and the only one published to a consumer. This function reads
 * through to that single copy rather than keeping a second one here, so the two
 * suites can never disagree about what the API actually sent.
 *
 * @return array<string, mixed>
 */
function webhookFixture(string $name): array
{
    $path = __DIR__.'/../../packages/kudosity-client/tests/Fixtures/V2Webhooks/'.$name.'.json';

    if (! is_file($path)) {
        // Named rather than silently decoding null: a typo'd fixture that
        // yields [] makes every assertion against it pass vacuously.
        throw new InvalidArgumentException("No such webhook fixture: {$name} (looked in {$path})");
    }

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
