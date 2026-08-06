<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The `kudosity-rcs` notification channel.
 *
 * Expects `toKudosityRcs()` on the notification, returning a
 * {@see KudosityRcsMessage}.
 *
 * **The sender is a registered agent ID, not a phone number**, taken from the
 * message or `kudosity.rcs.agent_id`. A phone-number-shaped value is rejected
 * before the request leaves the process, wherever it came from.
 */
class KudosityRcsChannel
{
    public function __construct(
        protected KudosityClient $client,
    ) {}

    /**
     * @param  mixed  $notifiable
     * @return RcsMessageData|null Null when no recipient could be resolved.
     *
     * @throws KudosityException
     */
    public function send($notifiable, Notification $notification): ?RcsMessageData
    {
        /** @var KudosityRcsMessage $message */
        $message = $notification->toKudosityRcs($notifiable);

        $to = $message->getTo() ?? $notifiable->routeNotificationFor('kudosity-rcs', $notification);

        if (! $to) {
            return null;
        }

        $configured = Config::get('kudosity.rcs.agent_id');
        $configured = is_string($configured) ? $configured : null;

        // Throws with a message naming the config key, rather than letting a null
        // reach the request and surface as a type error.
        $message->assertSendable($configured);

        return $this->client->rcs()->send(
            message: $message->getContent(),
            to: $to,
            agentId: (string) ($message->getAgentId() ?? $configured),
            fallback: $message->getFallback(),
            messageRef: $message->getMessageRef(),
        );
    }
}
