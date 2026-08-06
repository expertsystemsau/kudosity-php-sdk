# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP monorepo containing two packages for the Kudosity API:

- **`packages/kudosity-client`** (`expertsystemsau/kudosity-php-client`) - Framework-agnostic PHP client built on Saloon v4
- **`packages/kudosity-laravel`** (`expertsystemsau/kudosity-laravel-client`) - Laravel notification channel integration (supports Laravel 11, 12)

## Common Commands

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run tests with coverage
composer test-coverage

# Run a single test file
vendor/bin/pest tests/ExampleTest.php

# Run a specific test
vendor/bin/pest --filter="test name pattern"

# Static analysis (PHPStan level 6)
composer analyse

# Code formatting (Laravel Pint)
composer format
```

## Architecture

### Core Client (kudosity-client)

Built on Saloon PHP v4:

- **KudosityV1Connector** - Configures the V1 base URL, authentication (Basic Auth), headers, and timeout
- **KudosityV2Connector** - Configures the V2 base URL, authentication (`x-api-key` header, key only), headers, and timeout
- **KudosityClient** - High-level client wrapper holding both connectors, with V1 response validation
- **KudosityV1Request** - Abstract base for V1 API requests (uses form body, all endpoints must end with `.json`)
- **KudosityV2Request** - Abstract base for V2 API requests (no body; paths are written out in full, no suffix). Write requests extend **KudosityV2BodyRequest** instead, which adds the JSON body — kept off the base so GET readers never inherit one.

As of 2.0, the V2 transport, error mapping (`KudosityException::fromV2Response()`),
envelope handling (`Concerns\UnwrapsData`) and both paginators
(`V2PagedPaginator`, `V2CursorPaginator`) exist and are tested. All four V2
messaging channels are wired onto `KudosityClient` and exposed as resources:

- **`sms()` → `Resources\SmsV2Resource`** — single-recipient SMS (`/v2/sms`).
  Not a replacement for V1's old `sms()`: one recipient, no scheduling. Flat
  response envelope; paginates by page.
- **`mms()` → `Resources\MmsResource`** — single-recipient MMS (`/v2/mms`),
  one media file. Flat response envelope.
- **`whatsapp()` → `Resources\WhatsAppResource`** — templates, free-form text
  and custom (media/buttons) content via `Contracts\WhatsAppContent`.
  Response wrapped in `data`; paginates by cursor.
- **`rcs()` → `Resources\RcsResource`** — RCS sends and capability checks;
  `$agentId` is a registered agent ID, never a phone number. Response wrapped
  in `data`; paginates by cursor.

Phase 4 added the remaining two V2 surfaces, plus the protocol safety the
webhook transport needs:

- **`webhooks()` → `Resources\WebhooksResource`** — account-level webhook CRUD
  (`POST/GET/PUT/DELETE /v2/webhook`). Flat response envelope, not paginated.
  `PUT` is a **replace**, so `update()` takes the whole shape. Rejects an
  `http://` URL even though the API accepts one.
- **`senders()` → `Resources\SendersResource`** — sender registrations and the
  SMS verification flow. Response wrapped in `data`, items at
  `data.registrations`, page-based but reporting `meta.pagination.total_count`.
  Registers a **personal mobile number** only; alphanumeric sender IDs, WhatsApp
  senders and RCS agents are not self-service, and a leased virtual number is
  not a registration at all.
- **`Webhooks\WebhookEvent::fromArray()`** — ten event types into four payload
  classes (`StatusEvent`, `InboundEvent`, `LinkHitEvent`, `OptOutEvent`) plus
  `UnknownEvent`, which is returned rather than thrown. `messageRef()` is one
  accessor for a key the API hides at a different path per event type.
- **`Webhooks\StatusPrecedence`** — status events are unordered and
  at-least-once, so a late `SENT` must not overwrite a recorded `DELIVERED`. A
  rank, not a terminal check: `MessageStatus::isTerminal()` is true for both
  `DELIVERED` and `READ`, and an RCS read receipt follows delivery.
- **`Webhooks\SignedMessageRef`** — deliveries are **unsigned** (confirmed by
  Kudosity, 2026-08-06: V2 signing is roadmap, not shipped), so this signs our
  own correlation key. Protects correlation, not the payload. Parse from the
  **last** colon; real refs are composite.
- **`Webhooks\InboundMedia` / `InboundEvent::$media`** — an inbound MMS delivers
  its attachment as **inline base64** under `mo.media[]`. `$contentUrls` reads
  `mo.content_urls`, which is the *outbound* shape and is absent from a real
  `MMS_INBOUND`. Payloads run to hundreds of KB, carry no content type, and
  arrive with no `last_message` — so an MMS reply has no correlation key.

**When writing anything that reads a webhook payload, read
`tests/Fixtures/V2Webhooks/README.md` first.** The fixtures are real captured
deliveries and they record several behaviours the upstream docs contradict or
omit. Likewise `tests/Fixtures/V2Senders/README.md` for what is and is not
verified about the sender item shape.

