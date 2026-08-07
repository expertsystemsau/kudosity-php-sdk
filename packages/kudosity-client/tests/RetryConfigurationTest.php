<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Concerns\HasRetryPolicy;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/RetryConfigurationTest.php.
 *
 * withRetry()/withoutRetry() are declared on the shared HasRetryPolicy trait
 * rather than on the connector class itself, hence the separate CoversTrait.
 *
 * Note: withRetry() genuinely does not retry HTTP failures on this connector
 * — both connectors override getRequestException() to throw a
 * KudosityException outside Saloon's retry-eligible hierarchy, so only a
 * dropped connection retries in practice. This is pre-existing, documented
 * behaviour; these tests assert the configuration surface only.
 */
#[CoversClass(KudosityV1Connector::class)]
#[CoversClass(KudosityClient::class)]
#[CoversTrait(HasRetryPolicy::class)]
final class RetryConfigurationTest extends TestCase
{
    // -----------------------------------------------------------------
    // base URL
    // -----------------------------------------------------------------

    public function test_defaults_base_url_to_the_live_v1_api_hostname(): void
    {
        $this->assertSame('https://api.transmitsms.com', KudosityV1Connector::BASE_URL);
    }

    public function test_resolves_the_default_hostname_when_no_base_url_is_given_to_the_connector(): void
    {
        $connector = new KudosityV1Connector('key', 'secret');

        $this->assertSame('https://api.transmitsms.com', $connector->resolveBaseUrl());
    }

    public function test_resolves_the_default_hostname_when_no_base_url_is_given_to_the_client(): void
    {
        $client = new KudosityClient('key', 'secret');

        $this->assertSame('https://api.transmitsms.com', $client->connector()->resolveBaseUrl());
    }

    // -----------------------------------------------------------------
    // retry configuration
    // -----------------------------------------------------------------

    public function test_configures_retry_with_default_values(): void
    {
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withRetry();

        $this->assertSame(3, $connector->tries);
        $this->assertSame(1000, $connector->retryInterval);
        $this->assertTrue($connector->useExponentialBackoff);
        $this->assertTrue($connector->throwOnMaxTries);
    }

    public function test_configures_retry_with_custom_values(): void
    {
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withRetry(
            tries: 5,
            intervalMs: 500,
            useExponentialBackoff: false,
            throwOnMaxTries: false
        );

        $this->assertSame(5, $connector->tries);
        $this->assertSame(500, $connector->retryInterval);
        $this->assertFalse($connector->useExponentialBackoff);
        $this->assertFalse($connector->throwOnMaxTries);
    }

    public function test_returns_self_for_method_chaining(): void
    {
        $connector = new KudosityV1Connector('key', 'secret');

        $result = $connector->withRetry();

        $this->assertSame($connector, $result);
    }

    public function test_disables_retry_configuration(): void
    {
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withRetry(tries: 3);
        $connector->withoutRetry();

        $this->assertNull($connector->tries);
        $this->assertNull($connector->retryInterval);
        $this->assertNull($connector->useExponentialBackoff);
        $this->assertNull($connector->throwOnMaxTries);
    }

    public function test_without_retry_returns_self_for_method_chaining(): void
    {
        $connector = new KudosityV1Connector('key', 'secret');

        $result = $connector->withoutRetry();

        $this->assertSame($connector, $result);
    }
}
