<?php

use ExpertSystems\Kudosity\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TransmitSMS Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming callbacks from TransmitSMS for delivery
| receipts (DLR), replies, and link hits.
|
*/

if (config('transmitsms.webhooks.dlr.enabled', true)) {
    Route::get(
        config('transmitsms.webhooks.dlr.path', 'dlr'),
        [WebhookController::class, 'dlr']
    )->name('transmitsms.webhooks.dlr');
}

if (config('transmitsms.webhooks.reply.enabled', true)) {
    Route::get(
        config('transmitsms.webhooks.reply.path', 'reply'),
        [WebhookController::class, 'reply']
    )->name('transmitsms.webhooks.reply');
}

if (config('transmitsms.webhooks.link_hits.enabled', true)) {
    Route::get(
        config('transmitsms.webhooks.link_hits.path', 'link-hits'),
        [WebhookController::class, 'linkHits']
    )->name('transmitsms.webhooks.link-hits');
}
