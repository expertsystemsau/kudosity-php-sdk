<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The `kudosity-whatsapp` notification channel.
 *
 * Expects `toKudosityWhatsApp()` on the notification, returning a
 * {@see KudosityWhatsAppMessage}.
 *
 * **Free-form text only delivers inside the 24-hour service window.** This channel
 * cannot detect which side of it you are on — that depends on inbound history the
 * API does not expose here — so a notification that might be first contact should
 * use `template()`. See {@see KudosityWhatsAppMessage}.
 */
class KudosityWhatsAppChannel
{
    public function __construct(
        protected KudosityClient $client,
    ) {}

    /**
     * @param  mixed  $notifiable
     * @return WhatsAppMessageData|null Null when no recipient could be resolved.
     *
     * @throws KudosityException
     */
    public function send($notifiable, Notification $notification): ?WhatsAppMessageData
    {
        /** @var KudosityWhatsAppMessage $message */
        $message = $notification->toKudosityWhatsApp($notifiable);

        $to = $message->getTo() ?? $notifiable->routeNotificationFor('kudosity-whatsapp', $notification);

        if (! $to) {
            return null;
        }

        $message->assertSendable();

        // Omitted rather than defaulted to kudosity.from: WhatsApp needs a
        // registered WhatsApp Business number, and sending an SMS sender ID would
        // be rejected. Null lets the account default apply, which is the API's
        // own behaviour.
        $from = $message->getFrom() ?? Config::get('kudosity.whatsapp.sender');

        $content = $message->getContent();

        // Cannot be null — assertSendable() above guarantees it — but PHPStan
        // cannot see that across a method call.
        if ($content === null) {
            return null;
        }

        return $this->client->whatsapp()->send(
            content: $content,
            to: $to,
            from: is_string($from) && $from !== '' ? $from : null,
            fallback: $message->getFallback(),
            messageRef: $message->getMessageRef(),
        );
    }
}