Phase 5 (Laravel channels, the webhook receiver route, `kudosity:webhook:*`
commands) and Phase 6 (standalone suite, docs, release) remain. See "Two APIs,
two auth schemes" below for how the two APIs fit together, and the client
package README's "V2 channels" section for the per-endpoint envelope table.

### Laravel Integration (kudosity-laravel)

- **KudosityServiceProvider** - Registers singletons for both connectors and the client, extends the notification channel manager with four channels, and registers the Artisan commands. **`KudosityV2Connector` needs its explicit singleton — it cannot autowire**, because `$apiKey` has no default.
- **Kudosity Facade** - Proxies to `KudosityClient`
- **Four notification channels** — `kudosity` (`toKudosity()`), `kudosity-mms` (`toKudosityMms()`), `kudosity-whatsapp` (`toKudosityWhatsApp()`), `kudosity-rcs` (`toKudosityRcs()`), with `KudosityMessage`, `KudosityMmsMessage`, `KudosityWhatsAppMessage` and `KudosityRcsMessage` as builders.
- **The SMS channel routes between APIs.** V2 by default; V1 only when the message uses something V2 cannot express — `toList()`, `sendAt()`, `validity()`, `repliesToEmail()`, any per-send callback **including the `onDlr()`/`onReply()`/`onLinkHit()` handler forms**, or more than one recipient. `KudosityMessage::apiVersion()` reports the decision, `v1Reasons()` explains it, and **`forceV2()` throws rather than dropping a V1-only option**. It returns `Contracts\SentMessage`, not a concrete DTO, so the type is stable across a decision the caller never made.
- **`WebhookController::events()`** — the V2 receiver at `POST {prefix}/events`, dispatching `KudosityStatusReceived` / `KudosityInboundReceived` / `KudosityLinkHitReceived` / `KudosityOptOutReceived`. It is **stricter than `CallbackUrlParser`**: the parser skips verification when no handler is present, which is right for the V1 GET routes and wrong for a route whose only defence is an unguessable URL. The three V1 GET routes remain live for V1 sends.
- **`kudosity:webhook:list` / `:install` / `:delete`** — `install` must build its URL through `CallbackUrlBuilder`, or the receiver refuses the very webhook it registered.

Config published to `config/kudosity.php`. **`base_url` is keyed by API version** (`v1`/`v2`) as of 2.0 — a config still carrying the flat string throws on boot rather than sending V2 traffic to the V1 host. Other keys: `api_key`, `api_secret`, `from`, `country_code`, `timeout`, `mms.sender`, `whatsapp.sender`, `rcs.agent_id`, `webhooks`.

## Namespaces

- `ExpertSystems\Kudosity\` - Core client classes
- `ExpertSystems\Kudosity\Laravel\` - Laravel-specific classes
- `ExpertSystems\Kudosity\Tests\` - Test classes

## Testing

Tests use Pest v4 with Orchestra Testbench for Laravel integration testing. The base `TestCase` class auto-registers the service provider and sets default config values.

## Kudosity API Skills

TransmitSMS is now **Kudosity**. This repo vendors the official Kudosity agent skills — authoritative, per-endpoint API references with request/response shapes, parameter rules, and known gotchas. **Read the relevant skill before writing or changing any request class** rather than inferring the contract from existing code.

- Source of truth: `.agents/skills/<skill>/SKILL.md`
- Exposed to Claude Code via symlinks in `.claude/skills/` (invoke with the Skill tool, e.g. `kudosity-sms`)
- Upstream docs: https://developers.kudosity.com

| Skill | Use when working on |
|---|---|
| `kudosity-setup` | Credentials, auth, senders, debugging 401s |
| `kudosity-sms` | SMS sends, scheduling, link tracking, delivery callbacks |
| `kudosity-mms` | MMS with image/GIF/video/audio attachments |
| `kudosity-rcs` | RCS sends, capability checks, RCS→SMS fallback (agent ID, not a number) |
| `kudosity-whatsapp` | WhatsApp sends, 24-hour service window, SMS fallback, status reads |
| `kudosity-whatsapp-templates` | Template naming, positional params, locales, media headers/buttons |
| `kudosity-contacts-lists` | Lists, members, bulk CSV import, opt-outs |
| `kudosity-webhooks` | Delivery status, inbound replies, link hits, opt-out callbacks |

### Two APIs, two auth schemes

This is the most common source of bugs — the SDK spans both:

| | Base URL | Auth | Covers |
|---|---|---|---|
| **V1** | `api.transmitsms.com` | HTTP Basic (`key:secret`) | Contact lists, bulk/list sends, scheduling, reporting, balance. Endpoints end in `.json` |
| **V2** | `api.transmitmessage.com` | Header `x-api-key: {key}` | Single-recipient SMS, MMS, WhatsApp, RCS, webhooks. Paths under `/v2/` |

The V2 API never uses the API secret; V1 always needs both.
