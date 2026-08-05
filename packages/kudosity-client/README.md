# Kudosity PHP Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)
[![Total Downloads](https://img.shields.io/packagist/dt/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)
[![License](https://img.shields.io/packagist/l/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)

A framework-agnostic PHP client for the [Kudosity API](https://kudosity.com/).
This is the 2.x line — see [UPGRADING.md](../../UPGRADING.md) if you're
migrating from 1.x. V1 (contact lists, bulk/list sends, scheduling, reporting)
is covered first below; V2 (single-recipient SMS, MMS, WhatsApp, RCS) has its
own [V2 channels](#v2-channels) section further down.

## Installation

```bash
composer require expertsystemsau/kudosity-php-client
```

## Usage

The client is resource-based. `$client->sms()` is Kudosity's V2 endpoint
(`POST /v2/sms`), which has no support for multiple recipients, contact
lists, or scheduling — those sends live on `$client->bulk()` (V1) instead;
see [V2 channels](#v2-channels) below for `sms()` and the other three V2
channels. Account operations live on `$client->account()`, reporting on
`$client->reporting()`, and contact lists on `$client->lists()`.

```php
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;

$client = new KudosityClient('your-api-key', 'your-api-secret');

// Send an SMS — send(string $message, string $to, ?string $from = null, ?callable $configure = null)
$sms = $client->bulk()->send('Hello from Kudosity!', '+61400000000');
$messageId = $sms->messageId;

// Send to multiple recipients (comma-separated, up to 500)
$client->bulk()->send('Bulk message', '+61400000000,+61400000001');

// Extra options (replies-to-email, callbacks, scheduling, validity) — pass a
// configure closure. Connector defaults still apply, unlike sendRequest().
$client->bulk()->send('Hello!', '+61400000000', configure: fn (SendSmsRequest $r) =>
    $r->repliesToEmail('inbox@example.com')->validity(60)
);

// Full control with no connector defaults applied — build a request yourself
$request = (new SendSmsRequest('Scheduled message'))
    ->to('+61400000000')
    ->from('MySenderID')
    ->scheduledAt('2026-12-25 09:00:00');
$client->bulk()->sendRequest($request);

// Check a message's status / delivery stats
$message = $client->reporting()->getMessage($messageId);
$stats = $client->reporting()->getStats($messageId);

// Get account balance
$balance = $client->account()->getBalance();

// Get SMS replies (responses)
$replies = $client->reporting()->getAllResponses();

// Manage contact lists
$lists = $client->lists()->all();
$client->lists()->addContact(123, '+61400000000', firstName: 'John');
```

## Pagination

List endpoints (`numbers()->all()`, `lists()->all()`, `keywords()->all()`,
`reporting()->getSent()`, `reporting()->getUserSent()`, `lists()->getContacts()`,
`reporting()->getResponses()`/`getAllResponses()`) return a paginator that lazily walks
every page. Use `items()` to iterate individual records:

```php
foreach ($client->numbers()->all()->items() as $number) {
    echo $number['number'].PHP_EOL;
}

// Or collect across pages, tuning page size and page count
$members = $client->lists()->getContacts($listId)
    ->setPerPageLimit(100)
    ->setMaxPages(5)
    ->collect()
    ->all();
```

Each endpoint's response envelope uses a different item key (`numbers`, `lists`,
`recipients`, `messages`, `members`, `responses`, …); the paginator resolves the
correct key per request automatically.

## Sender IDs

The `from` value (per-message, or the connector default via `setDefaultFrom()`) is the
sender ID recipients see. It can be:

- A **dedicated virtual number (VMN)** in international format, e.g. `61412345678` —
  supports two-way messaging (recipients can reply).
- An **alphanumeric sender ID** ("alpha tag") such as `MyBrand` — max 11 characters,
  letters and digits only, no spaces (validate with `$client->bulk()->isValidSenderId()`).
  One-way only; recipients cannot reply.
- **Omitted** — Kudosity falls back to a shared number for the destination country.

There is no `from` argument on the constructor. Set it one of two ways:

```php
$client = new KudosityClient('your-api-key', 'your-api-secret');

// 1. Per message — the third argument to send() overrides any default.
//    send(string $message, string $to, ?string $from = null, ?callable $configure = null)
$client->bulk()->send('Hello!', '+61400000000', 'MyBrand');

// 2. A default sender ID applied to every send()/sendToList() call, set on
//    the connector. Optionally set a default country code used to normalise
//    local numbers before sending.
$client->connector()->setDefaultFrom('MyBrand');
$client->connector()->setDefaultCountryCode('AU');

$client->bulk()->send('Hello!', '+61400000000'); // uses "MyBrand"

// Validate a value before you rely on it
if (! $client->bulk()->isValidSenderId('MyBrand')) {
    // reject / fall back to a shared number
}
```

> Note: `$client->bulk()->sendRequest(SendSmsRequest $request)` does **not** apply
> these connector defaults — set `from` on the request yourself when using it.

> ⚠️ **Alpha tags must be registered and approved before you can send with them.**
> For messages to Australian numbers, alphanumeric sender IDs must be listed on the
> [ACMA SMS Sender ID Register](https://www.acma.gov.au/sms-sender-id-register)
> (enforced from 1 July 2026) — an unregistered sender ID is replaced with
> **"Unverified"** on the recipient's device. Registration requires your registered
> entity name, ABN, and an authorised contact. Register your sender IDs through the
> Kudosity dashboard before using an alpha tag; otherwise omit `from` to send from
> a shared number.

## DLR & Reply Callbacks

The client provides utilities for handling DLR (Delivery Receipt) and Reply callbacks with signed URLs.

### Setting Up Callback URLs

```php
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Callbacks\CallbackType;

// Create connector and client
$connector = new KudosityV1Connector(
    apiKey: 'your-api-key',
    apiSecret: 'your-api-secret'
);
$client = KudosityClient::fromConnector($connector);

// Create URL builder with your webhook base URL and signing key
$urlBuilder = new CallbackUrlBuilder(
    baseUrl: 'https://myapp.com/webhooks/sms',
    signingKey: 'your-secret-signing-key'
);

// Send SMS with callbacks
$request = (new SendSmsRequest('Your order has shipped!'))
    ->to('61400000000')
    ->from('MYSTORE')
    ->dlrCallback(
        $urlBuilder->build(
            type: CallbackType::DLR,
            handler: 'App\\Webhooks\\OrderDlrHandler',
            context: ['order_id' => 123]
        )
    )
    ->replyCallback(
        $urlBuilder->build(
            type: CallbackType::REPLY,
            handler: 'App\\Webhooks\\OrderReplyHandler',
            context: ['order_id' => 123]
        )
    );

$result = $client->bulk()->sendRequest($request);
```

### Handling Incoming Callbacks

In your webhook endpoint, parse and verify the callback:

```php
use ExpertSystems\Kudosity\Callbacks\CallbackUrlParser;
use ExpertSystems\Kudosity\Data\DlrCallbackData;
use ExpertSystems\Kudosity\Data\ReplyCallbackData;
use ExpertSystems\Kudosity\Exceptions\InvalidSignatureException;

$parser = new CallbackUrlParser('your-secret-signing-key');

try {
    // Parse and verify signature
    $parsed = $parser->parse($_GET);

    // Create DTO from callback data
    $dlr = DlrCallbackData::fromRequest($_GET);

    // Access handler and context
    $handlerClass = $parsed['handler'];  // 'App\Webhooks\OrderDlrHandler'
    $context = $parsed['context'];        // ['order_id' => 123]

    // Call your handler
    $handler = new $handlerClass();
    $handler->handle($dlr, $context);

    http_response_code(200);
    echo 'OK';

} catch (InvalidSignatureException $e) {
    http_response_code(403);
    echo 'Invalid signature';
}
```

### Callback Data DTOs

**DlrCallbackData** - Delivery receipt information:

```php
$dlr = DlrCallbackData::fromRequest($data);

$dlr->messageId;        // int - The message ID
$dlr->mobile;           // string - Recipient phone number
$dlr->status;           // string - 'delivered', 'failed', 'pending'
$dlr->datetime;         // ?string - Delivery timestamp
$dlr->errorCode;        // ?string - Error code if failed
$dlr->errorDescription; // ?string - Error description if failed

$dlr->isDelivered();    // bool - Check if delivered
$dlr->isFailed();       // bool - Check if failed
$dlr->isPending();      // bool - Check if pending
```

**ReplyCallbackData** - Reply message information:

```php
$reply = ReplyCallbackData::fromRequest($data);

$reply->messageId;      // int - Original message ID
$reply->mobile;         // string - Sender phone number
$reply->message;        // string - Reply message text
$reply->receivedAt;     // string - Timestamp when received
$reply->responseId;     // ?int - Reply ID
$reply->longcode;       // ?string - Number replied to
```

**LinkHitCallbackData** - Link click information:

```php
$linkHit = LinkHitCallbackData::fromRequest($data);

$linkHit->messageId;    // int - Message ID
$linkHit->mobile;       // string - Recipient phone number
$linkHit->url;          // string - URL that was clicked
$linkHit->clickedAt;    // string - Click timestamp
$linkHit->userAgent;    // ?string - Browser user agent
$linkHit->ipAddress;    // ?string - IP address
```

## V2 channels

Kudosity's V2 API (`api.transmitmessage.com`, `x-api-key` header) is
single-recipient by design — no contact lists, no scheduling. Four channels
are wired onto the client, each lazily built against `$client->v2()` and
returning typed DTOs rather than raw arrays.

```php
// SMS — single recipient, no scheduling.
$sms = $client->sms()->send('Hello from Kudosity!', '61400000000', '61481074185');
$sms = $client->sms()->get($sms->id);

// MMS — one recipient, one media file.
$mms = $client->mms()->send('61400000000', '61481074185', ['https://example.com/product.jpg']);

// WhatsApp — text only delivers inside the 24-hour service window; use
// template() to initiate a conversation, or custom() for media/buttons.
$wa = $client->whatsapp()->text('Your order has shipped!', '61411122211');

// RCS — $agentId is a registered agent ID (e.g. "DemoSender"), never a phone
// number; a phone-number-shaped value is rejected before the request is sent.
$rcs = $client->rcs()->send('Your order has shipped!', '61411122211', 'DemoSender');
$reachable = $client->rcs()->capabilities(['61411122211'], 'DemoSender');
```

SMS lists page by page (`$client->sms()->list()`); WhatsApp and RCS lists
page by cursor (`$client->whatsapp()->list()`, `$client->rcs()->list()`) —
both through the same `items()`/`collect()` paginator interface as V1.

Recipient handling is not uniform across the four channels: WhatsApp and RCS
normalise the recipient to strict E.164 before sending, while SMS and MMS
send it exactly as given — both skills document `recipient` as "local or
E.164 international format" for those two. This is deliberate, not an
oversight, so do not "fix" one to match the other.

### Response envelopes

The four endpoints do not all shape their response body the same way, and
code written against one shape reads `null` on the other — the most common
way to misread this API. Every DTO factory resolves this for you through one
seam (`Concerns\UnwrapsData::payload()`), so you never need to branch on it
yourself, but it matters the moment you read a response's `json()` directly:

| Endpoint | Envelope |
|---|---|
| SMS — `POST /v2/sms`, `GET /v2/sms/{id}` (single message) | Flat: `{"id": ..., "recipient": ...}` |
| SMS — `GET /v2/sms` (list) | Flat: `{"smses": [...], "total_records": ...}` |
| MMS — `POST/GET /v2/mms` | Flat: `{"id": ..., "recipient": ...}` |
| WhatsApp — `POST/GET /v2/whatsapp/messages` | Wrapped: `{"data": {"id": ..., "recipient": ...}}` |
| RCS — `POST/GET /v2/rcs/messages`, `POST /v2/rcs/capabilities` | Wrapped: `{"data": {...}}` |

> ⚠️ **`sms_count`, `total_records` and `total_segments` arrive from the API
> as JSON strings** — `"sms_count": "1"`, not `1`. `SmsMessageData::$smsCount`
> and `SmsListData::$totalRecords`/`$totalSegments` cast them to `int` for
> you. If you ever read a raw response body yourself (`$response->json()`
> rather than the DTO), treat these fields as strings: with
> `declare(strict_types=1)` in your own code, passing one straight into a
> parameter typed `int` throws a `TypeError`, and `$data['sms_count'] === 1`
> is always `false`.

### Message status subsets

`MessageStatus` is one enum shared across all four channels, but it is
deliberately the *union* of three separate API vocabularies — not every
value is valid, or even possible, everywhere it appears:

| Subset | Values |
|---|---|
| `GET /v2/sms` `status` filter (13) | `PENDING`, `SENT`, `FAILED`, `DELIVERED`, `ACCEPTED`, `SOFT_BOUNCE`, `HARD_BOUNCE`, `OTHER`, `REJECTED`, `PENDING_APPROVAL`, `SUBMITTED`, `UNDELIVERABLE`, `READ` |
| Webhook status events (8) | `SENT`, `ACCEPTED`, `DELIVERED`, `FAILED`, `SOFT_BOUNCE`, `HARD_BOUNCE`, `READ`, `OTHER` |
| WhatsApp and RCS list responses (5) | Includes `QUEUED`, which appears in neither of the other two subsets. The remaining 4 are not itemised separately by Kudosity's docs beyond that they overlap the SMS filter's 13 |
| `UNKNOWN` | Never sent by the API. This SDK's own sentinel, returned by `MessageStatus::fromApi()` for any value the docs have not published, so reading a message never throws just because Kudosity added a status |

`ListSmsV2Request` enforces the first row: passing `MessageStatus::Queued` or
`MessageStatus::Unknown` as the `status` filter to `$client->sms()->list()`
throws rather than silently sending an unsupported query parameter the API
would ignore.

## V2 webhooks

V2 webhooks are **account-level resources managed over the API**, not per-send
callback URLs. `POST /v2/sms` and friends have no `dlr_callback` equivalent, so a
send migrated from V1 to V2 stops receiving delivery receipts and replies unless a
webhook is registered. One registration can serve every channel.

```php
use ExpertSystems\Kudosity\Enums\WebhookEventType;

$hook = $client->webhooks()->create(
    name: 'Production events',
    url: 'https://your-app.example.com/webhooks/kudosity',   // HTTPS required
    eventTypes: [WebhookEventType::SmsStatus, WebhookEventType::SmsInbound],
    rateLimit: 100,                                          // 0 or omitted = system default
);

$client->webhooks()->all();                    // [] when there are none
$client->webhooks()->get($hook->id);
$client->webhooks()->delete($hook->id);

// PUT replaces rather than patches — read, then write the whole shape back.
$current = $client->webhooks()->get($hook->id);
$client->webhooks()->update(
    $current->id,
    $current->name,
    'https://your-app.example.com/webhooks/kudosity/v2',
    filter: $current->filter,
    rateLimit: $current->rateLimit,
);
```

### Handling a delivery

```php
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;

$event = WebhookEvent::fromArray($request->json()->all());

// One accessor, whatever the event type — see the table below.
$ref = $event->messageRef();

match (true) {
    $event instanceof StatusEvent  => $this->recordStatus($event),
    $event instanceof InboundEvent => $this->routeReply($event),
    default                        => $this->log($event->raw),
};
```

### Where the correlation key hides

`message_ref` is how a delivery ties back to your order, booking or conversation.
The API keeps it somewhere different on every event type, which is why
`messageRef()` exists rather than leaving callers to look:

| Event | Path |
|---|---|
| `SMS_STATUS`, `MMS_STATUS`, `WHATSAPP_STATUS`, `RCS_STATUS` | `status.message_ref` |
| `SMS_INBOUND`, `MMS_INBOUND`, `WHATSAPP_INBOUND`, `RCS_INBOUND` | `mo.last_message.message_ref` |
| `LINK_HIT` | `link_hit.source_message.message_ref` |
| `OPT_OUT` | `opt_out.source_message.message_ref` |

**Route replies on `message_ref`, never on the phone number.** Number matching
breaks the first time one contact is in two flows at once, and again when
`routed_via` shows a shared number delivered the message. Note also that on an
inbound event `mo.sender` is the *customer* and `mo.recipient` is *your* number —
and that the webhook filter's `sender` key matches `mo.recipient` for inbound
events, so filtering inbound by sender filters by your own number.

`last_message` is best-effort: it is absent when Kudosity finds no recent
outbound, so an unsolicited inbound has no ref and cannot be correlated *or*
authenticated. `InboundEvent::isCorrelated()` is the check.

### Deliveries are not signed

There is no HMAC, signature or auth header of any kind. The complete observed
header set is `accept-encoding`, `content-length`, `content-type`, `host`,
`sentry-trace`, `traceparent` and `user-agent: Go-http-client/2.0`. **A receiver
cannot verify a delivery came from Kudosity.**

What you *can* verify is that a delivery refers to one of your own entities:

```php
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;

// On the way out
$ref = SignedMessageRef::sign("order-9931:cust-4471", $secret);
$client->sms()->send($body, to: $to, from: $from, messageRef: $ref);

// On the way in
$entity = SignedMessageRef::verify($event->messageRef(), $secret);

if ($entity === null) {
    // Unsigned, forged, or for another system. Do not correlate it.
}
```

This protects **correlation, not the payload**. A forger can still POST a valid
webhook; they cannot make it point at a real entity of yours. Parsing splits on
the *last* colon, so composite refs survive.

### Status events are unordered and at-least-once

Several status events fire per message, they are not order-guaranteed, and the
same event can be delivered twice. A redelivered `SENT` arriving 57 seconds
*after* `DELIVERED` — carrying its original timestamp, byte-identical to the
first — has been observed on a live account.

```php
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;

if (StatusPrecedence::supersedes($event->status, $recorded)) {
    $this->update($event->id, $event->status);   // keyed on status.id
}
```

`MessageStatus::isTerminal()` is **not** enough for this: it is true for both
`DELIVERED` and `READ`, and an RCS read receipt legitimately follows delivery.
`StatusPrecedence` is a rank for that reason.

### Things the documentation does not say

- Every delivery carries `webhook_id` and `webhook_name` at the top level, and
  `MMS_STATUS` carries a carrier `status.description`. All three are modelled.
- `GET /v2/webhook` returns `{}` — not `{"webhooks": []}` — when there are none.
- `MMS_STATUS` does reach `DELIVERED`, despite the docs saying it carries
  internal statuses only.
- Webhook responses are **flat**, not `data`-wrapped, and carry `is_sandbox`,
  `created_at` and `updated_at`.
- Validation errors here return a plain `{"error": "..."}` string rather than the
  RFC 9457 body the messaging endpoints use. Both map to `ValidationException`.
- **A `LINK_HIT` is not evidence a human clicked.** The first hit on a tracked
  link routinely arrives in the same second as `DELIVERED` — a messaging app
  generating a preview. `hits` is cumulative for the link and counts machine
  fetches, so it is not an engagement metric.
- `link_hit.url` is the original destination; the *shortened* link is in
  `source_message.message`.
- **This SDK rejects an `http://` webhook URL even though the API accepts one.**
  The docs require HTTPS, deliveries carry message content, and they are
  unsigned. `WebhookData::isSecure()` reports on registrations that already exist.

## Senders

```php
$client->senders()->allRegistrations();   // typed, all pages
$client->senders()->readyToUse();         // only those that can actually send

$reg = $client->senders()->register('61400000000', 'AU');
$client->senders()->requestVerification($reg->id, originatingSender: '61481074185');
$client->senders()->confirmVerification($reg->id, '012345');   // string: codes have leading zeros

$client->senders()->deleteByPhoneNumber('61400000000');
```

**`VERIFIED` does not mean you can send.** It means *provisioning*. The registry
lifecycle is `NEW` → `SUBMITTED_TO_REGISTRY` → `PENDING_CUSTOMER` →
`PENDING_APPROVAL` → `VERIFIED` → `READY_TO_USE`, and only the last can send —
sending on `VERIFIED` fails in a way that looks like anything but a sender
problem. Use `SenderStatus::isReadyToUse()`, which is false for an unrecognised
state too. `PENDING_CUSTOMER` is waiting on *you*: read `statusReason`.

Two scope limits worth knowing before reaching for `register()`:

- It registers a **personal mobile number** — the only `type` the API accepts is
  `PERSONAL_MOBILE_NUMBER`. Alphanumeric sender IDs, WhatsApp Business senders
  and RCS agents need Kudosity approval and are not self-service.
- A **leased virtual number is not a registration**, so an account can send
  perfectly well and report zero registrations. Use `$client->numbers()` (V1) for
  leased numbers.

`GET /v2/senders/registrations` is page-based but reports its total as
`meta.pagination.total_count` and defaults to 25 per page, where `GET /v2/sms`
reports `total_records` and defaults to 100. `V2PagedPaginator` handles both.

## Laravel Integration

For Laravel projects, use [expertsystemsau/kudosity-laravel-client](https://packagist.org/packages/expertsystemsau/kudosity-laravel-client) which provides:

- Service provider with automatic configuration
- Facade for convenient access
- Notification channel integration
- **Automatic webhook handling** with job dispatching
- Event-driven callback processing

## License

The MIT License (MIT). Please see [License File](../../LICENSE.md) for more information.
