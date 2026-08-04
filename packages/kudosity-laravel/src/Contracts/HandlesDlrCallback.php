<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Contracts;

use ExpertSystems\Kudosity\Data\DlrCallbackData;

/**
 * Interface for jobs that handle DLR (Delivery Receipt) callbacks.
 *
 * Implement this interface on your job class to handle delivery receipts
 * for specific messages.
 *
 * Example:
 * ```php
 * class UpdateOrderSmsStatusJob implements HandlesDlrCallback, ShouldQueue
 * {
 *     use Queueable;
 *
 *     public function __construct(
 *         public DlrCallbackData $dlr,
 *         public array $context,
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         $order = Order::find($this->context['order_id']);
 *         $order->update(['sms_status' => $this->dlr->status]);
 *     }
 * }
 * ```
 */
interface HandlesDlrCallback
{
    /**
     * @param  DlrCallbackData  $dlr  The delivery receipt data from Kudosity
     * @param  array<string, mixed>  $context  The context data passed when sending the message
     */
    public function __construct(DlrCallbackData $dlr, array $context);
}
