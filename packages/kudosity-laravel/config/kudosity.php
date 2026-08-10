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
    | API Base URLs
    |--------------------------------------------------------------------------
    |
    | Kudosity runs two APIs under one account, on two different hostnames.
    | Neither is a kudosity.com domain, and neither should be "corrected":
    |
    |   v1 - api.transmitsms.com     contact lists, bulk and scheduled sends,
    |                                reporting, balance. Needs key AND secret.
    |   v2 - api.transmitmessage.com single-recipient SMS, MMS, WhatsApp, RCS,
    |                                webhooks, senders. Needs the key only.
    |
    | Override only to point at a proxy or a test double.
    |
    | NOTE: this replaced a single flat 'base_url' string in 2.0. If your
    | published config still has one, the service provider will tell you — it
    | throws rather than guessing, because a stale value points at the V1 host
    | and would send every V2 request to the wrong API.
    |
    */
    'base_url' => [
        'v1' => env('KUDOSITY_BASE_URL_V1', 'https://api.transmitsms.com'),
        'v2' => env('KUDOSITY_BASE_URL_V2', 'https://api.transmitmessage.com'),
    ],

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
    |     "61491570012" — supports two-way messaging (replies).
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
    /*
    |--------------------------------------------------------------------------
    | Default Country Code
    |--------------------------------------------------------------------------
    |
    | Used by the offline phone-number helpers when normalising a local number
    | to E.164. Leave null to require numbers already in international format —
    | the SDK never guesses a country, because guessing wrong sends a real
    | message to the wrong person rather than failing.
    |
    */
    'country_code' => env('KUDOSITY_COUNTRY_CODE'),

    /*
    |--------------------------------------------------------------------------
    | Per-Channel Senders
    |--------------------------------------------------------------------------
    |
    | Each V2 channel needs its own default, because they are not the same kind
    | of value. 'from' above covers SMS. An MMS sender must be a number; a
    | WhatsApp sender must be a registered WhatsApp Business number; and an RCS
    | sender is a registered AGENT ID, not a phone number at all — passing a
    | number there is rejected before the request leaves the process.
    |
    */
    'mms' => [
        'sender' => env('KUDOSITY_MMS_SENDER'),
    ],

    'whatsapp' => [
        'sender' => env('KUDOSITY_WHATSAPP_SENDER'),
    ],

    'rcs' => [
        'agent_id' => env('KUDOSITY_RCS_AGENT_ID'),
    ],

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

        /*
        |----------------------------------------------------------------------
        | V2 Events Receiver
        |----------------------------------------------------------------------
        |
        | One POST route handling all ten V2 event types. This is where delivery
        | status and inbound messages for V2 sends arrive — V2 has no per-send
        | callback URL, so without a registered webhook pointing here, a send
        | migrated from V1 silently stops reporting.
        |
        | The three GET routes above stay live for V1 sends.
        |
        */
        'events' => [
            'enabled' => env('KUDOSITY_WEBHOOKS_EVENTS_ENABLED', true),
            'path' => env('KUDOSITY_WEBHOOKS_EVENTS_PATH', 'events'),
        ],

        /*
        |----------------------------------------------------------------------
        | Registration Writes
        |----------------------------------------------------------------------
        |
        | Which environments may create, replace or delete account-level webhook
        | registrations, via kudosity:webhook:sync, :install and :delete.
        |
        | Webhook registrations belong to the ACCOUNT, not to an app. One
        | Kudosity account backs every environment, and every environment sends
        | from the same sender, so no filter can separate their traffic: a
        | registration made from staging receives production's delivery receipts
        | and inbound replies in full, message bodies and phone numbers
        | included.
        |
        | This list is therefore the only thing preventing that, and it FAILS
        | CLOSED — an empty or absent list refuses every environment, and there
        | is no command-line override. kudosity:webhook:list is read-only and
        | stays ungated.
        |
        */
        'sync' => [
            'environments' => ['production'],
        ],
    ],
];
