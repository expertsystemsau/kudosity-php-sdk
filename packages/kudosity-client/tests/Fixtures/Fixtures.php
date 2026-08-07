<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests\Fixtures;

use InvalidArgumentException;

/**
 * Captured API artefacts, owned by the package whose API produced them.
 *
 * These are real deliveries and real responses, not examples copied from the
 * documentation — which matters, because several of them contradict it.
 * **Read `V2Webhooks/README.md` before using any of them.**
 *
 * They live here rather than in the monorepo's root `tests/` because
 * `split.yml` publishes only `packages/*`: a consumer of
 * `kudosity-php-client` was previously getting a package whose fixtures sat in
 * a repository they never see. The root Pest suite reads through to this
 * directory via `tests/Fixtures/WebhookPayloads.php`, so there is exactly one
 * copy and a captured payload cannot drift between the two suites.
 */
final class Fixtures
{
    public static function path(string $relative): string
    {
        return __DIR__.'/'.$relative;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When no such fixture exists.
     */
    public static function webhook(string $name): array
    {
        $file = self::path('V2Webhooks/'.$name.'.json');

        if (! is_file($file)) {
            // Named rather than empty: a fixture that silently resolves to []
            // makes every assertion against it pass while testing nothing,
            // which is this repo's most persistent defect class.
            throw new InvalidArgumentException("No such webhook fixture: {$name} (looked in {$file})");
        }

        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When no such fixture exists.
     */
    public static function sender(string $name): array
    {
        $file = self::path('V2Senders/'.$name.'.json');

        if (! is_file($file)) {
            throw new InvalidArgumentException("No such sender fixture: {$name} (looked in {$file})");
        }

        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
}
