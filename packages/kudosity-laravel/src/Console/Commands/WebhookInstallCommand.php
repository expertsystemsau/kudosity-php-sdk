<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands;

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
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
    /**
     * The handler slot carries this marker rather than a class name.
     *
     * V2 deliveries dispatch Laravel events rather than a handler class, so there
     * is no handler to name. But the signature is only produced when `h` or `c` is
     * present, and the receiver requires `h` — so the slot is used to make the URL
     * signed at all. See WebhookController::events().
     */
    public const HANDLER_MARKER = 'kudosity.v2.events';

    protected $signature = 'kudosity:webhook:install
        {--name= : A name for the registration, 2-100 characters}
        {--event=* : Event types to subscribe to; omit for all ten}
        {--rate-limit= : Max deliveries per second; omit for the system default}
        {--url= : Override the receiver URL entirely, signature included}';

    protected $description = 'Register a Kudosity V2 webhook pointing at this app\'s receiver route';

    public function handle(KudosityClient $client, CallbackUrlBuilder $urls): int
    {
        $url = (string) ($this->option('url') ?: $urls->build(CallbackType::EVENTS, self::HANDLER_MARKER));

        if (! str_starts_with(strtolower($url), 'https://')) {
            // Caught here rather than let through to the request class, so the
            // operator gets an explanation naming the cause rather than a
            // ValidationException about a URL they never typed.
            $this->components->error('The receiver URL must be HTTPS.');
            $this->line(
                "  Resolved: <comment>{$url}</comment>\n".
                '  It comes from APP_URL plus kudosity.webhooks.prefix. Set APP_URL to an https:// address, '.
                "or pass --url= explicitly.\n".
                '  Deliveries carry message content and phone numbers and are unsigned, so a plaintext '.
                'endpoint is readable and forgeable in transit.'
            );

            return self::FAILURE;
        }

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
