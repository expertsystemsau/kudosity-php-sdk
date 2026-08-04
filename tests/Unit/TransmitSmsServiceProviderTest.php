<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Laravel\Notifications\TransmitSmsChannel;
use ExpertSystems\Kudosity\Laravel\TransmitSmsServiceProvider;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;

describe('TransmitSmsServiceProvider', function () {
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
        it('resolves transmitsms alias to client', function () {
            $client = app('transmitsms');

            expect($client)->toBeInstanceOf(KudosityClient::class);
            expect($client)->toBe(app(KudosityClient::class));
        });

        it('resolves transmitsms.connector alias to connector', function () {
            $connector = app('transmitsms.connector');

            expect($connector)->toBeInstanceOf(KudosityV1Connector::class);
            expect($connector)->toBe(app(KudosityV1Connector::class));
        });
    });

    describe('configuration', function () {
        it('uses config values for connector', function () {
            config()->set('transmitsms.api_key', 'my-api-key');
            config()->set('transmitsms.api_secret', 'my-api-secret');
            config()->set('transmitsms.base_url', 'https://custom.api.com');
            config()->set('transmitsms.timeout', 60);

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
            config()->set('transmitsms.from', 'MyBrand');

            // Clear the existing singleton to force re-creation
            app()->forgetInstance(KudosityV1Connector::class);

            $connector = app(KudosityV1Connector::class);

            expect($connector->getDefaultFrom())->toBe('MyBrand');
        });

        it('does not set default from when empty', function () {
            config()->set('transmitsms.from', '');

            // Clear the existing singleton to force re-creation
            app()->forgetInstance(KudosityV1Connector::class);

            $connector = app(KudosityV1Connector::class);

            expect($connector->getDefaultFrom())->toBeNull();
        });
    });

    describe('notification channel', function () {
        it('registers transmitsms notification channel', function () {
            /** @var ChannelManager $channelManager */
            $channelManager = Notification::getFacadeRoot();

            $channel = $channelManager->driver('transmitsms');

            expect($channel)->toBeInstanceOf(TransmitSmsChannel::class);
        });
    });

    describe('provides', function () {
        it('provides the correct services', function () {
            $provider = app()->getProvider(TransmitSmsServiceProvider::class);

            $provides = $provider->provides();

            expect($provides)->toContain(KudosityClient::class);
            expect($provides)->toContain(KudosityV1Connector::class);
            expect($provides)->toContain('transmitsms');
            expect($provides)->toContain('transmitsms.connector');
        });
    });
});
