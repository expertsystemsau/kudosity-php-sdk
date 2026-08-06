<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use Illuminate\Console\Command;

/**
 * List the account's V2 webhook registrations.
 */
class WebhookListCommand extends Command
{
    protected $signature = 'kudosity:webhook:list';

    protected $description = "List the account's Kudosity V2 webhook registrations";

    public function handle(KudosityClient $client): int
    {
        try {
            $webhooks = $client->webhooks()->all();
        } catch (KudosityException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // A sentence, not an empty table. The API answers `{}` for an account
        // with none, and "no rows" reads like a broken command.
        if ($webhooks === []) {
            $this->components->info('No webhooks registered on this account. Run kudosity:webhook:install to add one.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'URL', 'Events', 'Rate limit', 'TLS'],
            array_map(static fn ($hook): array => [
                $hook->id,
                $hook->name,
                $hook->url,
                $hook->filter->eventType === [] ? 'all' : implode(', ', $hook->filter->eventType),
                $hook->hasRateLimit() ? (string) $hook->rateLimit : 'system default',
                // Surfaced because the platform accepts an http:// registration
                // even though the docs forbid it — one made by another tool can
                // exist, and deliveries are unsigned.
                $hook->isSecure() ? 'yes' : 'NO',
            ], $webhooks),
        );

        foreach ($webhooks as $hook) {
            if (! $hook->isSecure()) {
                $this->components->warn(
                    "Webhook {$hook->id} delivers over plaintext HTTP. Deliveries carry message content and are ".
                    'unsigned, so anyone on the path can read and forge them.'
                );
            }
        }

        return self::SUCCESS;
    }
}
