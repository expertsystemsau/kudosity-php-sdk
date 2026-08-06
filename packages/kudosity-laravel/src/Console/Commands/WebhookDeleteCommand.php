<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use Illuminate\Console\Command;

/**
 * Delete a V2 webhook registration.
 */
class WebhookDeleteCommand extends Command
{
    protected $signature = 'kudosity:webhook:delete {id : The registration id} {--force : Skip the confirmation}';

    protected $description = 'Delete a Kudosity V2 webhook registration';

    public function handle(KudosityClient $client): int
    {
        $id = (string) $this->argument('id');

        // Destructive and not undoable: deleting the only registration stops all
        // V2 delivery reporting, silently, until another is installed.
        if (! $this->option('force') && ! $this->confirm("Delete webhook {$id}? V2 delivery reports and inbound messages will stop arriving.")) {
            $this->components->info('Left alone.');

            return self::SUCCESS;
        }

        try {
            $client->webhooks()->delete($id);
        } catch (KudosityException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Deleted webhook {$id}");

        return self::SUCCESS;
    }
}
