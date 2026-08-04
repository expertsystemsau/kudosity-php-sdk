<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kudosity API Key
    |--------------------------------------------------------------------------
    |
    | Your Kudosity API key. You can find this in your Kudosity
    | account settings under API Credentials.
    |
    */
    'api_key' => env('KUDOSITY_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Kudosity API Secret
    |--------------------------------------------------------------------------
    |
    | Your Kudosity API secret. You can find this in your Kudosity
    | account settings under API Credentials.
    |
    */
    'api_secret' => env('KUDOSITY_API_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the Kudosity V1 API (contact lists, bulk and scheduled
    | sends, reporting, balance). Override only to point at a proxy or a test
    | double.
    |
    */
    'base_url' => env('KUDOSITY_BASE_URL', 'https://api.transmitsms.com'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout for API requests in seconds.
    |
    */
    'timeout' => env('KUDOSITY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Default Sender ID
    |--------------------------------------------------------------------------
    |
    | The default sender ID recipients see when sending SMS. Can be overridden
    | per-message. Valid values:
    |
    |   - A dedicated virtual number (VMN) in international format, e.g.
    |     "61412345678" — supports two-way messaging (replies).
    |   - An alphanumeric sender ID ("alpha tag"), e.g. "MyBrand" — max 11
    |     characters, letters and digits only, no spaces. One-way only.
    |   - Empty — Kudosity falls back to a shared number for the
    |     destination country.
    |
    | IMPORTANT: Alpha tags must be registered and approved before use. For
    | Australian numbers, alphanumeric sender IDs must be listed on the ACMA
    | SMS Sender ID Register (enforced from 1 July 2026) or they are shown as
    | "Unverified" to recipients. Register via the Kudosity dashboard first;
    | otherwise leave this empty. See https://www.acma.gov.au/sms-sender-id-register
    |
    */
    'from' => env('KUDOSITY_FROM', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the package handles incoming DLR (Delivery Receipt),
    | Reply, and Link Hit callbacks from Kudosity.
    |
    | When you send an SMS with callback handlers (using onDlr, onReply, or
    | onLinkHit methods), the package automatically generates signed callback
    | URLs. When Kudosity calls these URLs, the package verifies the
    | signature and dispatches your configured handler jobs.
    |
    */
    'webhooks' => [
        /*
        |----------------------------------------------------------------------
        | Enable Webhooks
        |----------------------------------------------------------------------
        |
        | Set to false to disable webhook route registration entirely.
        |
        */
        'enabled' => env('KUDOSITY_WEBHOOKS_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Route Prefix
        |----------------------------------------------------------------------
        |
        | The URL prefix for webhook endpoints. The full URLs will be:
        | - {APP_URL}/webhooks/kudosity/dlr
        | - {APP_URL}/webhooks/kudosity/reply
        | - {APP_URL}/webhooks/kudosity/link-hits
        |
        */
        'prefix' => env('KUDOSITY_WEBHOOKS_PREFIX', 'webhooks/kudosity'),

        /*
        |----------------------------------------------------------------------
        | Middleware
        |----------------------------------------------------------------------
        |
        | Middleware to apply to webhook routes. The 'api' middleware is
        | recommended to disable CSRF verification and session handling.
        |
        */
        'middleware' => ['api'],

        /*
        |----------------------------------------------------------------------
        | Signing Key
        |----------------------------------------------------------------------
        |
        | Secret key used to sign and verify callback URLs. This prevents
        | unauthorized parties from spoofing webhook requests.
        |
        | Defaults to your application's APP_KEY if not specified.
        |
        */
        'signing_key' => env('KUDOSITY_SIGNING_KEY'),

        /*
        |----------------------------------------------------------------------
        | DLR (Delivery Receipt) Callback
        |----------------------------------------------------------------------
        |
        | Configuration for delivery receipt callbacks. These are triggered
        | when a message is delivered, fails, or times out.
        |
        */
        'dlr' => [
            'enabled' => true,
            'path' => 'dlr',
            'queue' => env('KUDOSITY_DLR_QUEUE', 'default'),
        ],

        /*
        |----------------------------------------------------------------------
        | Reply Callback
        |----------------------------------------------------------------------
        |
        | Configuration for reply callbacks. These are triggered when a
        | recipient replies to your SMS message.
        |
        */
        'reply' => [
            'enabled' => true,
            'path' => 'reply',
            'queue' => env('KUDOSITY_REPLY_QUEUE', 'default'),
        ],

        /*
        |----------------------------------------------------------------------
        | Link Hits Callback
        |----------------------------------------------------------------------
        |
        | Configuration for link hit callbacks. These are triggered when a
        | recipient clicks a tracked link in your SMS message.
        |
        */
        'link_hits' => [
            'enabled' => true,
            'path' => 'link-hits',
            'queue' => env('KUDOSITY_LINK_HITS_QUEUE', 'default'),
        ],
    ],
];
