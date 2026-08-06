<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Laravel\KudosityServiceProvider;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;

describe('KudosityServiceProvider', function () {
    describe('service registration', function () {
        it('registers KudosityV1Connector as singleton', function () {
            $connector1 = app(KudosityV1Connector::class);
            $connector2 = app(KudosityV1Connector::class);

            expect($connector1)->toBe($connector2);
            expect($connector1)->toBeInstanceOf(KudosityV1Connector::class);
        });

        it('registers KudosityClient as singleton', function () {
            $client1 = app(KudosityClient::class);
            $client2 = app(KudosityClient::class);

            expect($client1)->toBe($client2);
            expect($client1)->toBeInstanceOf(KudosityClient::class);
        });

        it('creates client from connector', function () {
            $connector = app(KudosityV1Connector::class);
            $client = app(KudosityClient::class);

            expect($client->connector())->toBe($connector);
        });
    });

    describe('aliases', function () {
        it('resolves kudosity alias to client', function () {
            $client = app('kudosity');

            expect($client)->toBeInstanceOf(KudosityClient::class);
            expect($client)->toBe(app(KudosityClient::class));
        });

        it('resolves kudosity.connector alias to connector', function () {
            $connector = app('kudosity.connector');

            expect($connector)->toBeInstanceOf(KudosityV1Connector::class);
            expect($connector)->toBe(app(KudosityV1Connector::class));
        });
    });

    describe('configuration', function () {
        it('uses config values for connector', function () {
            config()->set('kudosity.api_key', 'my-api-key');
            config()->set('kudosity.api_secret', 'my-api-secret');
            // 2.0: base_url is keyed by API version. Setting the old flat string
            // now throws — deliberately, see ServiceProviderV2Test. This test was
            // the first thing that guard caught.
            config()->set('kudosity.base_url', ['v1' => 'https://custom.api.com', 'v2' => 'https://v2.custom.api.com']);
            config()->set('kudosity.timeout', 60);

            // Clear the existing singleton to force re-creation
            app()->forgetInstance(KudosityV1Connector::class);
            app()->forgetInstance(KudosityClient::class);

            $connector = app(KudosityV1Connector::class);

            expect($connector->getApiKey())->toBe('my-api-key');
            expect($connector->getApiSecret())->toBe('my-api-secret');
            expect($connector->getBaseUrl())->toBe('https://custom.api.com');
            expect($connector->getTimeout())->toBe(60);
        });

        it('sets default from when configured', function () {
            config()->set('kudosity.from', 'MyBrand');

            // Clear the existing singleton to force re-creation
            app()->forgetInstance(KudosityV1Connector::class);

            $connector = app(KudosityV1Connector::class);

            expect($connector->getDefaultFrom())->toBe('MyBrand');
        });

        it('does not set default from when empty', function () {
            config()->set('kudosity.from', '');

            // Clear the existing singleton to force re-creation
            app()->forgetInstance(KudosityV1Connector::class);

            $connector = app(KudosityV1Connector::class);

            expect($connector->getDefaultFrom())->toBeNull();
        });
    });

    describe('notification channel', function () {
        it('registers kudosity notification channel', function () {
            /** @var ChannelManager $channelManager */
            $channelManager = Notification::getFacadeRoot();

            $channel = $channelManager->driver('kudosity');

            expect($channel)->toBeInstanceOf(KudosityChannel::class);
        });
    });

    describe('provides', function () {
        it('provides the correct services', function () {
            $provider = app()->getProvider(KudosityServiceProvider::class);

            $provides = $provider->provides();

            expect($provides)->toContain(KudosityClient::class);
            expect($provides)->toContain(KudosityV1Connector::class);
            expect($provides)->toContain('kudosity');
            expect($provides)->toContain('kudosity.connector');
        });
    });
});
