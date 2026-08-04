<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class KudosityChannel
{
    public function __construct(
        protected KudosityClient $client,
        protected ?CallbackUrlBuilder $urlBuilder = null,
    ) {}

    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @return SmsData|null The SMS data response, or null if no recipient
     *
     * @throws KudosityException
     */
    public function send($notifiable, Notification $notification): ?SmsData
    {
        /** @var KudosityMessage|string $message */
        $message = $notification->toKudosity($notifiable);

        if (is_string($message)) {
            $message = new KudosityMessage($message);
        }

        $listId = $message->getListId();
        $to = $message->getTo() ?? $notifiable->routeNotificationFor('kudosity', $notification);

        // A list send doesn't need a resolved recipient; a direct send does.
        if ($listId === null && ! $to) {
            return null;
        }

        try {
            // Build the SendSmsRequest (may throw ValidationException)
            $request = new SendSmsRequest($message->getContent());

            if ($listId !== null) {
                $request->toList($listId);
            } elseif ($to !== null) {
                $request->to($to);
            }

            // Apply sender ID: message > config > default
            $from = $message->getFrom() ?? Config::get('kudosity.from');
            if ($from !== null && $from !== '') {
                $request->from($from);
            }

            // Apply scheduled send time if set
            if ($message->getSendAt() !== null) {
                $request->scheduledAt($message->getSendAt());
            }

            // Apply additional message options
            if ($message->getValidity() !== null) {
                $request->validity($message->getValidity());
            }

            if ($message->getCountryCode() !== null) {
                $request->countryCode($message->getCountryCode());
            }

            if ($message->getFormatNumbers()) {
                $request->formatNumbers();
            }

            if ($message->getRepliesToEmail() !== null) {
                $request->repliesToEmail($message->getRepliesToEmail());
            }

            if ($message->getTrackedLinkUrl() !== null) {
                $request->trackedLinkUrl($message->getTrackedLinkUrl());
            }

            // Apply callback URLs - handlers take precedence over explicit URLs
            $this->applyCallbackUrls($request, $message);

        } catch (ValidationException $e) {
            // Re-throw validation errors as KudosityException for consistent error handling
            throw new KudosityException(
                $e->getMessage(),
                $e->getCode(),
                $e,
                $e->getErrorCode()
            );
        }

        // Send the request and return the DTO
        return $this->client->sms()->sendRequest($request);
    }

    /**
     * Apply callback URLs to the request.
     *
     * If a handler is specified (via onDlr, onReply, onLinkHit), a signed URL
     * is generated. Otherwise, the explicit callback URL is used if set.
     */
    protected function applyCallbackUrls(SendSmsRequest $request, KudosityMessage $message): void
    {
        // DLR callback
        if ($message->getDlrHandler() !== null && $this->urlBuilder !== null) {
            $request->dlrCallback(
                $this->urlBuilder->build(
                    CallbackType::DLR,
                    $message->getDlrHandler(),
                    $message->getDlrContext()
                )
            );
        } elseif ($message->getDlrCallback() !== null) {
            $request->dlrCallback($message->getDlrCallback());
        }

        // Reply callback
        if ($message->getReplyHandler() !== null && $this->urlBuilder !== null) {
            $request->replyCallback(
                $this->urlBuilder->build(
                    CallbackType::REPLY,
                    $message->getReplyHandler(),
                    $message->getReplyContext()
                )
            );
        } elseif ($message->getReplyCallback() !== null) {
            $request->replyCallback($message->getReplyCallback());
        }

        // Link hits callback
        if ($message->getLinkHitHandler() !== null && $this->urlBuilder !== null) {
            $request->linkHitsCallback(
                $this->urlBuilder->build(
                    CallbackType::LINK_HITS,
                    $message->getLinkHitHandler(),
                    $message->getLinkHitContext()
                )
            );
        } elseif ($message->getLinkHitsCallback() !== null) {
            $request->linkHitsCallback($message->getLinkHitsCallback());
        }
    }
}
