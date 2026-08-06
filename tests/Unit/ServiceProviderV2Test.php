<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;

// ---------------------------------------------------------------------------
// The V2 connector must be resolvable — it cannot autowire
// ---------------------------------------------------------------------------

it('resolves the V2 connector from the container', function () {
    // KudosityV2Connector's $apiKey has no default, so without an explicit
    // singleton the container cannot build it and every consumer injecting it
    // fails at resolution rather than at send time.
    expect(app(KudosityV2Connector::class))->toBeInstanceOf(KudosityV2Connector::class);
});

it('caches the V2 connector as a singleton, like the V1 one', function () {
    expect(app(KudosityV2Connector::class))->toBe(app(KudosityV2Connector::class));
});

it('gives the resolved V2 connector the configured API key', function () {
    config()->set('kudosity.api_key', 'a-specific-key');

    app()->forgetInstance(KudosityV2Connector::class);

    expect(app(KudosityV2Connector::class)->getApiKey())->toBe('a-specific-key');
});

// ---------------------------------------------------------------------------
// The timeout gap
// ---------------------------------------------------------------------------

it('applies the configured timeout to the V2 connector, not a hardcoded default', function () {
    // The provider used to build the client with fromConnector(), which derives
    // a V2 connector internally and gives it the library default regardless of
    // config. A consumer setting KUDOSITY_TIMEOUT got it on V1 only.
    config()->set('kudosity.timeout', 7);

    app()->forgetInstance(KudosityV2Connector::class);
    app()->forgetInstance(KudosityClient::class);

    expect(app(KudosityV2Connector::class)->getTimeout())->toBe(7)
        ->and(app(KudosityClient::class)->v2()->getTimeout())->toBe(7);
});

it('builds the client from both configured connectors, not a derived V2 one', function () {
    // The client's V2 connector must be the same instance the container holds,
    // or configuration applied to one silently does not apply to the other.
    expect(app(KudosityClient::class)->v2())->toBe(app(KudosityV2Connector::class))
        ->and(app(KudosityClient::class)->v1())->toBe(app(KudosityV1Connector::class));
});

// ---------------------------------------------------------------------------
// The base_url split
// ---------------------------------------------------------------------------

it('reads a separate base URL per API version', function () {
    config()->set('kudosity.base_url.v1', 'https://v1.test');
    config()->set('kudosity.base_url.v2', 'https://v2.test');

    app()->forgetInstance(KudosityV1Connector::class);
    app()->forgetInstance(KudosityV2Connector::class);

    expect(app(KudosityV1Connector::class)->getBaseUrl())->toBe('https://v1.test')
        ->and(app(KudosityV2Connector::class)->getBaseUrl())->toBe('https://v2.test');
});

it('defaults each version to its own real hostname', function () {
    // Neither is a Kudosity domain and neither may be "corrected" — this is the
    // regression test for the sweep that once rewrote the V1 host.
    expect(config('kudosity.base_url.v1'))->toBe('https://api.transmitsms.com')
        ->and(config('kudosity.base_url.v2'))->toBe('https://api.transmitmessage.com');
});

it('fails loudly when a config still carries the pre-2.0 flat base_url string', function () {
    // The consumer-visible break. A stale flat base_url points at
    // api.transmitsms.com, so silently ignoring it would send every V2 request
    // to the V1 host — where it would 404 or, worse, be misinterpreted. A
    // published config file is not re-published on upgrade, so this WILL happen.
    config()->set('kudosity.base_url', 'https://api.transmitsms.com');

    app()->forgetInstance(KudosityV1Connector::class);
    app()->forgetInstance(KudosityV2Connector::class);

    app(KudosityV2Connector::class);
})->throws(RuntimeException::class, 'base_url');

it('diagnoses a flat base_url as a flat base_url, not as a missing key', function () {
    // Asserting on a fragment ONLY this rule produces, and on the offending
    // value being echoed back. Without that, the missing-key guard below
    // satisfies a looser assertion — it also throws, and its message also
    // mentions base_url.v1 and base_url.v2 — so removing this check entirely
    // went unnoticed. The two messages are not interchangeable: one says
    // "replace your flat value", the other says "a key is missing", and a
    // consumer with a published 1.x config needs the first.
    config()->set('kudosity.base_url', 'https://my-proxy.example.com');

    app()->forgetInstance(KudosityV2Connector::class);

    try {
        app(KudosityV2Connector::class);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('is now an array keyed by API version')
            ->and($e->getMessage())->toContain('https://my-proxy.example.com')
            ->and($e->getMessage())->toContain('base_url.v1')
            ->and($e->getMessage())->toContain('base_url.v2');

        return;
    }

    throw new RuntimeException('a flat base_url was accepted');
});

it('diagnoses a genuinely missing version key differently', function () {
    // The other side of the pair, so neither message can drift into the other.
    config()->set('kudosity.base_url', ['v1' => 'https://v1.test']);

    app()->forgetInstance(KudosityV2Connector::class);

    try {
        app(KudosityV2Connector::class);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('base_url.v2')
            ->and($e->getMessage())->toContain('missing or empty')
            ->and($e->getMessage())->not->toContain('is now an array keyed by API version');

        return;
    }

    throw new RuntimeException('a missing base_url.v2 was accepted');
});

// ---------------------------------------------------------------------------
// New config keys
// ---------------------------------------------------------------------------

it('exposes a per-channel sender default and a country code', function () {
    // Each channel needs its own default: an MMS sender must be a number, and
    // an RCS sender is an agent ID rather than a number at all, so one shared
    // `from` cannot serve them.
    expect(config()->has('kudosity.country_code'))->toBeTrue()
        ->and(config()->has('kudosity.mms.sender'))->toBeTrue()
        ->and(config()->has('kudosity.whatsapp.sender'))->toBeTrue()
        ->and(config()->has('kudosity.rcs.agent_id'))->toBeTrue();
});

it('keeps every pre-existing config key', function () {
    // The split must not become a rewrite: a consumer's published config sets
    // these, and losing one silently changes behaviour.
    foreach ([
        'kudosity.api_key',
        'kudosity.api_secret',
        'kudosity.timeout',
        'kudosity.from',
        'kudosity.webhooks.enabled',
        'kudosity.webhooks.prefix',
        'kudosity.webhooks.middleware',
        'kudosity.webhooks.signing_key',
    ] as $key) {
        expect(config()->has($key))->toBeTrue("missing {$key}");
    }
});
