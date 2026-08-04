<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Contracts;

use ExpertSystems\Kudosity\Data\ReplyCallbackData;

/**
 * Interface for jobs that handle Reply callbacks.
 *
 * Implement this interface on your job class to handle replies
 * for specific messages.
 *
 * Example:
 * ```php
 * class ProcessCustomerReplyJob implements HandlesReplyCallback, ShouldQueue
 * {
 *     use Queueable;
 *
 *     public function __construct(
 *         public ReplyCallbackData $reply,
 *         public array $context,
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         SmsConversation::create([
 *             'order_id' => $this->context['order_id'],
 *             'message' => $this->reply->message,
 *             'mobile' => $this->reply->mobile,
 *         ]);
 *     }
 * }
 * ```
 */
interface HandlesReplyCallback
{
    /**
     * @param  ReplyCallbackData  $reply  The reply data from Kudosity
     * @param  array<string, mixed>  $context  The context data passed when sending the message
     */
    public function __construct(ReplyCallbackData $reply, array $context);
}
