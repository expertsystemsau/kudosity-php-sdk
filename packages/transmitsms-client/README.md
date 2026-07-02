# TransmitSMS PHP Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/expertsystemsau/transmitsms-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/transmitsms-php-client)
[![Total Downloads](https://img.shields.io/packagist/dt/expertsystemsau/transmitsms-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/transmitsms-php-client)
[![License](https://img.shields.io/packagist/l/expertsystemsau/transmitsms-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/transmitsms-php-client)

A framework-agnostic PHP client for the [TransmitSMS API](https://transmitsms.com/).

## Installation

```bash
composer require expertsystemsau/transmitsms-php-client
```

## Usage

The client is resource-based. SMS operations live on `$client->sms()`, account
operations on `$client->account()`, reporting on `$client->reporting()`, and
contact lists on `$client->lists()`.

```php
use ExpertSystems\TransmitSms\TransmitSmsClient;
use ExpertSystems\TransmitSms\Requests\SendSmsRequest;

$client = new TransmitSmsClient('your-api-key', 'your-api-secret');

// Send an SMS — send(string $message, string $to, ?string $from = null, ?callable $configure = null)
$sms = $client->sms()->send('Hello from TransmitSMS!', '+61400000000');
$messageId = $sms->messageId;

// Send to multiple recipients (comma-separated, up to 500)
$client->sms()->send('Bulk message', '+61400000000,+61400000001');

// Extra options (replies-to-email, callbacks, scheduling, validity) — pass a
// configure closure. Connector defaults still apply, unlike sendRequest().
$client->sms()->send('Hello!', '+61400000000', configure: fn (SendSmsRequest $r) =>
    $r->repliesToEmail('inbox@example.com')->validity(60)
);

// Full control with no connector defaults applied — build a request yourself
$request = (new SendSmsRequest('Scheduled message'))
    ->to('+61400000000')
    ->from('MySenderID')
    ->scheduledAt('2026-12-25 09:00:00');
$client->sms()->sendRequest($request);

// Check a message's status / delivery stats
$message = $client->reporting()->getMessage($messageId);
$stats = $client->reporting()->getStats($messageId);

// Get account balance
$balance = $client->account()->getBalance();

// Get SMS replies (responses)
$replies = $client->sms()->getAllResponses();

// Manage contact lists
$lists = $client->lists()->all();
$client->lists()->addContact(123, '+61400000000', firstName: 'John');
```

## Pagination

List endpoints (`numbers()->all()`, `lists()->all()`, `keywords()->all()`,
`reporting()->getSent()`, `reporting()->getUserSent()`, `lists()->getContacts()`,
`sms()->getResponses()`/`getAllResponses()`) return a paginator that lazily walks
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
  letters and digits only, no spaces (validate with `$client->sms()->isValidSenderId()`).
  One-way only; recipients cannot reply.
- **Omitted** — TransmitSMS falls back to a shared number for the destination country.

There is no `from` argument on the constructor. Set it one of two ways:

```php
$client = new TransmitSmsClient('your-api-key', 'your-api-secret');

// 1. Per message — the third argument to send() overrides any default.
//    send(string $message, string $to, ?string $from = null, ?callable $configure = null)
$client->sms()->send('Hello!', '+61400000000', 'MyBrand');

// 2. A default sender ID applied to every send()/sendToList() call, set on
//    the connector. Optionally set a default country code used to normalise
//    local numbers before sending.
$client->connector()->setDefaultFrom('MyBrand');
$client->connector()->setDefaultCountryCode('AU');

$client->sms()->send('Hello!', '+61400000000'); // uses "MyBrand"

// Validate a value before you rely on it
if (! $client->sms()->isValidSenderId('MyBrand')) {
    // reject / fall back to a shared number
}
```

> Note: `$client->sms()->sendRequest(SendSmsRequest $request)` does **not** apply
> these connector defaults — set `from` on the request yourself when using it.

> ⚠️ **Alpha tags must be registered and approved before you can send with them.**
> For messages to Australian numbers, alphanumeric sender IDs must be listed on the
> [ACMA SMS Sender ID Register](https://www.acma.gov.au/sms-sender-id-register)
> (enforced from 1 July 2026) — an unregistered sender ID is replaced with
> **"Unverified"** on the recipient's device. Registration requires your registered
> entity name, ABN, and an authorised contact. Register your sender IDs through the
> TransmitSMS dashboard before using an alpha tag; otherwise omit `from` to send from
> a shared number.

## DLR & Reply Callbacks

The client provides utilities for handling DLR (Delivery Receipt) and Reply callbacks with signed URLs.

### Setting Up Callback URLs

```php
use ExpertSystems\TransmitSms\TransmitSmsConnector;
use ExpertSystems\TransmitSms\TransmitSmsClient;
use ExpertSystems\TransmitSms\Requests\SendSmsRequest;
use ExpertSystems\TransmitSms\Callbacks\CallbackUrlBuilder;
use ExpertSystems\TransmitSms\Callbacks\CallbackType;

// Create connector and client
$connector = new TransmitSmsConnector(
    apiKey: 'your-api-key',
    apiSecret: 'your-api-secret'
);
$client = new TransmitSmsClient($connector);

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

$result = $client->sms()->sendRequest($request);
```

### Handling Incoming Callbacks

In your webhook endpoint, parse and verify the callback:

```php
use ExpertSystems\TransmitSms\Callbacks\CallbackUrlParser;
use ExpertSystems\TransmitSms\Data\DlrCallbackData;
use ExpertSystems\TransmitSms\Data\ReplyCallbackData;
use ExpertSystems\TransmitSms\Exceptions\InvalidSignatureException;

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

## Laravel Integration

For Laravel projects, use [expertsystemsau/transmitsms-laravel](https://packagist.org/packages/expertsystemsau/transmitsms-laravel) which provides:

- Service provider with automatic configuration
- Facade for convenient access
- Notification channel integration
- **Automatic webhook handling** with job dispatching
- Event-driven callback processing

## License

The MIT License (MIT). Please see [License File](../../LICENSE.md) for more information.
