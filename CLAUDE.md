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

Webhook and sender request classes do not exist yet — those arrive in
Phases 4–5. See "Two APIs, two auth schemes" below for how the two APIs fit
together, and the client package README's "V2 channels" section for the
per-endpoint envelope table.

### Laravel Integration (kudosity-laravel)

- **KudosityServiceProvider** - Registers singletons for `KudosityV1Connector` and `KudosityClient`, extends notification channel manager
- **Kudosity Facade** - Proxies to `KudosityClient`
- **KudosityChannel** - Laravel notification channel (expects `toKudosity()` method on notifications)
- **KudosityMessage** - Fluent message builder for notifications

Config file published to `config/kudosity.php` with keys: `api_key`, `api_secret`, `base_url`, `from`, `timeout`, `webhooks`

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
