<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Http\Controllers;

use ExpertSystems\Kudosity\Callbacks\CallbackUrlParser;
use ExpertSystems\Kudosity\Data\DlrCallbackData;
use ExpertSystems\Kudosity\Data\LinkHitCallbackData;
use ExpertSystems\Kudosity\Data\ReplyCallbackData;
use ExpertSystems\Kudosity\Exceptions\InvalidSignatureException;
use ExpertSystems\Kudosity\Laravel\Contracts\HandlesDlrCallback;
use ExpertSystems\Kudosity\Laravel\Contracts\HandlesLinkHitCallback;
use ExpertSystems\Kudosity\Laravel\Contracts\HandlesReplyCallback;
use ExpertSystems\Kudosity\Laravel\Events\DlrReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityInboundReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityLinkHitReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityOptOutReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityStatusReceived;
use ExpertSystems\Kudosity\Laravel\Events\LinkHitReceived;
use ExpertSystems\Kudosity\Laravel\Events\ReplyReceived;
use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use ExpertSystems\Kudosity\Webhooks\OptOutEvent;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles incoming webhook callbacks from Kudosity.
 *
 * This controller processes DLR (Delivery Receipt), Reply, and Link Hit
 * callbacks, dispatching events and handler jobs as configured.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected CallbackUrlParser $parser,
    ) {}

    /**
     * Receive a V2 webhook delivery.
     *
     * One route for all ten event types, because one registration can serve every
     * channel. `event_type` decides which typed Laravel event is dispatched.
     *
     * ## Why this returns 200 so readily
     *
     * A non-2xx tells Kudosity the endpoint is unhealthy and earns a retry — which
     * arrives at the same failure. So an event type this SDK does not recognise, and
     * a body that will not parse, are both **logged and accepted**: the delivery is
     * genuinely not retryable, and holding the connection open for it helps nobody.
     * Only a bad signature is refused, with 403.
     *
     * ## Authenticity
     *
     * V2 deliveries carry no signature of any kind — no HMAC, no auth header. The
     * only check available is the unguessable URL we registered, whose signature
     * travels in the query string; V2 preserves it when it POSTs, so the existing
     * {@see CallbackUrlParser} verifies it exactly as it does for V1 callbacks.
     *
     * That authenticates the *endpoint*, not the payload. To establish that a
     * delivery refers to one of your own entities, sign the `message_ref` on the way
     * out and verify it here — see
     * {@see SignedMessageRef}.
     */
    public function events(Request $request): Response
    {
        $query = $request->query->all();

        // Stricter than CallbackUrlParser alone, deliberately.
        //
        // The parser has an "events-only mode": with neither `h` nor `c` present
        // it returns without verifying anything. That is right for the V1 GET
        // routes, where a bare callback URL carries no handler and there is
        // nothing to protect — the payload is the point.
        //
        // It is wrong here. This route's ENTIRE authenticity story is that the URL
        // is unguessable, and its default path (`webhooks/kudosity/events`) is
        // documented. Accepting an unsigned POST would let anyone who read the
        // README forge delivery receipts and inbound messages. So a signature and
        // a handler are both required before the parser is consulted.
        if (! isset($query['s'], $query['h']) || $query['s'] === '' || $query['h'] === '') {
            return response('Invalid signature', 403);
        }

        try {
            $parsed = $this->parser->parse($query);
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }

        $context = $parsed['context'];

        try {
            // No empty-payload guard: an empty or event_type-less body already
            // resolves to an UnknownEvent, which is logged and accepted by the
            // default arm below. A mutation proved an explicit check changed
            // nothing but which log level fired, so it is not carried.
            $event = WebhookEvent::fromArray($request->json()->all());
        } catch (Throwable $e) {
            // A truncated, non-JSON or hostile body. Not retryable, so accept it
            // and leave a trace rather than making Kudosity redeliver garbage.
            Log::warning('Kudosity: could not parse a V2 webhook delivery', [
                'error' => $e->getMessage(),
            ]);

            return response('OK', 200);
        }

        match (true) {
            $event instanceof StatusEvent => KudosityStatusReceived::dispatch($event, $context),
            $event instanceof InboundEvent => KudosityInboundReceived::dispatch($event, $context),
            $event instanceof LinkHitEvent => KudosityLinkHitReceived::dispatch($event, $context),
            $event instanceof OptOutEvent => KudosityOptOutReceived::dispatch($event, $context),
            // An event type Kudosity shipped after this SDK. Logged with the raw
            // payload so it is recoverable, and accepted so the endpoint does not
            // look dead.
            default => Log::info('Kudosity: unrecognised V2 webhook event type', [
                'event_type' => $event->raw['event_type'] ?? null,
                'payload' => $event->raw,
            ]),
        };

        return response('OK', 200);
    }

    /**
     * Handle DLR (Delivery Receipt) callback.
     */
    public function dlr(Request $request): Response
    {
        try {
            $parsed = $this->parser->parse($request->query->all());
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }

        // Create DTO from callback data
        $dlr = DlrCallbackData::fromRequest($request->query->all());

        // Always dispatch event (for global listeners)
        event(new DlrReceived($dlr, $parsed['context']));

        // Dispatch handler job if specified
        if ($parsed['handler'] !== null) {
            if (! $this->dispatchHandler(
                $parsed['handler'],
                $dlr,
                $parsed['context'],
                config('kudosity.webhooks.dlr.queue', 'default'),
                HandlesDlrCallback::class
            )) {
                return response('Handler dispatch failed', 500);
            }
        }

        return response('OK', 200);
    }

    /**
     * Handle Reply callback.
     */
    public function reply(Request $request): Response
    {
        try {
            $parsed = $this->parser->parse($request->query->all());
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }

        // Create DTO from callback data
        $reply = ReplyCallbackData::fromRequest($request->query->all());

        // Always dispatch event (for global listeners)
        event(new ReplyReceived($reply, $parsed['context']));

        // Dispatch handler job if specified
        if ($parsed['handler'] !== null) {
            if (! $this->dispatchHandler(
                $parsed['handler'],
                $reply,
                $parsed['context'],
                config('kudosity.webhooks.reply.queue', 'default'),
                HandlesReplyCallback::class
            )) {
                return response('Handler dispatch failed', 500);
            }
        }

        return response('OK', 200);
    }

    /**
     * Handle Link Hit callback.
     */
    public function linkHits(Request $request): Response
    {
        try {
            $parsed = $this->parser->parse($request->query->all());
        } catch (InvalidSignatureException $e) {
            return response('Invalid signature', 403);
        }

        // Create DTO from callback data
        $linkHit = LinkHitCallbackData::fromRequest($request->query->all());

        // Always dispatch event (for global listeners)
        event(new LinkHitReceived($linkHit, $parsed['context']));

        // Dispatch handler job if specified
        if ($parsed['handler'] !== null) {
            if (! $this->dispatchHandler(
                $parsed['handler'],
                $linkHit,
                $parsed['context'],
                config('kudosity.webhooks.link_hits.queue', 'default'),
                HandlesLinkHitCallback::class
            )) {
                return response('Handler dispatch failed', 500);
            }
        }

        return response('OK', 200);
    }

    /**
     * Dispatch a handler job with the callback data.
     *
     * @param  class-string  $handlerClass
     * @param  array<string, mixed>  $context
     * @param  class-string  $expectedInterface
     * @return bool True if handler was dispatched successfully, false on validation failure
     */
    protected function dispatchHandler(
        string $handlerClass,
        object $callbackData,
        array $context,
        string $queue,
        string $expectedInterface,
    ): bool {
        if (! class_exists($handlerClass)) {
            report(new \RuntimeException("Kudosity callback handler class not found: {$handlerClass}"));

            return false;
        }

        // Validate handler implements the expected interface
        if (! is_a($handlerClass, $expectedInterface, true)) {
            report(new \RuntimeException(
                "Kudosity callback handler {$handlerClass} must implement {$expectedInterface}"
            ));

            return false;
        }

        // Instantiate the job with callback data and context
        $job = new $handlerClass($callbackData, $context);

        // Set queue if the job supports it
        if (method_exists($job, 'onQueue')) {
            $job->onQueue($queue);
        }

        dispatch($job);

        return true;
    }
}
