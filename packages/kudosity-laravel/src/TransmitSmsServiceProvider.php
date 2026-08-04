<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel;

use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlParser;
use ExpertSystems\Kudosity\Laravel\Notifications\TransmitSmsChannel;
use ExpertSystems\Kudosity\TransmitSmsClient;
use ExpertSystems\Kudosity\TransmitSmsConnector;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TransmitSmsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/transmitsms.php',
            'transmitsms'
        );

        // Register the connector as a singleton
        $this->app->singleton(TransmitSmsConnector::class, function ($app) {
            /** @var array{api_key: string, api_secret: string, base_url: string, timeout: int, from: string} $config */
            $config = $app['config']['transmitsms'];

            $connector = new TransmitSmsConnector(
                apiKey: $config['api_key'],
                apiSecret: $config['api_secret'],
                baseUrl: $config['base_url'],
                timeout: (int) $config['timeout'],
            );

            // Set default sender ID if configured
            if (! empty($config['from'])) {
                $connector->setDefaultFrom($config['from']);
            }

            return $connector;
        });

        // Register the client as a singleton, using the connector
        $this->app->singleton(TransmitSmsClient::class, function ($app) {
            return TransmitSmsClient::fromConnector(
                $app->make(TransmitSmsConnector::class)
            );
        });

        // Register the callback URL builder
        $this->app->singleton(CallbackUrlBuilder::class, function ($app) {
            $prefix = $app['config']['transmitsms.webhooks.prefix'] ?? 'webhooks/transmitsms';
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
        $this->app->singleton(TransmitSmsChannel::class, function ($app) {
            return new TransmitSmsChannel(
                $app->make(TransmitSmsClient::class),
                $app->make(CallbackUrlBuilder::class)
            );
        });

        // Create aliases for easier resolution
        $this->app->alias(TransmitSmsClient::class, 'transmitsms');
        $this->app->alias(TransmitSmsConnector::class, 'transmitsms.connector');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/transmitsms.php' => config_path('transmitsms.php'),
            ], 'transmitsms-config');
        }

        // Register the notification channel
        Notification::resolved(function (ChannelManager $service) {
            $service->extend('transmitsms', function ($app) {
                return $app->make(TransmitSmsChannel::class);
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
        if (! $this->app['config']['transmitsms.webhooks.enabled']) {
            return;
        }

        $prefix = $this->app['config']['transmitsms.webhooks.prefix'] ?? 'webhooks/transmitsms';
        $middleware = $this->app['config']['transmitsms.webhooks.middleware'] ?? ['api'];

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
            });
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
        $signingKey = $app['config']['transmitsms.webhooks.signing_key'];

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
                'TransmitSMS webhook signing key is not configured. '.
                'Set TRANSMITSMS_SIGNING_KEY in your .env file or ensure APP_KEY is set.'
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
            TransmitSmsClient::class,
            TransmitSmsConnector::class,
            TransmitSmsChannel::class,
            CallbackUrlBuilder::class,
            CallbackUrlParser::class,
            'transmitsms',
            'transmitsms.connector',
        ];
    }
}
