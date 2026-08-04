# Kudosity PHP SDK

A PHP client for the [Kudosity API](https://kudosity.com/). This monorepo
contains two packages:

| Package | For | Repository |
|---|---|---|
| `expertsystemsau/kudosity-php-client` | Framework-agnostic PHP | [expertsystemsau/kudosity-php-client](https://github.com/expertsystemsau/kudosity-php-client) |
| `expertsystemsau/kudosity-laravel-client` | Laravel (includes the core client) | [expertsystemsau/kudosity-laravel-client](https://github.com/expertsystemsau/kudosity-laravel-client) |

Both are split from this monorepo's `packages/` directory on every push to
`main` and every `v*` tag — see `.github/workflows/split.yml`.

> This is the 2.x line of the SDK. Kudosity runs two APIs: **V1**
> (`api.transmitsms.com`, HTTP Basic auth with an API key *and* secret) and
> **V2** (`api.transmitmessage.com`, header auth with the key alone). This
> phase renames every package, class, config key and environment variable to
> Kudosity and ships V1 support under the new names; V2 support (MMS,
> WhatsApp, RCS, API-managed webhooks) arrives before this line reaches
> `2.0.0`. Upgrading from 1.x? See [UPGRADING.md](UPGRADING.md).

### expertsystemsau/kudosity-php-client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)
[![Total Downloads](https://img.shields.io/packagist/dt/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)
[![License](https://img.shields.io/packagist/l/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)

Framework-agnostic PHP client for the Kudosity API.

### expertsystemsau/kudosity-laravel-client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/expertsystemsau/kudosity-laravel-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-laravel-client)
[![Total Downloads](https://img.shields.io/packagist/dt/expertsystemsau/kudosity-laravel-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-laravel-client)
[![License](https://img.shields.io/packagist/l/expertsystemsau/kudosity-laravel-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-laravel-client)

Laravel notification channel integration (includes the core client).

## Installation

### For Plain PHP Projects

Install the core client package:

```bash
composer require expertsystemsau/kudosity-php-client
```

### For Laravel Projects

Install the Laravel integration package (includes the core client):

```bash
composer require expertsystemsau/kudosity-laravel-client
```

Then publish the configuration file:

```bash
php artisan vendor:publish --tag="kudosity-config"
```

## Configuration

### Plain PHP

```php
use ExpertSystems\Kudosity\KudosityClient;

$client = new KudosityClient(
    apiKey: 'your-api-key',
    apiSecret: 'your-api-secret'
);
```

### Laravel

Add your credentials to your `.env` file:

```env
KUDOSITY_API_KEY=your-api-key
KUDOSITY_API_SECRET=your-api-secret
# Optional default sender ID — see "Sender IDs" below before setting this
KUDOSITY_FROM=
```

### Sender IDs

The `from` value (`KUDOSITY_FROM`, or the per-message `from` option) is the sender
ID recipients see. It can be:

- A **dedicated virtual number (VMN)** in international format, e.g. `61412345678` —
  supports two-way messaging (recipients can reply).
- An **alphanumeric sender ID** ("alpha tag") such as `MyBrand` — max 11 characters,
  letters and digits only, no spaces (validated by `PhoneNumber::isValidSenderId()`).
  One-way only; recipients cannot reply.
- **Omitted** (leave empty) — Kudosity falls back to a shared number for the
  destination country.

> ⚠️ **Alpha tags must be registered and approved before you can send with them.**
> For messages to Australian numbers, alphanumeric sender IDs must be listed on the
> [ACMA SMS Sender ID Register](https://www.acma.gov.au/sms-sender-id-register)
> (enforced from 1 July 2026) — an unregistered sender ID is replaced with
> **"Unverified"** on the recipient's device. Registration requires your registered
> entity name, ABN, and an authorised contact. Register your sender IDs through the
> Kudosity dashboard before setting `KUDOSITY_FROM`; until then, leave `from`
> empty to send from a shared number.

## Usage

### Core Client (Plain PHP)

The client is resource-based: SMS operations live on `$client->sms()`, account
operations on `$client->account()`, reporting on `$client->reporting()`, and so on.

```php
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;

$client = new KudosityClient('api-key', 'api-secret');

// Send an SMS — send(string $message, string $to, ?string $from = null, ?callable $configure = null)
$sms = $client->sms()->send('Hello from Kudosity!', '+61400000000');
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
```

### Pagination

List endpoints return a paginator that lazily fetches every page as you iterate.
`items()` yields the individual records across all pages:

```php
// Iterate every virtual number, page by page
foreach ($client->numbers()->all()->items() as $number) {
    echo $number['number'].PHP_EOL;
}

// Works the same for lists, keywords, sent messages, responses, and list members
$client->lists()->all();                 // contact lists
$client->keywords()->all();              // keywords
$client->reporting()->getSent($msgId);   // recipients of a message
$client->reporting()->getUserSent();     // all messages sent by the account
$client->lists()->getContacts($listId);  // members of a list

// Request 50 records per page and cap how many pages are walked
$numbers = $client->numbers()->all()
    ->setPerPageLimit(50)
    ->setMaxPages(3)
    ->collect()   // lazy collection of items across the fetched pages
    ->all();
```

The SDK maps each endpoint's response envelope to the right item key
automatically, so iteration returns the records regardless of which key the API
uses (`numbers`, `lists`, `recipients`, `messages`, `members`, `responses`, …).

### Laravel Facade

The facade proxies to the same resources as the core client.

```php
use ExpertSystems\Kudosity\Laravel\Facades\Kudosity;

// Send an SMS
Kudosity::sms()->send('Hello from Laravel!', '+61400000000');

// Get account balance
$balance = Kudosity::account()->getBalance();
```

### Laravel Notifications

Create a notification that uses the Kudosity channel:

```php
use Illuminate\Notifications\Notification;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;

class OrderShipped extends Notification
{
    public function via($notifiable): array
    {
        return ['kudosity'];
    }

    public function toKudosity($notifiable): KudosityMessage
    {
        return KudosityMessage::create('Your order has been shipped!')
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

## Package Structure

```
packages/
├── kudosity-client/        # Core PHP client (no framework dependencies)
│   └── src/
│       ├── KudosityClient.php
│       └── Exceptions/
│           └── KudosityException.php
│
└── kudosity-laravel/       # Laravel integration
    ├── src/
    │   ├── KudosityServiceProvider.php
    │   ├── Facades/
    │   │   └── Kudosity.php
    │   └── Notifications/
    │       ├── KudosityChannel.php
    │       └── KudosityMessage.php
    └── config/
        └── kudosity.php
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Upgrading

Migrating from 1.x? See [UPGRADING.md](UPGRADING.md).

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mitchell Williams](https://github.com/mitchello77)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
