<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests\Fixtures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FixturesTest extends TestCase
{
    public function test_it_loads_a_captured_webhook_delivery(): void
    {
        $payload = Fixtures::webhook('sms-status-delivered');

        $this->assertSame('SMS_STATUS', $payload['event_type']);
        $this->assertSame('DELIVERED', $payload['status']['status']);
    }

    public function test_it_names_the_missing_fixture_rather_than_returning_null(): void
    {
        // A typo'd fixture name that silently yields [] makes every assertion
        // against it pass vacuously — the exact defect class this repo keeps
        // producing.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no-such-fixture/');

        Fixtures::webhook('no-such-fixture');
    }

    public function test_every_captured_delivery_is_valid_json_with_an_event_type(): void
    {
        $files = glob(Fixtures::path('V2Webhooks/*.json'));

        $this->assertNotEmpty($files, 'The fixtures did not move, or the path is wrong.');

        foreach ((array) $files as $file) {
            $decoded = json_decode((string) file_get_contents((string) $file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertArrayHasKey('event_type', $decoded, basename((string) $file));
        }
    }
}
