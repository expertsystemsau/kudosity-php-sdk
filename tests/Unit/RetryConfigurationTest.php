<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;

describe('KudosityV1Connector', function () {
    describe('base URL', function () {
        it('defaults BASE_URL to the live V1 API hostname', function () {
            expect(KudosityV1Connector::BASE_URL)->toBe('https://api.transmitsms.com');
        });

        it('resolves the default hostname when no baseUrl is given to the connector', function () {
            $connector = new KudosityV1Connector('key', 'secret');

            expect($connector->resolveBaseUrl())->toBe('https://api.transmitsms.com');
        });

        it('resolves the default hostname when no baseUrl is given to the client', function () {
            $client = new KudosityClient('key', 'secret');

            expect($client->connector()->resolveBaseUrl())->toBe('https://api.transmitsms.com');
        });
    });

    describe('retry configuration', function () {
        it('configures retry with default values', function () {
            $connector = new KudosityV1Connector('key', 'secret');
            $connector->withRetry();

            expect($connector->tries)->toBe(3);
            expect($connector->retryInterval)->toBe(1000);
            expect($connector->useExponentialBackoff)->toBeTrue();
            expect($connector->throwOnMaxTries)->toBeTrue();
        });

        it('configures retry with custom values', function () {
            $connector = new KudosityV1Connector('key', 'secret');
            $connector->withRetry(
                tries: 5,
                intervalMs: 500,
                useExponentialBackoff: false,
                throwOnMaxTries: false
            );

            expect($connector->tries)->toBe(5);
            expect($connector->retryInterval)->toBe(500);
            expect($connector->useExponentialBackoff)->toBeFalse();
            expect($connector->throwOnMaxTries)->toBeFalse();
        });

        it('returns self for method chaining', function () {
            $connector = new KudosityV1Connector('key', 'secret');

            $result = $connector->withRetry();

            expect($result)->toBe($connector);
        });

        it('disables retry configuration', function () {
            $connector = new KudosityV1Connector('key', 'secret');
            $connector->withRetry(tries: 3);
            $connector->withoutRetry();

            expect($connector->tries)->toBeNull();
            expect($connector->retryInterval)->toBeNull();
            expect($connector->useExponentialBackoff)->toBeNull();
            expect($connector->throwOnMaxTries)->toBeNull();
        });

        it('withoutRetry returns self for method chaining', function () {
            $connector = new KudosityV1Connector('key', 'secret');

            $result = $connector->withoutRetry();

            expect($result)->toBe($connector);
        });
    });
});
