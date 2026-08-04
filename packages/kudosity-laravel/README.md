# TransmitSMS Laravel Integration

[![Latest Version on Packagist](https://img.shields.io/packagist/v/expertsystemsau/transmitsms-laravel.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/transmitsms-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/expertsystemsau/transmitsms-laravel.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/transmitsms-laravel)
[![License](https://img.shields.io/packagist/l/expertsystemsau/transmitsms-laravel.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/transmitsms-laravel)

Laravel notification channel and integration for the [TransmitSMS API](https://transmitsms.com/).

## Installation

```bash
composer require expertsystemsau/transmitsms-laravel
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="transmitsms-config"
```

## Configuration

Add your credentials to your `.env` file:

```env
KUDOSITY_API_KEY=your-api-key
KUDOSITY_API_SECRET=your-api-secret
# Optional default sender ID — see "Sender IDs" below before setting this
KUDOSITY_FROM=
```

### Sender IDs

`KUDOSITY_FROM` (or the per-message `from()` / `from` option) is the sender ID
recipients see. It can be:

- A **dedicated virtual number (VMN)** in international format, e.g. `61412345678` —
  supports two-way messaging (recipients can reply).
- An **alphanumeric sender ID** ("alpha tag") such as `MyBrand` — max 11 characters,
  letters and digits only, no spaces. One-way only; recipients cannot reply.
- **Omitted** (leave empty) — TransmitSMS falls back to a shared number for the
  destination country.

> ⚠️ **Alpha tags must be registered and approved before you can send with them.**
> For messages to Australian numbers, alphanumeric sender IDs must be listed on the
> [ACMA SMS Sender ID Register](https://www.acma.gov.au/sms-sender-id-register)
> (enforced from 1 July 2026) — an unregistered sender ID is replaced with
> **"Unverified"** on the recipient's device. Registration requires your registered
> entity name, ABN, and an authorised contact. Register your sender IDs through the
> TransmitSMS dashboard before setting `KUDOSITY_FROM`; until then, leave it empty
> to send from a shared number.

## Usage

### Facade

The facade proxies to the resource-based client: SMS operations live on `sms()`,
account operations on `account()`, reporting on `reporting()`, and so on.

```php
use ExpertSystems\TransmitSms\Laravel\Facades\TransmitSms;

// Send an SMS — send(string $message, string $to, ?string $from = null, ?callable $configure = null)
TransmitSms::sms()->send('Hello from Laravel!', '+61400000000');

// Get account balance
$balance = TransmitSms::account()->getBalance();
```

### Notifications

Create a notification that uses the TransmitSMS channel:

```php
use Illuminate\Notifications\Notification;
use ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsMessage;

class OrderShipped extends Notification
{
    public function via($notifiable): array
    {
        return ['kudosity'];
    }

    public function toKudosity($notifiable): TransmitSmsMessage
    {
        return TransmitSmsMessage::create('Your order has been shipped!')
            ->from('MyStore');
    }
}
```

Add the `routeNotificationForKudosity` method to your notifiable model:

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForKudosity($notification): ?string
    {
        return $this->phone_number;
    }
}
```

Then send notifications:

```php
$user->notify(new OrderShipped());
```

### Message options

`TransmitSmsMessage` is a fluent builder covering every send option:

```php
TransmitSmsMessage::create('Your order has shipped!')
    ->from('MyStore')                         // sender ID (else config/default)
    ->countryCode('AU')                       // normalise local numbers
    ->formatNumbers()                         // format numbers to E.164 client-side
    ->validity(60)                            // minutes to attempt delivery
    ->sendAt('2026-12-25 09:00:00')           // schedule
    ->repliesToEmail('inbox@example.com')     // route replies to an email
    ->trackedLinkUrl('https://example.com');  // [tracked-link] target
```

To send to a TransmitSMS contact list instead of the notifiable's number, use
`toList()` — the resolved recipient is then ignored:

```php
public function toKudosity($notifiable): TransmitSmsMessage
{
    return TransmitSmsMessage::create('Flash sale for members!')
        ->toList(12345);
}
```

## DLR & Reply Callbacks

The package provides automatic handling for DLR (Delivery Receipt), Reply, and Link Hit callbacks. When you send an SMS, you can specify a job to be dispatched when a callback is received.

### Quick Start

```php
use App\Jobs\UpdateOrderSmsStatusJob;
use App\Jobs\ProcessCustomerReplyJob;
use ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsMessage;

class OrderShipped extends Notification
{
    public function __construct(public Order $order) {}

    public function via($notifiable): array
    {
        return ['kudosity'];
    }

    public function toKudosity($notifiable): TransmitSmsMessage
    {
        return TransmitSmsMessage::create("Your order #{$this->order->id} has shipped!")
            ->from('MYSTORE')
            ->onDlr(UpdateOrderSmsStatusJob::class, [
                'order_id' => $this->order->id,
            ])
            ->onReply(ProcessCustomerReplyJob::class, [
                'order_id' => $this->order->id,
                'customer_id' => $notifiable->id,
            ]);
    }
}
```

### Creating Handler Jobs

**DLR Handler Job:**

```php
namespace App\Jobs;

use App\Models\Order;
use ExpertSystems\TransmitSms\Data\DlrCallbackData;
use ExpertSystems\TransmitSms\Laravel\Contracts\HandlesDlrCallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateOrderSmsStatusJob implements HandlesDlrCallback, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(
        public DlrCallbackData $dlr,
        public array $context,
    ) {}

    public function handle(): void
    {
        $order = Order::find($this->context['order_id']);

        $order->update([
            'sms_status' => $this->dlr->status,
            'sms_delivered_at' => $this->dlr->isDelivered()
                ? now()->parse($this->dlr->datetime)
                : null,
        ]);

        if ($this->dlr->isFailed()) {
            // Handle failure - maybe send email instead
            Log::warning('SMS delivery failed', [
                'order_id' => $order->id,
                'error' => $this->dlr->errorDescription,
            ]);
        }
    }
}
```

**Reply Handler Job:**

```php
namespace App\Jobs;

use App\Models\SmsConversation;
use ExpertSystems\TransmitSms\Data\ReplyCallbackData;
use ExpertSystems\TransmitSms\Laravel\Contracts\HandlesReplyCallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessCustomerReplyJob implements HandlesReplyCallback, ShouldQueue
{
    use Queueable;

    public function __construct(
        public ReplyCallbackData $reply,
        public array $context,
    ) {}

    public function handle(): void
    {
        SmsConversation::create([
            'order_id' => $this->context['order_id'],
            'customer_id' => $this->context['customer_id'],
            'direction' => 'inbound',
            'message' => $this->reply->message,
            'mobile' => $this->reply->mobile,
            'received_at' => $this->reply->receivedAt,
        ]);
    }
}
```

**Link Hit Handler Job:**

```php
namespace App\Jobs;

use ExpertSystems\TransmitSms\Data\LinkHitCallbackData;
use ExpertSystems\TransmitSms\Laravel\Contracts\HandlesLinkHitCallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class TrackLinkClickJob implements HandlesLinkHitCallback, ShouldQueue
{
    use Queueable;

    public function __construct(
        public LinkHitCallbackData $linkHit,
        public array $context,
    ) {}

    public function handle(): void
    {
        LinkClick::create([
            'campaign_id' => $this->context['campaign_id'],
            'mobile' => $this->linkHit->mobile,
            'url' => $this->linkHit->url,
            'clicked_at' => $this->linkHit->clickedAt,
        ]);
    }
}
```

### Global Event Listeners

In addition to per-message handlers, you can listen to events for all callbacks:

```php
// App\Providers\EventServiceProvider.php
use ExpertSystems\TransmitSms\Laravel\Events\DlrReceived;
use ExpertSystems\TransmitSms\Laravel\Events\ReplyReceived;
use ExpertSystems\TransmitSms\Laravel\Events\LinkHitReceived;

protected $listen = [
    DlrReceived::class => [
        \App\Listeners\LogDlrCallback::class,
    ],
    ReplyReceived::class => [
        \App\Listeners\LogReplyCallback::class,
    ],
    LinkHitReceived::class => [
        \App\Listeners\LogLinkHitCallback::class,
    ],
];
```

Example listener:

```php
namespace App\Listeners;

use ExpertSystems\TransmitSms\Laravel\Events\DlrReceived;
use Illuminate\Support\Facades\Log;

class LogDlrCallback
{
    public function handle(DlrReceived $event): void
    {
        Log::info('DLR callback received', [
            'message_id' => $event->dlr->messageId,
            'mobile' => $event->dlr->mobile,
            'status' => $event->dlr->status,
            'context' => $event->context,
        ]);
    }
}
```

### Webhook Configuration

The webhook routes are automatically registered. You can customize them in `config/transmitsms.php`:

```php
'webhooks' => [
    // Enable/disable webhook routes
    'enabled' => env('KUDOSITY_WEBHOOKS_ENABLED', true),

    // Route prefix (e.g., /webhooks/kudosity/dlr)
    'prefix' => env('KUDOSITY_WEBHOOKS_PREFIX', 'webhooks/kudosity'),

    // Middleware for webhook routes
    'middleware' => ['api'],

    // Custom signing key (defaults to APP_KEY)
    'signing_key' => env('KUDOSITY_SIGNING_KEY'),

    // DLR callback settings
    'dlr' => [
        'enabled' => true,
        'path' => 'dlr',
        'queue' => env('KUDOSITY_DLR_QUEUE', 'default'),
    ],

    // Reply callback settings
    'reply' => [
        'enabled' => true,
        'path' => 'reply',
        'queue' => env('KUDOSITY_REPLY_QUEUE', 'default'),
    ],

    // Link hits callback settings
    'link_hits' => [
        'enabled' => true,
        'path' => 'link-hits',
        'queue' => env('KUDOSITY_LINK_HITS_QUEUE', 'default'),
    ],
],
```

### Callback Data Objects

**DlrCallbackData** properties:

| Property | Type | Description |
|----------|------|-------------|
| `messageId` | `int` | The message ID |
| `mobile` | `string` | Recipient phone number |
| `status` | `string` | Status: `delivered`, `failed`, `pending` |
| `datetime` | `?string` | Delivery timestamp |
| `senderId` | `?string` | Sender ID used |
| `errorCode` | `?string` | Error code if failed |
| `errorDescription` | `?string` | Error description |

Helper methods: `isDelivered()`, `isFailed()`, `isPending()`

**ReplyCallbackData** properties:

| Property | Type | Description |
|----------|------|-------------|
| `messageId` | `int` | Original message ID |
| `mobile` | `string` | Sender phone number |
| `message` | `string` | Reply message text |
| `receivedAt` | `string` | Timestamp when received |
| `responseId` | `?int` | Reply ID |
| `longcode` | `?string` | Number replied to |
| `firstName` | `?string` | Sender first name |
| `lastName` | `?string` | Sender last name |

**LinkHitCallbackData** properties:

| Property | Type | Description |
|----------|------|-------------|
| `messageId` | `int` | Message ID |
| `mobile` | `string` | Recipient phone number |
| `url` | `string` | URL that was clicked |
| `clickedAt` | `string` | Click timestamp |
| `userAgent` | `?string` | Browser user agent |
| `ipAddress` | `?string` | IP address |

### How It Works

1. **Sending**: When you use `onDlr()`, `onReply()`, or `onLinkHit()`, the package builds a signed callback URL containing your handler class and context data.

2. **Receiving**: When TransmitSMS calls the webhook, the package:
   - Verifies the HMAC signature
   - Parses the callback data into a DTO
   - Dispatches a global event (for logging/monitoring)
   - Dispatches your handler job with the data and context

3. **Security**: The callback URL includes an HMAC signature to prevent tampering. Only callbacks with valid signatures are processed.

```
┌─────────────────────────────────────────────────────────────────────┐
│  Your App                                                           │
│  ────────                                                           │
│  TransmitSmsMessage::create('Hello')                               │
│      ->onDlr(MyJob::class, ['id' => 1])                           │
│                    │                                                │
│                    ▼                                                │
│  Package builds signed callback URL                                │
│  https://app.com/webhooks/kudosity/dlr?h=...&c=...&s=...       │
└─────────────────────────────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────────┐
│  TransmitSMS                                                        │
│  ───────────                                                        │
│  Sends SMS → Receives DLR → Calls your webhook URL                 │
└─────────────────────────────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Your App (Webhook)                                                 │
│  ─────────────────                                                  │
│  WebhookController:                                                │
│    1. Verify signature ✓                                           │
│    2. Parse DlrCallbackData                                        │
│    3. Dispatch DlrReceived event                                   │
│    4. Dispatch MyJob with data + context                           │
└─────────────────────────────────────────────────────────────────────┘
```

## License

The MIT License (MIT). Please see [License File](../../LICENSE.md) for more information.
