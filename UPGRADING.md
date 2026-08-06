# Upgrading

## 1.x (TransmitSMS) to 2.x (Kudosity)

TransmitSMS has rebranded to Kudosity. Phase 1 of the 2.0 line renames every
package, namespace, class, config key and environment variable to match —
mechanically, with no behaviour change. Every V1 request still hits the same
URL with the same body and the same auth. Kudosity's V2 API — single-recipient
SMS, MMS, WhatsApp and RCS — has since landed; see [V2 channels](#v2-channels)
below. API-managed webhooks and senders are still to come, in a later phase;
this file will grow a **Webhook migration** section once they land.

### Renames

The full rebrand, from the design spec:

| Now | After |
|---|---|
| repo `expertsystemsau/transmitsms-php-sdk` | `expertsystemsau/kudosity-php-sdk` |
| `packages/transmitsms-client` | `packages/kudosity-client` |
| `packages/transmitsms-laravel` | `packages/kudosity-laravel` |
| package `expertsystemsau/transmitsms-php-client` | `expertsystemsau/kudosity-php-client` |
| package `expertsystemsau/transmitsms-laravel-client` | `expertsystemsau/kudosity-laravel-client` |
| namespace `ExpertSystems\TransmitSms\` | `ExpertSystems\Kudosity\` |
| namespace `ExpertSystems\TransmitSms\Laravel\` | `ExpertSystems\Kudosity\Laravel\` |
| namespace `ExpertSystems\TransmitSms\Tests\` | `ExpertSystems\Kudosity\Tests\` |
| `TransmitSmsClient` | `KudosityClient` |
| `TransmitSmsConnector` | `KudosityV1Connector` + `KudosityV2Connector` |
| `TransmitSmsRequest` | `KudosityV1Request` + `KudosityV2Request` |
| `TransmitSmsException` | `KudosityException` |
| `TransmitSmsPaginator` | `V1PagedPaginator` |
| `TransmitSmsServiceProvider` | `KudosityServiceProvider` |
| `TransmitSmsChannel` | `KudosityChannel` |
| `TransmitSmsMessage` | `KudosityMessage` |
| facade `TransmitSms` | `Kudosity` |
| `config/transmitsms.php` | `config/kudosity.php` |
| env `TRANSMITSMS_*` | `KUDOSITY_*` |
| `toTransmitSms()` | `toKudosity()` |
| channel string `'transmitsms'` | `'kudosity'` |
| `routeNotificationForTransmitsms()` | `routeNotificationForKudosity()` |
| route names `transmitsms.webhooks.dlr` / `.reply` / `.link-hits` | `kudosity.webhooks.dlr` / `.reply` / `.link-hits` |
| container aliases `transmitsms` / `transmitsms.connector` | `kudosity` / `kudosity.connector` |

The API hostnames stay `api.transmitsms.com` and `api.transmitmessage.com` —
Kudosity has not renamed them — so those string constants keep their real
values under new constant names (`KudosityV1Connector::BASE_URL`,
`KudosityV2Connector::BASE_URL` once V2 lands).

`KudosityV2Connector`, `KudosityV2Request` and `KudosityException::fromV2Response()`
are reserved names — nothing in Phase 1 occupies them, so a later phase can
introduce them without another rename.

### Class renames shipped in this phase

Concretely, in this release:

| 1.x | 2.x |
|---|---|
| `TransmitSmsClient` | `KudosityClient` |
| `TransmitSmsConnector` | `KudosityV1Connector` |
| `TransmitSmsRequest` | `KudosityV1Request` |
| `TransmitSmsException` | `KudosityException` |
| `TransmitSmsPaginator` | `V1PagedPaginator` |
| `TransmitSmsServiceProvider` | `KudosityServiceProvider` |
| `TransmitSmsChannel` | `KudosityChannel` |
| `TransmitSmsMessage` | `KudosityMessage` |
| facade `TransmitSms` | `Kudosity` |
| `toTransmitSms()` | `toKudosity()` |
| channel string `'transmitsms'` | `'kudosity'` |

## Automated upgrade

Install the new package:

```bash
composer require expertsystemsau/kudosity-laravel-client:^2.0
```

(Plain-PHP project, no Laravel? Install `expertsystemsau/kudosity-php-client:^2.0` instead.)

`bin/kudosity-codemod` ships in the monorepo, not inside either split
package — `.github/workflows/split.yml` only publishes `packages/*` to the
two package repos, so the script never reaches your project's `vendor/`
directory. Fetch it (and the rename map it depends on) directly from the
monorepo instead:

```bash
curl -fsSL -o kudosity-codemod \
  https://raw.githubusercontent.com/expertsystemsau/kudosity-php-sdk/main/bin/kudosity-codemod
curl -fsSL -o rename-map.json \
  https://raw.githubusercontent.com/expertsystemsau/kudosity-php-sdk/main/rename-map.json
chmod +x kudosity-codemod
```

The script resolves the map relative to its own location
(`__DIR__.'/../rename-map.json'`), so with both files dropped side by side
as above, point it at the map explicitly with `--map`:

```bash
./kudosity-codemod . --write --map=./rename-map.json
```

`--map=PATH` is the general-purpose way to point the codemod at an explicit
map file instead of relying on the default relative lookup — use it whenever
the script and `rename-map.json` don't end up one directory apart. If you'd
rather match the default lookup, place the script at `bin/kudosity-codemod`
inside your project and `rename-map.json` at your project root, then omit
`--map`.

Then publish the new config:

```bash
php artisan vendor:publish --tag=kudosity-config
```

Notes:

- Dry-run by default — the commands above with `--write` removed report
  what *would* change and exit `0` without touching anything.
- It skips `vendor/`, `node_modules/`, `.git/`, `storage/`, `bootstrap/cache/`
  and `public/build/`.
- It refuses to run — exiting non-zero — if a rewrite would damage the
  literal `api.transmitsms.com` anywhere in your project; that hostname is
  the real, live V1 API host and must survive untouched. The check runs as a
  first pass over every file before anything is written, so a refusal always
  leaves your working tree completely untouched — it never leaves you with a
  half-migrated project.
- It also flags every call site it finds using `fromResponse()` for manual
  review rather than rewriting it — see
  [`fromResponse()` → `fromV1Response()`](#fromresponse--fromv1response) below.
- It renames the `BASE_URL_SMS` constant to `BASE_URL`, and flags every use
  of `useSmsUrl()`, `useMmsUrl()` and `BASE_URL_MMS` for manual review —
  those three were removed outright in this phase, with no automatic
  replacement; see [Removed APIs](#removed-apis) below.
- The text-level rules operate on plain text, not the AST, so they can also
  hit your own code: `\bTransmitSms\b` → `Kudosity` will rename your own
  `TransmitSms`-named class without renaming its file (breaking autoload),
  and `'transmitsms'` → `'kudosity'` will rewrite an unrelated array key of
  the same name. Review `git diff` for your own same-named symbols before
  committing. The tool never renames files — it only *reports*
  `config/transmitsms.php` as needing a manual rename.

## Rector

Prefer Rector? Here's an equivalent config generated from the same map,
covering the class renames:

```php
<?php

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/app', __DIR__.'/config', __DIR__.'/routes'])
    ->withConfiguredRule(RenameClassRector::class, [
        'ExpertSystems\TransmitSms\TransmitSmsClient' => 'ExpertSystems\Kudosity\KudosityClient',
        'ExpertSystems\TransmitSms\TransmitSmsConnector' => 'ExpertSystems\Kudosity\KudosityV1Connector',
        'ExpertSystems\TransmitSms\Requests\TransmitSmsRequest' => 'ExpertSystems\Kudosity\Requests\KudosityV1Request',
        'ExpertSystems\TransmitSms\Exceptions\TransmitSmsException' => 'ExpertSystems\Kudosity\Exceptions\KudosityException',
        'ExpertSystems\TransmitSms\Pagination\TransmitSmsPaginator' => 'ExpertSystems\Kudosity\Pagination\V1PagedPaginator',
        'ExpertSystems\TransmitSms\Laravel\TransmitSmsServiceProvider' => 'ExpertSystems\Kudosity\Laravel\KudosityServiceProvider',
        'ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsChannel' => 'ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel',
        'ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsMessage' => 'ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage',
        'ExpertSystems\TransmitSms\Laravel\Facades\TransmitSms' => 'ExpertSystems\Kudosity\Laravel\Facades\Kudosity',
    ]);
```

Rector renames classes only. The notification hook (`toTransmitSms()` →
`toKudosity()`), the channel string (`'transmitsms'` → `'kudosity'`), config
keys and environment variables still need the codemod above, or a hand pass.

## Config and environment

`config/transmitsms.php` → `config/kudosity.php`. Publish tag
`transmitsms-config` → `kudosity-config`.

Every environment variable is renamed with the same `TRANSMITSMS_` →
`KUDOSITY_` prefix swap:

| 1.x | 2.x |
|---|---|
| `TRANSMITSMS_API_KEY` | `KUDOSITY_API_KEY` |
| `TRANSMITSMS_API_SECRET` | `KUDOSITY_API_SECRET` |
| `TRANSMITSMS_BASE_URL` | `KUDOSITY_BASE_URL` |
| `TRANSMITSMS_TIMEOUT` | `KUDOSITY_TIMEOUT` |
| `TRANSMITSMS_FROM` | `KUDOSITY_FROM` |
| `TRANSMITSMS_WEBHOOKS_ENABLED` | `KUDOSITY_WEBHOOKS_ENABLED` |
| `TRANSMITSMS_WEBHOOKS_PREFIX` | `KUDOSITY_WEBHOOKS_PREFIX` |
| `TRANSMITSMS_SIGNING_KEY` | `KUDOSITY_SIGNING_KEY` |
| `TRANSMITSMS_DLR_QUEUE` | `KUDOSITY_DLR_QUEUE` |
| `TRANSMITSMS_REPLY_QUEUE` | `KUDOSITY_REPLY_QUEUE` |
| `TRANSMITSMS_LINK_HITS_QUEUE` | `KUDOSITY_LINK_HITS_QUEUE` |

`KUDOSITY_WEBHOOKS_PREFIX` defaults to `webhooks/kudosity` (was
`webhooks/transmitsms`), so any webhook URL you've already registered with
Kudosity, or hard-coded anywhere in your own app, needs updating to match —
or pin the old value explicitly (`KUDOSITY_WEBHOOKS_PREFIX=webhooks/transmitsms`)
to avoid a redeploy race where the registered URL and the route prefix
briefly disagree.

## Removed APIs

| Removed | Replacement |
|---|---|
| `useSmsUrl()` | Removed with no replacement. The connector only ever pointed at one host; set it directly with `setBaseUrl()` if you need to override it. |
| `useMmsUrl()` | Removed with no replacement, same reasoning — nothing in the SDK ever issued a request against the MMS host. |
| `BASE_URL_MMS` constant | Removed with no replacement. |
| `BASE_URL_SMS` constant | Renamed to `BASE_URL`. Replace `TransmitSmsConnector::BASE_URL_SMS` with `KudosityV1Connector::BASE_URL`. |
| `KudosityException::fromResponse()` | Renamed to `fromV1Response()` — see below. |
| `Resources\SmsResource` class | Removed — split three ways. Sends, `cancel()` and the offline phone helpers (`formatNumberLocal()`, `isValidNumber()`, `validateNumbers()`, `isValidSenderId()`) moved to `Resources\BulkSmsResource`; the reply readers (`getResponses()`, `getResponsesByKeywordId()`, `getResponsesByKeyword()`, `getAllResponses()`) moved to `Resources\ReportingResource`; the API-backed `formatNumber()` moved to `Resources\NumbersResource`. The codemod rewrites `use ExpertSystems\TransmitSms\Resources\SmsResource;`, `SmsResource::class` and type hints to `Resources\BulkSmsResource` — update the call site to the accessor it actually needs (see [Resource surface changes](#resource-surface-changes) below) if that's not the one you meant. |
| `$client->sms()` accessor | Removed in the rebrand release, with no rewrite (flagged for manual review) — replaced by `bulk()`, `reporting()` or `numbers()` depending on which method you were calling (see [Resource surface changes](#resource-surface-changes) below). **`sms()` has since returned, but it means something different — see below.** The codemod keeps flagging every `sms(` call site regardless, because a compiling call is not necessarily a correct one. |

### `sms()` means something different now

`$client->sms()` was removed in the rebrand release and has since returned —
but it is not the method you remember. V1's `sms()` took up to 500
comma-separated recipients and could schedule a future send; V2's `sms()`
(`POST /v2/sms`, this release) takes **exactly one recipient** and cannot
schedule at all.

The dangerous case is multi-recipient code that still compiles unchanged:

```php
// 1.x: sent to two recipients.
// 2.x: same line, but now sends to ONE recipient — the literal string
// "61400000000,61400000001", comma and all. No exception, no warning.
$client->sms()->send($msg, '61400000000,61400000001');
```

That is worse than the fatal error this call produced in the rebrand
release: a loud break has become a silent wrong send. Nothing about a failed
(or, worse, "successful") delivery here mentions multiple recipients — it is
just the API doing its best with an unrecognisable single `recipient`.

This is why the codemod keeps flagging `sms(` for manual review
(`"sms": null` in `rename-map.json`, under `removed`) rather than treating
the symbol's return as fixed. If your project calls `$client->sms()`, check
every call site by hand:

- Single recipient, no scheduling → safe to keep; it now hits V2's
  `POST /v2/sms` and returns a typed `SmsMessageData` instead of the old
  array/DTO shape.
- Multiple recipients, a contact list, or a scheduled send → move it to
  `$client->bulk()`, which is what `sms()` mapped to for the duration this
  method was gone (see [Resource surface changes](#resource-surface-changes)
  below).

### `fromResponse()` → `fromV1Response()`

The codemod deliberately does **not** rewrite this one for you. Several
unrelated classes declare their own `fromResponse()` factory, so a
text-level rename would silently corrupt code that has nothing to do with
`KudosityException`. Instead, for every file it finds calling `fromResponse(`,
the codemod prints:

```
review by hand: <file> uses fromResponse() — see UPGRADING.md
```

This section is what that message points at. The rule is narrow:

- Only `KudosityException::fromResponse()` was renamed, to `fromV1Response()`.
  Change `TransmitSmsException::fromResponse($response)` /
  `KudosityException::fromResponse($response)` call sites to
  `KudosityException::fromV1Response($response)`.
- Every `Data\*Data` DTO (`SmsData::fromResponse()`, `BalanceData::fromResponse()`,
  and so on) keeps its own `fromResponse()` — unchanged, and unrelated to the
  exception factory despite the shared name.
- `RateLimitException::fromResponseWithMetadata()` is unchanged.

If a flagged file's `fromResponse(` call is on a DTO rather than
`KudosityException`, no action is needed — the codemod flags the method name
regardless of which class it's called on, because it can't resolve types
from plain text.

## Resource surface changes

Kudosity's V2 API adds `POST /v2/sms` — a single-recipient send with no
`send_at` — and `sms()` now returns it. The V1 send surface that `sms()` used
to expose lives on `bulk()`: it is everything V2 cannot do — multiple
recipients, contact lists, and scheduled sends. The reply readers moved to
`reporting()`, where every other read already lives, and the API-backed
number formatter moved to `numbers()`, alongside the other number endpoints.

| 1.x | 2.x |
|---|---|
| n/a — new in this release | `$client->sms()->send($msg, $to, $from)` — V2's single-recipient send (`POST /v2/sms`); exactly one recipient, no scheduling. **Not** a drop-in replacement for 1.x `sms()` — see [`sms()` means something different now](#sms-means-something-different-now) above before repointing multi-recipient code at it. |
| `$client->sms()->send($msg, $to)` — multiple recipients | `$client->bulk()->send($msg, $to)` |
| `$client->sms()->sendToList($msg, $listId)` | `$client->bulk()->sendToList($msg, $listId)` |
| `$client->sms()->sendRequest($request)` | `$client->bulk()->sendRequest($request)` |
| `$client->sms()->cancel($id)` | `$client->bulk()->cancel($id)` |
| `$client->sms()->getResponses($id)` | `$client->reporting()->getResponses($id)` |
| `$client->sms()->getResponsesByKeywordId($id)` | `$client->reporting()->getResponsesByKeywordId($id)` |
| `$client->sms()->getResponsesByKeyword($kw, $n)` | `$client->reporting()->getResponsesByKeyword($kw, $n)` |
| `$client->sms()->getAllResponses()` | `$client->reporting()->getAllResponses()` |
| `$client->sms()->formatNumber($n)` | `$client->numbers()->formatNumber($n)` |
| `$client->sms()->formatNumberLocal($n)` | `$client->bulk()->formatNumberLocal($n)` |
| `$client->sms()->isValidNumber($n)` | `$client->bulk()->isValidNumber($n)` |
| `$client->sms()->validateNumbers($n)` | `$client->bulk()->validateNumbers($n)` |
| `$client->sms()->isValidSenderId($s)` | `$client->bulk()->isValidSenderId($s)` |
| `new KudosityClient($key, $secret, $baseUrl, $timeout)` | `new KudosityClient($key, $secret, $v1BaseUrl, $v2BaseUrl, $timeout)` — `$apiSecret` is now optional |
| `$client->setBaseUrl($url)` | `$client->setV1BaseUrl($url)` — `setBaseUrl()` still works, as an alias |
| n/a | `$client->v1()`, `$client->v2()` — the two connectors, see [Two connectors](#two-connectors) below |

The codemod cannot automate any of these: the method names themselves
(`sendToList`, `getResponses`, `getAllResponses`, ...) are unchanged, only the
accessor before them changed, and a text-level tool can't tell your own
`sms()`-returning method from ours. It flags `sendToList(`, `getResponses(`,
`getAllResponses(` and `sms(` call sites for manual review the same way it
flags `fromResponse(` above; the other renamed accessors in the table have no
matching method name elsewhere in the SDK, so nothing (yet) needs a flag for
them. The `sms(` flag is case-sensitive, so it matches `$client->sms()` but
not your own `emailSms()` or similar camelCase method names.

Scheduling is now explicit rather than something you reach through the
`configure` closure: `$client->bulk()->schedule($msg, $to, $at)`.

### Two connectors

`KudosityClient` now holds a connector for each API: `v1()` returns the
`KudosityV1Connector` (`api.transmitsms.com`, key + secret — everything
above this section), and `v2()` returns the new `KudosityV2Connector`
(`api.transmitmessage.com`, key only). `connector()` is unchanged and still
returns the V1 connector, so existing code that calls it keeps working.

The API secret is now optional on the constructor —
`new KudosityClient($apiKey)` builds a client that can use `v2()` but throws
`KudosityException` from any V1 call, with a message naming the missing
secret rather than a bare 401 from the API. Pass both `$apiKey` and
`$apiSecret` as before if you need V1.

`setBaseUrl()` set the connector's only base URL; with two hosts that name
is ambiguous, so it is renamed `setV1BaseUrl()`. `setBaseUrl()` still exists
and delegates to it, so nothing breaks, but new code should call
`setV1BaseUrl()` (or `$client->v2()->setBaseUrl()` for the V2 host)
directly. `fromConnector()` is unchanged — it takes a V1 connector and
derives a V2 connector from its API key. `fromConnectors()` is new and
takes either or both connectors directly, for a container or a shared setup.

## V2 channels

Four V2 channels are wired onto `KudosityClient`, each lazily built against
`v2()` and returning typed DTOs. None of these existed under 1.x, so there is
nothing to migrate — this is new surface, not a rename.

```php
// SMS — single recipient only. See "sms() means something different now"
// above before pointing multi-recipient 1.x code at this.
$client->sms()->send('Hello from Kudosity!', '61400000000', '61481074185');

// MMS — one recipient, one media file.
$client->mms()->send('61400000000', '61481074185', ['https://example.com/product.jpg']);

// WhatsApp — free-form text only delivers inside the 24-hour service window;
// use template() instead to initiate a conversation.
$client->whatsapp()->text('Your order has shipped!', '61411122211');

// RCS — $agentId is a registered agent ID (e.g. "DemoSender"), never a phone
// number; a phone-number-shaped value is rejected before the request is sent.
$client->rcs()->send('Your order has shipped!', '61411122211', 'DemoSender');
```

See the client package README's "V2 channels" section for the full method
list, the per-endpoint response envelope, and the `sms_count`/`total_records`/
`total_segments` string-vs-int gotcha.

### `rcs()->capabilities()` now validates `sender` too

`$client->rcs()->send()` already rejected a phone-number-shaped `$agentId`
before this release; `$client->rcs()->capabilities()` did not, and reached
the live API instead. It now throws `ValidationException` (`FIELD_INVALID`)
at construction, for the same reason and with the same check.

**Affected:** only calls to `capabilities()` that pass something
phone-number-shaped as `sender`. Previously this reached Kudosity and failed
with `"sender is not owned by this account"` — true, but silent about the
real mistake. Now it fails locally, before any request is sent, with a
message naming the actual problem.

**Action:** pass a registered RCS agent ID (e.g. `"DemoSender"`), not a phone
number.

## Your V1 callbacks do not fire for V2 sends

**Read this before repointing any send at a V2 channel.** It is the one change in
this release that breaks something without producing an error.

Under 1.x a send carried its own callback URLs — `dlr_callback` for delivery
status, `reply_callback` for inbound replies — and the SDK's signed-URL helpers
built them per send. **V2 has no per-send callback URL at all.** Delivery status
and replies for V2 messages arrive at an *account-level webhook* you register
once, over the API.

So a call site migrated like this:

```php
// 1.x — the callback URL travelled with the send
$client->sms()->send($message, $to, ['dlr_callback' => $url]);

// 2.x on a V2 channel — the option is gone, and nothing warns you
$client->sms()->send($message, to: $to, from: $from);
```

…sends the message successfully and then **silently stops receiving delivery
receipts and replies**. Nothing throws, no status changes: the messages simply
send and the callbacks never arrive.

Register a webhook once instead:

```php
use ExpertSystems\Kudosity\Enums\WebhookEventType;

$client->webhooks()->create(
    name: 'Production events',
    url: 'https://your-app.example.com/webhooks/kudosity',
    eventTypes: [WebhookEventType::SmsStatus, WebhookEventType::SmsInbound],
);
```

Three things to know while doing it:

- **V1 callbacks still work — for V1 sends.** `bulk()`, `bulk()->sendToList()`
  and `bulk()->schedule()` are V1 and keep their per-send callbacks. You will be
  running both mechanisms during a partial migration, and V1 callbacks do not
  fire for V2 messages any more than V2 webhooks replace them.
- **One webhook can serve every channel**, and event types are filtered with
  `filter.event_type`. There are ten of them; `SMS_STATUS` does **not** report
  WhatsApp or RCS.
- **Deliveries are not signed.** There is no HMAC or signature header of any
  kind, so a receiver cannot prove a delivery came from Kudosity. Use
  `Webhooks\SignedMessageRef` to sign your own `message_ref` on the way out and
  verify it on the way in — that proves the delivery refers to one of your
  entities, which is the only authenticity signal available. It does not
  authenticate the payload.

Handling must also be **idempotent on `status.id`**: several status events fire
per message, arrival order is not guaranteed, and deliveries are at-least-once —
a redelivered `SENT` arriving after `DELIVERED` has been observed in the wild.
`Webhooks\StatusPrecedence::supersedes()` is the guard.

## Senders

`$client->senders()` reads sender registrations and runs the SMS verification
flow. Nothing to migrate — new surface.

One thing worth knowing before you reach for it: it registers a **personal
mobile number**, and that is the only `type` the API accepts. Alphanumeric sender
IDs, WhatsApp Business senders and RCS agents all need Kudosity approval and
never appear here — and a **leased virtual number is not a registration** either,
so an account can send perfectly well and report zero registrations. Use
`$client->numbers()` (V1) for leased numbers.

`VERIFIED` does not mean sendable; it means provisioning. Check
`isReadyToUse()`.

## Laravel: config, channels and the receiver

### `base_url` is now keyed by API version

Kudosity runs two APIs on two hostnames, so one value cannot serve both:

```php
// config/kudosity.php — before
'base_url' => env('KUDOSITY_BASE_URL', 'https://api.transmitsms.com'),

// after
'base_url' => [
    'v1' => env('KUDOSITY_BASE_URL_V1', 'https://api.transmitsms.com'),
    'v2' => env('KUDOSITY_BASE_URL_V2', 'https://api.transmitmessage.com'),
],
```

**A published config still carrying the flat string now throws on boot**, naming
both replacement keys and echoing your value. That is deliberate: a published
config file is not re-published on upgrade, and a stale `base_url` points at the
V1 host — silently ignoring it would send every V2 request to the wrong API. The
codemod rewrites `TRANSMITSMS_BASE_URL` to `KUDOSITY_BASE_URL_V1` and
`config('kudosity.base_url')` to `config('kudosity.base_url.v1')`.

New keys, all optional: `country_code`, `mms.sender`, `whatsapp.sender`,
`rcs.agent_id`, and `webhooks.events.{enabled,path}`. Each channel needs its own
sender because they are not the same kind of value — an alphanumeric sender that
works for SMS is not a valid MMS sender, and an RCS sender is an *agent ID*
rather than a number at all.

### `KudosityChannel::send()` returns `SentMessage`

It used to return `?SmsData`. It now returns `?SentMessage`, which both `SmsData`
(V1) and `Data\V2\SmsMessageData` implement, because the channel decides which
API to use and the return type must not change with that decision.

If you type-hinted the concrete class, widen it:

```php
- public function handle(?SmsData $sent) { … }
+ public function handle(?SentMessage $sent) { … }
```

`SentMessage` gives you `id(): string`, `recipientCount(): int` and
`status(): ?MessageStatus`. **`status()` is null for every V1 send** — the V1 send
response carries no status, and inventing one would be indistinguishable from a
real one. Read it back with `reporting()` or `sms()->get()`.

### The SMS channel now sends over V2 by default

A notification routes to V2 unless it uses something V2 cannot express:
`toList()`, `sendAt()`, `validity()`, `repliesToEmail()`, any of the three
per-send callbacks — **including the `onDlr()` / `onReply()` / `onLinkHit()`
handler forms** — or more than one recipient in `to()`.

`apiVersion()` reports the decision and `v1Reasons()` names what drove it, so it
is inspectable rather than magic. `forceV1()` and `forceV2()` override, and
**`forceV2()` throws if the message uses a V1-only option** rather than dropping
it: silently ignoring a `sendAt()` turns a scheduled send into an immediate one.

### Three new channels

`kudosity-mms`, `kudosity-whatsapp` and `kudosity-rcs`, expecting
`toKudosityMms()`, `toKudosityWhatsApp()` and `toKudosityRcs()` on the
notification. All three are V2-only, so none has a routing decision.

### The V2 events receiver

A new `POST {prefix}/events` route handles all ten V2 event types and dispatches
`KudosityStatusReceived`, `KudosityInboundReceived`, `KudosityLinkHitReceived` or
`KudosityOptOutReceived`. **The three V1 GET routes are unchanged and still
handle V1 callbacks** — see "Your V1 callbacks do not fire for V2 sends" above.

Two things worth knowing before you write a listener:

- **Handle status events idempotently on `status.id`.** They are unordered *and*
  at-least-once. Use `Webhooks\StatusPrecedence::supersedes()`; a listener that
  writes unconditionally will corrupt its own delivery reporting.
- **The route is authenticated by its unguessable URL only**, because V2
  deliveries carry no signature. Register it with
  `kudosity:webhook:install`, or build the URL through `CallbackUrlBuilder` —
  a request without a valid signature gets a 403.

## For maintainers

Release checklist:

1. Rename the GitHub monorepo to `kudosity-php-sdk`.
2. Create `expertsystemsau/kudosity-php-client` and `expertsystemsau/kudosity-laravel-client` as split targets.
3. Register both on Packagist.
4. Mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned, replacement set to the new packages.
5. Tag `v2.0.0`. The `v` prefix is required or `split.yml` never fires.
6. Confirm both sub-repo releases appeared and Packagist picked them up.
