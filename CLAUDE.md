# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP monorepo containing two packages for the TransmitSMS API:

- **`packages/transmitsms-client`** (`expertsystemsau/transmitsms-client`) - Framework-agnostic PHP client built on Saloon v3
- **`packages/transmitsms-laravel`** (`expertsystemsau/transmitsms-laravel`) - Laravel notification channel integration (supports Laravel 11, 12)

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

# Static analysis (PHPStan level 5)
composer analyse

# Code formatting (Laravel Pint)
composer format
```

## Architecture

### Core Client (transmitsms-client)

Built on Saloon PHP v3:

- **TransmitSmsConnector** - Configures base URL, authentication (Basic Auth), headers, and timeout
- **TransmitSmsClient** - High-level client wrapper with response validation
- **TransmitSmsRequest** - Abstract base for API requests (uses form body, all endpoints must end with `.json`)

Two base URLs are supported:
- SMS: `https://api.transmitsms.com`
- MMS: `https://api.transmitmessage.com`

### Laravel Integration (transmitsms-laravel)

- **TransmitSmsServiceProvider** - Registers singletons for `TransmitSmsConnector` and `TransmitSmsClient`, extends notification channel manager
- **TransmitSms Facade** - Proxies to `TransmitSmsClient`
- **TransmitSmsChannel** - Laravel notification channel (expects `toKudosity()` method on notifications)
- **TransmitSmsMessage** - Fluent message builder for notifications

Config file published to `config/transmitsms.php` with keys: `api_key`, `api_secret`, `base_url`, `from`, `timeout`

## Namespaces

- `ExpertSystems\TransmitSms\` - Core client classes
- `ExpertSystems\TransmitSms\Laravel\` - Laravel-specific classes
- `ExpertSystems\TransmitSms\Tests\` - Test classes

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
