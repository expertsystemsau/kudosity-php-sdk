<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Contracts\SentMessage;
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
     * Routes to V2 by default and to V1 only when the message uses something V2
     * cannot express — see {@see KudosityMessage::apiVersion()}, which also
     * reports the decision and the reasons behind it.
     *
     * The return type is {@see SentMessage} rather than either concrete DTO,
     * because the routing decision is made here rather than by the caller: a
     * caller reading `->id` on a concrete type would break the first time
     * somebody added `sendAt()` to a notification.
     *
     * @param  mixed  $notifiable
     * @return SentMessage|null The send response, or null if no recipient
     *
     * @throws KudosityException
     */
    public function send($notifiable, Notification $notification): ?SentMessage
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
            // apiVersion() throws when forceV2() cannot be honoured, so this sits
            // inside the try and surfaces as a KudosityException like every other
            // validation failure in this method.
            if ($message->apiVersion() === ApiVersion::V2 && $to !== null) {
                return $this->sendViaV2($message, $to);
            }
        } catch (ValidationException $e) {
            throw new KudosityException(
                $e->getMessage(),
                $e->getCode(),
                $e,
                $e->getErrorCode()
            );
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
        return $this->client->bulk()->sendRequest($request);
    }

    /**
     * Send over `POST /v2/sms`.
     *
     * Only reached for a message with no V1-only options, so nothing here needs to
     * consider scheduling, lists, validity or callbacks — the routing decision has
     * already established none are set. That is why this is short: the complexity
     * lives in the decision, not the send.
     *
     * @throws KudosityException
     */
    protected function sendViaV2(KudosityMessage $message, string $to): SentMessage
    {
        $from = $message->getFrom() ?? Config::get('kudosity.from');

        return $this->client->sms()->send(
            message: $message->getContent(),
            to: trim($to),
            from: (string) $from,
            messageRef: null,
            trackLinks: $message->getTrackedLinkUrl() !== null,
        );
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
