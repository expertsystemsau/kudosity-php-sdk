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
use ExpertSystems\Kudosity\Laravel\Events\LinkHitReceived;
use ExpertSystems\Kudosity\Laravel\Events\ReplyReceived;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Handles incoming webhook callbacks from TransmitSMS.
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
                config('transmitsms.webhooks.dlr.queue', 'default'),
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
                config('transmitsms.webhooks.reply.queue', 'default'),
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
                config('transmitsms.webhooks.link_hits.queue', 'default'),
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
            report(new \RuntimeException("TransmitSMS callback handler class not found: {$handlerClass}"));

            return false;
        }

        // Validate handler implements the expected interface
        if (! is_a($handlerClass, $expectedInterface, true)) {
            report(new \RuntimeException(
                "TransmitSMS callback handler {$handlerClass} must implement {$expectedInterface}"
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
