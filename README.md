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
> **V2** (`api.transmitmessage.com`, header auth with the key alone). Every
> package, class, config key and environment variable is renamed to Kudosity;
> V1 support ships under the new names, and V2's four messaging channels —
> single-recipient SMS, MMS, WhatsApp, RCS — are wired onto the client
> alongside it. API-managed webhooks and senders arrive before this line
> reaches `2.0.0`. Upgrading from 1.x? See [UPGRADING.md](UPGRADING.md).

### expertsystemsau/kudosity-php-client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)
[![Total Downloads](https://img.shields.io/packagist/dt/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)
[![License](https://img.shields.io/packagist/l/expertsystemsau/kudosity-php-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-php-client)

Framework-agnostic PHP client for the Kudosity API.

### expertsystemsau/kudosity-laravel-client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/expertsystemsau/kudosity-laravel-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-laravel-client)
[![Total Downloads](https://img.shields.io/packagist/dt/expertsystemsau/kudosity-laravel-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-laravel-client)
[![License](https://img.shields.io/packagist/l/expertsystemsau/kudosity-laravel-client.svg?style=flat-square)](https://packagist.org/packages/expertsystemsau/kudosity-laravel-client)

Laravel notification channel integration (includes the core client) — four channels, the V2 webhook receiver route, and `kudosity:webhook:*` commands.

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

- A **dedicated virtual number (VMN)** in international format, e.g. `61491570012` —
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

The client is resource-based. `$client->sms()` is Kudosity's V2 endpoint
(`POST /v2/sms`), which cannot do multiple recipients, contact lists, or
scheduling — those sends stay on `$client->bulk()` (V1) instead.
`$client->mms()`, `$client->whatsapp()` and `$client->rcs()` cover the other
three V2 channels — see [V2 channels](packages/kudosity-client/README.md#v2-channels)
in the client package README for the full method list and response
envelopes. `$client->webhooks()` manages account-level webhooks, which is how
delivery receipts and replies arrive for V2 sends — V2 has no per-send callback
URL, so [migrating a send](UPGRADING.md#your-v1-callbacks-do-not-fire-for-v2-sends)
without registering one silently loses its callbacks. `$client->senders()` reads
sender registrations and runs the SMS verification flow. Account operations live
on `$client->account()`, reporting on `$client->reporting()`, and so on.

```php
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;

$client = new KudosityClient('api-key', 'api-secret');

// Send an SMS — send(string $message, string $to, ?string $from = null, ?callable $configure = null)
$sms = $client->bulk()->send('Hello from Kudosity!', '+61491570006');
$messageId = $sms->messageId;

// Send to multiple recipients (comma-separated, up to 500)
$client->bulk()->send('Bulk message', '+61491570006,+61491570007');

// Extra options (replies-to-email, callbacks, scheduling, validity) — pass a
// configure closure. Connector defaults still apply, unlike sendRequest().
$client->bulk()->send('Hello!', '+61491570006', configure: fn (SendSmsRequest $r) =>
    $r->repliesToEmail('inbox@example.com')->validity(60)
);

// Full control with no connector defaults applied — build a request yourself
$request = (new SendSmsRequest('Scheduled message'))
    ->to('+61491570006')
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

// V2 channels — single recipient, no scheduling. See the client package
// README's "V2 channels" section for the response envelope table.
$client->sms()->send('Hi from V2!', '61491570006', '61491570017');
$client->mms()->send('61491570006', '61491570017', ['https://example.com/product.jpg']);
$client->whatsapp()->text('Hi from WhatsApp!', '61491570010');
$client->rcs()->send('Hi from RCS!', '61491570010', 'DemoSender');
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

// Send an SMS via V1 bulk() — multiple recipients, contact lists and
// scheduling all live here, not on sms() (see "Core Client" above)
Kudosity::bulk()->send('Hello from Laravel!', '+61491570006');

// V2 single-recipient SMS
Kudosity::sms()->send('Hi from V2!', '61491570006', '61491570017');

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

#### Keeping the registration correct

```bash
php artisan kudosity:webhook:sync
```

Put this in your deploy script alongside `migrate`. It is idempotent — running
it twice registers one webhook, not two — and it repairs drift that a presence
check cannot see, but only some of it in place. Rotating `KUDOSITY_SIGNING_KEY`
or `APP_KEY` updates the existing registration and keeps its id: without this,
it keeps receiving deliveries the receiver then rejects with a 403, and nothing
reports that back to you. Changing `kudosity.webhooks.prefix` or moving
`APP_URL` is different — the path and host are part of the registration's
identity, so the old one becomes a **different endpoint**. `sync` registers a
new webhook for it and leaves the old one running: nothing here deletes, and
the stale registration will not appear in the duplicate warning either, since
that only covers registrations sharing the *current* identity. Those old
deliveries 404 (the path no longer routes) rather than 403 (a stale signature
on a path that still routes). Run `kudosity:webhook:list` after either change
and remove the stale row with `kudosity:webhook:delete`.

`kudosity:webhook:install` remains the imperative one-shot, for registering an
additional, differently-filtered webhook.

**Only permitted environments may run it.** Registrations are account-level, so
one made from staging receives production's delivery receipts and inbound
replies — message bodies and phone numbers included. `kudosity.webhooks.sync.environments`
controls this, defaults to `['production']`, **fails closed** on an empty or
absent list, and has no command-line override. `kudosity:webhook:list` is
read-only and ungated.

If several of your deployments share one Kudosity account and sender, each
receiver will be delivered events for messages the others sent. Write listeners
that treat an unrecognised `message_ref` as ordinary rather than an error.

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

This monorepo runs two independent suites.

The Laravel integration suite (Pest 4 + Orchestra Testbench, PHP 8.3+) — 168 tests, 394 assertions:

```bash
composer install
composer test
```

The client package's own suite (PHPUnit 11), installed and run standalone —
no Laravel, no Testbench — on the PHP 8.2 floor both packages declare, plus
8.3 and 8.4 — 785 tests, 1470 assertions:

```bash
cd packages/kudosity-client
composer install
vendor/bin/phpunit
```

PHP 8.2 itself usually isn't available in a local toolchain; run the same
suite in a container instead:

```bash
cd packages/kudosity-client
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit
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
