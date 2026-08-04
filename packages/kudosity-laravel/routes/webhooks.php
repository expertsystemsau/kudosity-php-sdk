<?php

use ExpertSystems\Kudosity\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kudosity Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming callbacks from Kudosity for delivery
| receipts (DLR), replies, and link hits.
|
*/

if (config('kudosity.webhooks.dlr.enabled', true)) {
    Route::get(
        config('kudosity.webhooks.dlr.path', 'dlr'),
        [WebhookController::class, 'dlr']
    )->name('kudosity.webhooks.dlr');
}

if (config('kudosity.webhooks.reply.enabled', true)) {
    Route::get(
        config('kudosity.webhooks.reply.path', 'reply'),
        [WebhookController::class, 'reply']
    )->name('kudosity.webhooks.reply');
}

if (config('kudosity.webhooks.link_hits.enabled', true)) {
    Route::get(
        config('kudosity.webhooks.link_hits.path', 'link-hits'),
        [WebhookController::class, 'linkHits']
    )->name('kudosity.webhooks.link-hits');
}
