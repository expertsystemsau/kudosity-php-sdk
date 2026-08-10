<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands;

use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\GuardsEnvironment;
use ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns\GuardsReceiverUrl;
use Illuminate\Console\Command;

/**
 * Register a V2 webhook pointing at this application's own receiver route.
 *
 * The URL is built through {@see CallbackUrlBuilder}, which is not a convenience:
 * the receiver refuses an unsigned request with a 403, so a hand-assembled URL
 * produces a registration that can never deliver — and it fails silently, because
 * Kudosity has no way to tell you your endpoint is rejecting it.
 */
class WebhookInstallCommand extends Command
{
    use GuardsEnvironment;
    use GuardsReceiverUrl;

    protected $signature = 'kudosity:webhook:install
        {--name= : A name for the registration, 2-100 characters}
        {--event=* : Event types to subscribe to; omit for all ten}
        {--rate-limit= : Max deliveries per second; omit for the system default}
        {--url= : Override the receiver URL entirely, signature included}';

    protected $description = 'Register a Kudosity V2 webhook pointing at this app\'s receiver route';

    public function handle(KudosityClient $client, CallbackUrlBuilder $urls): int
    {
        if (! $this->environmentPermitsWrites()) {
            return self::FAILURE;
        }

        $receiver = $this->resolveReceiverUrl($urls);

        if ($receiver === null) {
            return self::FAILURE;
        }

        ['url' => $url, 'allowInsecure' => $allowInsecure] = $receiver;

        $events = $this->resolveEvents();

        if ($events === null) {
            return self::FAILURE;
        }

        try {
            $hook = $client->webhooks()->create(
                name: (string) ($this->option('name') ?: config('app.name').' Kudosity events'),
                url: $url,
                eventTypes: $events,
                rateLimit: $this->option('rate-limit') !== null ? (int) $this->option('rate-limit') : null,
                allowInsecureUrl: $allowInsecure,
            );
        } catch (KudosityException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Registered webhook {$hook->id}");
        $this->line('  URL:    '.$hook->url);
        $this->line('  Events: '.($hook->filter->eventType === [] ? 'all' : implode(', ', $hook->filter->eventType)));

        return self::SUCCESS;
    }

    /**
     * Resolve --event values to enum cases, or null when one is unrecognised.
     *
     * Rejected rather than passed through: an event type the API does not know is
     * silently ignored, so the registration would look correct and deliver
     * nothing.
     *
     * @return array<int, WebhookEventType>|null
     */
    protected function resolveEvents(): ?array
    {
        $events = [];

        /** @var array<int, string> $requested */
        $requested = (array) $this->option('event');

        foreach ($requested as $name) {
            $type = WebhookEventType::fromApi($name);

            if ($type === WebhookEventType::Unknown) {
                $this->components->error("Unrecognised event type: {$name}");
                $this->line('  Valid types: '.implode(', ', array_map(
                    static fn (WebhookEventType $t): string => $t->value,
                    array_filter(
                        WebhookEventType::cases(),
                        static fn (WebhookEventType $t): bool => $t !== WebhookEventType::Unknown,
                    ),
                )));

                return null;
            }

            $events[] = $type;
        }

        return $events;
    }
}
