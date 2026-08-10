<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns;

use ExpertSystems\Kudosity\Enums\WebhookEventType;
use Illuminate\Console\Command;

/**
 * Shared by `install` and `sync`, which both take the same `--event=*` option.
 *
 * @phpstan-require-extends Command
 */
trait ResolvesEventTypes
{
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
