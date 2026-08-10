<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns;

use Illuminate\Console\Command;

/**
 * Refuse to write account-level webhook registrations from the wrong environment.
 *
 * Webhook registrations are **account-level**, and one Kudosity account backs
 * every environment here. A registration made from staging therefore receives
 * the whole account's events — every production delivery receipt and inbound
 * reply, with message bodies and phone numbers.
 *
 * No filter can prevent it. `filter.sender` would partition the traffic, but
 * every environment sends from the same sender; `messageRef` is an exact-match
 * list rather than a prefix; and `campaign_id` is not settable on a send. So
 * this gate is the only control, which is why it **fails closed and has no
 * override flag**. An absent or empty allowlist refuses.
 *
 * @phpstan-require-extends Command
 */
trait GuardsEnvironment
{
    /**
     * Whether this environment may write to the account, explaining any refusal.
     */
    protected function environmentPermitsWrites(): bool
    {
        /** @var array<int, string> $permitted */
        $permitted = (array) config('kudosity.webhooks.sync.environments', []);

        $current = (string) app()->environment();

        if (in_array($current, $permitted, true)) {
            return true;
        }

        $this->components->error("Refusing to write webhook registrations from the {$current} environment.");
        $this->line(
            "  Webhook registrations are account-level, and one Kudosity account backs every\n".
            "  environment — so a registration made here would receive production's delivery\n".
            "  receipts and inbound replies, message bodies and phone numbers included.\n".
            '  Permitted: <comment>'.($permitted === [] ? 'none' : implode(', ', $permitted))."</comment>\n".
            '  Set <comment>kudosity.webhooks.sync.environments</comment> if this environment '.
            'genuinely should own the account webhooks.'
        );

        return false;
    }
}
