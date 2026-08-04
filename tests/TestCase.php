<?php

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Laravel\TransmitSmsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            TransmitSmsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('app.key', 'base64:'.base64_encode('test-app-key-32-bytes-long!!!!'));
        config()->set('transmitsms.api_key', 'test-api-key');
        config()->set('transmitsms.api_secret', 'test-api-secret');
    }
}
