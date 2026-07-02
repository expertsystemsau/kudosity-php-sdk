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
- **TransmitSmsChannel** - Laravel notification channel (expects `toTransmitSms()` method on notifications)
- **TransmitSmsMessage** - Fluent message builder for notifications

Config file published to `config/transmitsms.php` with keys: `api_key`, `api_secret`, `base_url`, `from`, `timeout`

## Namespaces

- `ExpertSystems\TransmitSms\` - Core client classes
- `ExpertSystems\TransmitSms\Laravel\` - Laravel-specific classes
- `ExpertSystems\TransmitSms\Tests\` - Test classes

## Testing

Tests use Pest v4 with Orchestra Testbench for Laravel integration testing. The base `TestCase` class auto-registers the service provider and sets default config values.
