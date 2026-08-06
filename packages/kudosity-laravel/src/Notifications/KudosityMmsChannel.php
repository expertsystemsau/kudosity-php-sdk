<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The `kudosity-mms` notification channel.
 *
 * Expects `toKudosityMms()` on the notification, returning a
 * {@see KudosityMmsMessage}.
 *
 * V2 only — there is no V1 MMS send — so unlike {@see KudosityChannel} there is
 * no routing decision here.
 */
class KudosityMmsChannel
{
    public function __construct(
        protected KudosityClient $client,
    ) {}

    /**
     * @param  mixed  $notifiable
     * @return MmsMessageData|null Null when no recipient could be resolved.
     *
     * @throws KudosityException
     */
    public function send($notifiable, Notification $notification): ?MmsMessageData
    {
        /** @var KudosityMmsMessage $message */
        $message = $notification->toKudosityMms($notifiable);

        $to = $message->getTo() ?? $notifiable->routeNotificationFor('kudosity-mms', $notification);

        if (! $to) {
            return null;
        }

        // Message wins over config, matching the SMS channel. The MMS default is
        // its own key rather than `kudosity.from`, because an alphanumeric sender
        // that works for SMS is not a valid MMS sender.
        $from = $message->getFrom() ?? Config::get('kudosity.mms.sender') ?? Config::get('kudosity.from');

        $message->assertSendable();

        return $this->client->mms()->send(
            to: $to,
            from: (string) $from,
            contentUrls: $message->getContentUrls(),
            subject: $message->getSubject(),
            message: $message->getContent(),
            messageRef: $message->getMessageRef(),
            trackLinks: $message->getTrackLinks(),
        );
    }
}
