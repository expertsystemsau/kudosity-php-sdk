<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel;

use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlParser;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KudosityServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/kudosity.php',
            'kudosity'
        );

        // Register the V1 connector as a singleton
        $this->app->singleton(KudosityV1Connector::class, function ($app) {
            /** @var array{api_key: string, api_secret: string, timeout: int, from: string} $config */
            $config = $app['config']['kudosity'];

            $connector = new KudosityV1Connector(
                apiKey: $config['api_key'],
                apiSecret: $config['api_secret'],
                baseUrl: $this->baseUrlFor($app, 'v1'),
                timeout: (int) $config['timeout'],
            );

            // Set default sender ID if configured
            if (! empty($config['from'])) {
                $connector->setDefaultFrom($config['from']);
            }

            return $connector;
        });

        // Register the V2 connector as a singleton.
        //
        // This has to be explicit: KudosityV2Connector's $apiKey parameter has
        // no default, so the container cannot autowire it, and a consumer
        // type-hinting it would fail at resolution rather than at send time.
        $this->app->singleton(KudosityV2Connector::class, function ($app) {
            /** @var array{api_key: string, timeout: int} $config */
            $config = $app['config']['kudosity'];

            return new KudosityV2Connector(
                apiKey: $config['api_key'],
                baseUrl: $this->baseUrlFor($app, 'v2'),
                timeout: (int) $config['timeout'],
            );
        });

        // Register the client as a singleton, from BOTH configured connectors.
        //
        // fromConnectors(), not fromConnector(): the latter derives a V2
        // connector internally with library defaults, so `kudosity.timeout`
        // reached V1 only and the client's V2 connector was a different
        // instance from the container's.
        $this->app->singleton(KudosityClient::class, function ($app) {
            return KudosityClient::fromConnectors(
                $app->make(KudosityV1Connector::class),
                $app->make(KudosityV2Connector::class),
            );
        });

        // Register the callback URL builder
        $this->app->singleton(CallbackUrlBuilder::class, function ($app) {
            $prefix = $app['config']['kudosity.webhooks.prefix'] ?? 'webhooks/kudosity';
            $appUrl = rtrim(config('app.url'), '/');
            $baseUrl = $appUrl.'/'.ltrim($prefix, '/');
            $signingKey = $this->getSigningKey($app);

            return new CallbackUrlBuilder($baseUrl, $signingKey);
        });

        // Register the callback URL parser
        $this->app->singleton(CallbackUrlParser::class, function ($app) {
            return new CallbackUrlParser($this->getSigningKey($app));
        });

        // Register the notification channel
        $this->app->singleton(KudosityChannel::class, function ($app) {
            return new KudosityChannel(
                $app->make(KudosityClient::class),
                $app->make(CallbackUrlBuilder::class)
            );
        });

        // Create aliases for easier resolution
        $this->app->alias(KudosityClient::class, 'kudosity');
        $this->app->alias(KudosityV1Connector::class, 'kudosity.connector');
        $this->app->alias(KudosityV2Connector::class, 'kudosity.connector.v2');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/kudosity.php' => config_path('kudosity.php'),
            ], 'kudosity-config');
        }

        // Register the notification channel
        Notification::resolved(function (ChannelManager $service) {
            $service->extend('kudosity', function ($app) {
                return $app->make(KudosityChannel::class);
            });
        });

        // Register webhook routes if enabled
        $this->registerWebhookRoutes();
    }

    /**
     * Register the webhook routes.
     */
    protected function registerWebhookRoutes(): void
    {
        if (! $this->app['config']['kudosity.webhooks.enabled']) {
            return;
        }

        $prefix = $this->app['config']['kudosity.webhooks.prefix'] ?? 'webhooks/kudosity';
        $middleware = $this->app['config']['kudosity.webhooks.middleware'] ?? ['api'];

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
            });
    }

    /**
     * Resolve the base URL for one API version.
     *
     * 2.0 replaced a single flat `base_url` string with a `v1`/`v2` pair. A
     * published config file is not re-published on upgrade, so a stale flat
     * value is not hypothetical — and it points at the V1 host, which means
     * silently ignoring it would send every V2 request to the wrong API. Fail
     * instead, naming both replacement keys.
     *
     * @param  Application  $app
     *
     * @throws \RuntimeException If the config still carries a flat base_url
     */
    protected function baseUrlFor($app, string $version): string
    {
        $configured = $app['config']['kudosity.base_url'];

        if (is_string($configured) && $configured !== '') {
            throw new \RuntimeException(
                'Kudosity config `base_url` is now an array keyed by API version. Replace '.
                "`'base_url' => '{$configured}'` with `'base_url' => ['v1' => …, 'v2' => …]` — ".
                'see base_url.v1 and base_url.v2 in the published config, or UPGRADING.md. '.
                'Kudosity runs two APIs on two hostnames, so a single value cannot serve both.'
            );
        }

        $url = $app['config']["kudosity.base_url.{$version}"];

        if (! is_string($url) || $url === '') {
            throw new \RuntimeException(
                "Kudosity config `base_url.{$version}` is missing or empty. Both base_url.v1 and ".
                'base_url.v2 are required; see the published config for the defaults.'
            );
        }

        return $url;
    }

    /**
     * Get the signing key for callback URLs.
     *
     * @param  Application  $app
     *
     * @throws \RuntimeException If no signing key is configured
     */
    protected function getSigningKey($app): string
    {
        $signingKey = $app['config']['kudosity.webhooks.signing_key'];

        if (! empty($signingKey)) {
            return $signingKey;
        }

        // Fall back to APP_KEY
        $appKey = $app['config']['app.key'] ?? '';

        // Remove the base64: prefix if present
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        if (empty($appKey)) {
            throw new \RuntimeException(
                'Kudosity webhook signing key is not configured. '.
                'Set KUDOSITY_SIGNING_KEY in your .env file or ensure APP_KEY is set.'
            );
        }

        return $appKey;
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            KudosityClient::class,
            KudosityV1Connector::class,
            KudosityV2Connector::class,
            KudosityChannel::class,
            CallbackUrlBuilder::class,
            CallbackUrlParser::class,
            'kudosity',
            'kudosity.connector',
            'kudosity.connector.v2',
        ];
    }
}
