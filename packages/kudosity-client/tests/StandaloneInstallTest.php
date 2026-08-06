<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The suite's reason for existing, asserted rather than assumed.
 *
 * This package declares `php: ^8.2` and depends on nothing but Saloon. Both
 * claims went untested until this suite: the root Pest suite pulls in Laravel,
 * Testbench and Pest itself, so it could never have noticed the client package
 * reaching for a Laravel helper, and Pest 4 requires PHP >= 8.3, so the declared
 * floor was never executed anywhere.
 */
#[CoversNothing]
final class StandaloneInstallTest extends TestCase
{
    public function test_it_runs_on_the_php_version_the_package_declares(): void
    {
        // Not a tautology: this suite is wired into CI on 8.2, 8.3 and 8.4, and
        // this assertion is what makes the 8.2 job meaningfully different from
        // the others. If someone drops 8.2 from the matrix, coverage of the
        // floor disappears silently — so the constraint is asserted here too.
        $this->assertTrue(
            version_compare(PHP_VERSION, '8.2.0', '>='),
            'The package declares php: ^8.2.',
        );

        $constraint = json_decode(
            (string) file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['require']['php'];

        $this->assertSame(
            '^8.2',
            $constraint,
            'If the floor moves, the CI matrix and this suite have to move with it.',
        );
    }

    public function test_it_loads_the_client_without_laravel_present(): void
    {
        // Deliberately a string, not `Collection::class`. Pint's
        // fully_qualified_strict_types fixer rewrites an inline FQCN into a
        // `use Illuminate\Support\Collection;` import — which still passes,
        // because imports are lazy, but leaves a Laravel import sitting in the
        // one test asserting the package has no Laravel. A string is inert.
        $this->assertFalse(
            class_exists('Illuminate\Support\Collection'),
            'The standalone suite must run without Laravel installed, or it proves nothing about installability.',
        );

        $client = new KudosityClient(apiKey: 'key', apiSecret: 'secret');

        $this->assertInstanceOf(KudosityV1Connector::class, $client->v1());
        $this->assertInstanceOf(KudosityV2Connector::class, $client->v2());
    }

    public function test_the_package_requires_no_framework(): void
    {
        $require = json_decode(
            (string) file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['require'];

        foreach (array_keys($require) as $package) {
            $this->assertDoesNotMatchRegularExpression(
                '#^(laravel|illuminate|orchestra|pestphp)/#',
                (string) $package,
                'The client package is framework-agnostic; a framework dependency belongs in kudosity-laravel.',
            );
        }
    }
}
