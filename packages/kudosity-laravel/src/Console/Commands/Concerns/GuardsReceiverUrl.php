<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Console\Commands\Concerns;

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use Illuminate\Console\Command;

/**
 * Resolve and vet the URL a webhook registration should point at.
 *
 * Shared by `install` and `sync` rather than duplicated, because a divergence
 * between them is invisible: `sync` would register a URL `install` would have
 * rejected, and the receiver would then refuse every delivery with a 403 that
 * Kudosity has no way of reporting back.
 *
 * @phpstan-require-extends Command
 */
trait GuardsReceiverUrl
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

    /**
     * The vetted receiver URL, or null when it was rejected.
     *
     * @return array{url: string, allowInsecure: bool}|null
     */
    protected function resolveReceiverUrl(CallbackUrlBuilder $urls): ?array
    {
        /** @var string|null $override */
        $override = $this->option('url');

        $url = (string) ($override ?: $urls->build(CallbackType::EVENTS, self::HANDLER_MARKER));

        // Plaintext is allowed only on a local environment. Laravel knows which
        // environment it is; the client package does not, which is why the
        // decision is made here and passed down explicitly.
        $allowInsecure = app()->environment('local') && str_starts_with(strtolower($url), 'http://');

        if ($allowInsecure) {
            $this->components->warn(
                'Registering a plaintext http:// receiver because APP_ENV=local. Deliveries carry message '.
                'content and are unsigned, so never do this outside local development.'
            );
        }

        if (! $allowInsecure && ! str_starts_with(strtolower($url), 'https://')) {
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

            return null;
        }

        return ['url' => $url, 'allowInsecure' => $allowInsecure];
    }
}
