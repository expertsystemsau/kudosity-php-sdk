<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands;

use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\GuardsEnvironment;
use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\GuardsReceiverUrl;
use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\ResolvesEventTypes;
use Illuminate\Console\Command;

/**
 * Converge the account on one webhook registration pointing at this app.
 *
 * The declarative counterpart to `install`, and the one to put in a deploy
 * script: running it twice registers one webhook, not two.
 *
 * It also repairs what a presence check cannot see. The receiver URL carries an
 * HMAC signature, so rotating `KUDOSITY_SIGNING_KEY` or `APP_KEY`, changing
 * `kudosity.webhooks.prefix`, or moving `APP_URL` leaves a registration that
 * still exists and still receives deliveries — every one of which the receiver
 * then rejects with a 403 that Kudosity has no way of reporting back to you.
 */
class WebhookSyncCommand extends Command
{
    use GuardsEnvironment;
    use GuardsReceiverUrl;
    use ResolvesEventTypes;

    protected $signature = 'kudosity:webhook:sync
        {--name= : A name for the registration, 2-100 characters}
        {--event=* : Event types to subscribe to; omit for all ten}
        {--rate-limit= : Max deliveries per second; omit for the system default}
        {--url= : Override the receiver URL entirely, signature included}';

    protected $description = 'Reconcile this app\'s Kudosity V2 webhook registration, creating or repairing as needed';

    public function handle(KudosityClient $client, CallbackUrlBuilder $urls): int
    {
        if (! $this->environmentPermitsWrites()) {
            return self::FAILURE;
        }

        $receiver = $this->resolveReceiverUrl($urls);

        if ($receiver === null) {
            return self::FAILURE;
        }

        $events = $this->resolveEvents();

        if ($events === null) {
            return self::FAILURE;
        }

        try {
            $result = $client->webhooks()->ensure(
                name: (string) ($this->option('name') ?: config('app.name').' Kudosity events'),
                url: $receiver['url'],
                eventTypes: $events,
                rateLimit: $this->option('rate-limit') !== null ? (int) $this->option('rate-limit') : null,
                allowInsecureUrl: $receiver['allowInsecure'],
            );
        } catch (KudosityException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $hook = $result->webhook;

        match ($result->action) {
            EnsureAction::Created => $this->components->info("Created webhook {$hook?->id}"),
            // Both URLs, because an operator seeing "Updated" cannot otherwise tell
            // what drifted — a rotated signing key looks identical to a moved route.
            EnsureAction::Updated => $this->components->info(
                "Repaired webhook {$hook?->id}\n  Now: ".($hook->url ?? '')
            ),
            EnsureAction::Unchanged => $this->components->info("Webhook {$hook?->id} is already correct"),
            // Unreachable from this command, which passes no fingerprint store.
            EnsureAction::Skipped => $this->components->info('Skipped: a stored fingerprint already matched'),
        };

        if ($result->duplicates !== []) {
            $ids = implode(', ', array_map(static fn (WebhookData $d): string => $d->id, $result->duplicates));

            // Two calls, not one with an embedded newline: components->warn() renders
            // its argument as a single block, so a second sentence folded into the same
            // string would just be flowed onto the same line rather than kept distinct.
            $this->components->warn("Other registrations point at the same receiver: {$ids}");
            $this->line(
                '  Nothing was deleted — each will deliver its own copy of every event. '.
                'Remove the redundant ones with kudosity:webhook:delete once you have checked which is which.'
            );
        }

        return self::SUCCESS;
    }
}
