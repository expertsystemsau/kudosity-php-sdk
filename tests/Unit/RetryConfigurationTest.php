<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\TransmitSmsConnector;

describe('TransmitSmsConnector', function () {
    describe('retry configuration', function () {
        it('configures retry with default values', function () {
            $connector = new TransmitSmsConnector('key', 'secret');
            $connector->withRetry();

            expect($connector->tries)->toBe(3);
            expect($connector->retryInterval)->toBe(1000);
            expect($connector->useExponentialBackoff)->toBeTrue();
            expect($connector->throwOnMaxTries)->toBeTrue();
        });

        it('configures retry with custom values', function () {
            $connector = new TransmitSmsConnector('key', 'secret');
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
            $connector = new TransmitSmsConnector('key', 'secret');

            $result = $connector->withRetry();

            expect($result)->toBe($connector);
        });

        it('disables retry configuration', function () {
            $connector = new TransmitSmsConnector('key', 'secret');
            $connector->withRetry(tries: 3);
            $connector->withoutRetry();

            expect($connector->tries)->toBeNull();
            expect($connector->retryInterval)->toBeNull();
            expect($connector->useExponentialBackoff)->toBeNull();
            expect($connector->throwOnMaxTries)->toBeNull();
        });

        it('withoutRetry returns self for method chaining', function () {
            $connector = new TransmitSmsConnector('key', 'secret');

            $result = $connector->withoutRetry();

            expect($result)->toBe($connector);
        });
    });
});
