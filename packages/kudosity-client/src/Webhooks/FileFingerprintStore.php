<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

use ExpertSystems\Kudosity\Contracts\WebhookFingerprintStore;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use JsonException;

/**
 * A fingerprint store in a JSON file.
 *
 * Shipped because the audience for a dependency-free store is exactly the
 * audience with no cache library — a raw-PHP consumer with a deploy script and
 * a writable directory.
 *
 * Reads are forgiving and writes are not, deliberately. A corrupt or missing
 * file degrades to one extra `GET`, so throwing on read would turn a harmless
 * state into an outage. An unwritable path is a configuration error that would
 * otherwise silently turn a once-per-deploy request into a per-call one, so it
 * throws — and by the time it fires the registration is already correct, with
 * re-running idempotent, so failing loudly costs nothing but attention.
 */
final class FileFingerprintStore implements WebhookFingerprintStore
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function get(string $key): ?string
    {
        $entries = $this->read();

        $value = $entries[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $fingerprint): void
    {
        $entries = $this->read();
        $entries[$key] = $fingerprint;

        try {
            $json = json_encode($entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new KudosityException("Could not encode the webhook fingerprint store: {$e->getMessage()}");
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new KudosityException("Could not create the webhook fingerprint directory: {$directory}");
        }

        if (@file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new KudosityException(
                "Could not write the webhook fingerprint store at {$this->path}. ".
                'It is only an optimisation — drop the store argument to reconcile against the API every time.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A half-written file. One extra GET is the correct cost.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
