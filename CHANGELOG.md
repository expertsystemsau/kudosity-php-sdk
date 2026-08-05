# Changelog

All notable changes to `kudosity-php-client` will be documented in this file.

## Unreleased

### Breaking

- Renamed the packages: `expertsystemsau/transmitsms-php-client` is now `expertsystemsau/kudosity-php-client`, and `expertsystemsau/transmitsms-laravel-client` is now `expertsystemsau/kudosity-laravel-client`. The old packages are abandoned and point at the replacements.
- Renamed the namespace `ExpertSystems\TransmitSms\` to `ExpertSystems\Kudosity\`.
- Renamed `TransmitSmsClient` to `KudosityClient`, `TransmitSmsConnector` to `KudosityV1Connector`, `TransmitSmsRequest` to `KudosityV1Request`, `TransmitSmsException` to `KudosityException`, `TransmitSmsPaginator` to `V1PagedPaginator`, `TransmitSmsServiceProvider` to `KudosityServiceProvider`, `TransmitSmsChannel` to `KudosityChannel`, `TransmitSmsMessage` to `KudosityMessage`, and the `TransmitSms` facade to `Kudosity`.
- Renamed the notification method `toTransmitSms()` to `toKudosity()` and the channel string `'transmitsms'` to `'kudosity'`.
- Renamed the config file `config/transmitsms.php` to `config/kudosity.php`, its publish tag `transmitsms-config` to `kudosity-config`, and every `TRANSMITSMS_*` environment variable to `KUDOSITY_*`. The default webhook prefix moved from `webhooks/transmitsms` to `webhooks/kudosity`.
- Renamed `KudosityException::fromResponse()` to `fromV1Response()`, making room for the V2 error format. The identically named factories on the `Data\*` DTOs are unchanged.
- Removed `useSmsUrl()` and `useMmsUrl()` from the client and connector, and the `BASE_URL_MMS` constant. `BASE_URL_SMS` is now `BASE_URL`. Nothing in the SDK ever issued a request against the MMS host; V2 support arrives with a dedicated connector.
- `KudosityClient` now holds two connectors. `v1()` and `v2()` return them; `connector()` still returns the V1 connector. `fromConnector()` takes a V1 connector as before, and `fromConnectors()` accepts either or both. The constructor's `$baseUrl` parameter is replaced by `$v1BaseUrl` and `$v2BaseUrl`, and `$apiSecret` is now optional — omit it for V2-only use. `setBaseUrl()` is now `setV1BaseUrl()`, with the old name delegating to it.
- Removed `KudosityClient::sms()`. The V1 send surface is `bulk()`, the reply readers moved to `reporting()`, and the API-backed `formatNumber()` moved to `numbers()`. `sms()` returns in the next release as the V2 single-recipient API. See UPGRADING.md.
- A V1 call with no API secret now throws `KudosityException` explaining that V1 needs both credentials, instead of failing with a 401 from the API.

### Added

- `rename-map.json` and `bin/kudosity-codemod`, which rewrite a consuming project's class references, notification hook, channel string, config keys, environment variables and composer requirements. Dry-run by default.
- `UPGRADING.md`.
- `KudosityV2Connector` for the V2 API (`api.transmitmessage.com`, `x-api-key`), with `KudosityV2Request` as the JSON-body request base.
- `KudosityException::fromV2Response()` maps V2's RFC 9457 Problem Details onto typed exceptions, adding `NotFoundException` and `ServerException`, and exposes every failed field via `getIssues()`.
- `V2PagedPaginator` and `V2CursorPaginator` for V2's two pagination schemes, selected by the `PaginatesV2Pages` and `PaginatesV2Cursor` contracts.
- `BulkSmsResource::schedule()` makes a scheduled V1 send explicit.
- `Concerns\HasRetryPolicy`, `Concerns\UnwrapsData` and `Concerns\FormatsPhoneNumbers`.
- `KudosityClient::sms()`, `mms()`, `whatsapp()` and `rcs()` — the four V2 channels, each lazily built against `v2()` and returning typed DTOs. `sms()` returns with different semantics than the method 1.x removed: it now wraps `POST /v2/sms`, a single-recipient send with no scheduling — see UPGRADING.md before repointing multi-recipient 1.x `sms()` call sites at it.
- `Resources\SmsV2Resource`, `MmsResource`, `WhatsAppResource` and `RcsResource`, with their request classes under `Requests\V2\`. SMS lists page by page; WhatsApp and RCS lists page by cursor; both go through Phase 2's paginators.
- `Enums\MessageStatus` and `Enums\RcsCapabilityCode` — tolerant enums whose `fromApi()` resolves an undocumented value to `Unknown` rather than throwing, so a client reading its own message history doesn't break when Kudosity adds a status.
- `Contracts\WhatsAppContent` and its three variants, `Data\V2\Content\TextContent`, `TemplateContent` and `CustomContent`, plus `Data\V2\SmsFallback` for the `sms_fallback` object shared by the WhatsApp and RCS send endpoints.
- `Data\V2\SmsMessageData`, `MmsMessageData`, `WhatsAppMessageData`, `RcsMessageData`, `RcsCapabilityData` and `SmsListData` DTOs. SMS and MMS responses are flat; WhatsApp and RCS are wrapped in `data` — both resolved through the same `Concerns\UnwrapsData::payload()` seam.

## 1.9.0 - 2026-07-03

### Fixed

- Paginated iteration silently returned zero items for every endpoint whose response envelope is not keyed `responses`. `TransmitSmsPaginator` hardcoded the `responses` key, so `numbers()->all()`, `lists()->all()`, `keywords()->all()`, and reporting `getSent()`/`getUserSent()` iterated to nothing. Each paginatable request now declares its own key (`numbers`, `lists`, `keywords`, `recipients`, `messages`, `members`, `responses`) via the new `ExpertSystems\TransmitSms\Contracts\PaginatesResults` interface, and the paginator reads it per request. Keys were verified against the official API documentation.
- `lists()->getContacts()` threw `InvalidArgumentException` because `GetListRequest` was not `Paginatable`. It now implements `PaginatesResults` and pages through the list's `members`.

### Changed

- CI: removed the `test-client` matrix (12 jobs) that ran PHPUnit against the client package's empty test directory under `continue-on-error: true`, masking the fact that it ran nothing. The client's classes are covered by the root Pest suite (`test-laravel` job).
- CI: documented why `split.yml` intentionally pins `actions/checkout@v4` — `splitsh-action@v1.0.0` unsets the inline `http.extraheader` auth token that checkout v4 writes, and checkout v5+ stores credentials differently, which breaks the split. It must stay on v4.

## 1.8.0 - 2026-07-03

### Breaking

- `SmsResource::send()` and `sendToList()` replace the positional `repliesToEmail` argument added in 1.7.0 with an optional `configure` closure that receives the `SendSmsRequest` after connector defaults are applied. Migrate `send($msg, $to, $from, $email)` to `send($msg, $to, $from, configure: fn ($r) => $r->repliesToEmail($email))`. The closure also reaches every other request option (callbacks, scheduling, validity, tracked links).
- Dropped Laravel 10 support. The Saloon v4 upgrade (`saloonphp/laravel-plugin ^4.0`) requires Laravel 11+, so the Laravel package now requires `illuminate/* ^11.0||^12.0`. The CI matrix and docs were updated to match.

### Added

- Laravel `TransmitSmsMessage::toList(int $listId)` — send a notification to a TransmitSMS contact list; the channel skips notifiable recipient resolution when a list is set.
- Laravel `TransmitSmsMessage::formatNumbers(bool)` — client-side E.164 number normalisation, wired through `TransmitSmsChannel`.

### Fixed

- Pagination threw `InvalidArgumentException` on every paginated call (`numbers()->all()`, `sms()->getResponses()`/`getAllResponses()`, `lists()->all()`, `keywords()->all()`, reporting `getSent()`/`getUserSent()`); all collection requests now implement Saloon's `Paginatable` interface.

## 1.7.0 - 2026-07-02

Add "repliesToEmail" parameter to send() method.

## v1.6.0 - 2026-01-16

**Full Changelog**: https://github.com/expertsystemsau/transmitsms-php-sdk/compare/v1.5.0...v1.6.0

## v1.5.0 - 2026-01-13

### What's Changed

* Bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/expertsystemsau/transmitsms-php-sdk/pull/16
* Add DLR and reply callbacks to Laravel package by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-sdk/pull/17

**Full Changelog**: https://github.com/expertsystemsau/transmitsms-php-sdk/compare/v1.4.0...v1.5.0

## v1.4.0 - 2026-01-04

### What's Changed

* Add automatic release creation for split packages by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-sdk/pull/13
* Add Packagist badges to README files by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-sdk/pull/14

**Full Changelog**: https://github.com/expertsystemsau/transmitsms-php-sdk/compare/v1.3.0...v1.4.0

## v1.3.0 - 2025-12-09

### What's Changed

* update to split to pakagist by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-sdk/pull/11
* Bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/expertsystemsau/transmitsms-php-sdk/pull/9
* Check splitsh config workflow by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-sdk/pull/12

**Full Changelog**: https://github.com/expertsystemsau/transmitsms-php-sdk/compare/v1.2.0...v1.3.0

## v1.2.0 - 2025-12-08

### What's Changed

* Implement notification channel with SMS support by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-client/pull/10

**Full Changelog**: https://github.com/expertsystemsau/transmitsms-php-client/compare/v1.1.0...v1.2.0

## v1.1.0 - 2025-12-08

### What's Changed

* Implement TransmitSMS notification channel by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-client/pull/8

**Full Changelog**: https://github.com/expertsystemsau/transmitsms-php-client/compare/v1.0.0...v1.1.0

## v1.0.0 - 2025-12-06

### What's Changed

* Refactor to support two packages by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-client/pull/2
* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/expertsystemsau/transmitsms-php-client/pull/1
* Integrate Saloon PHP into TransmitSMS client by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-client/pull/3
* Add Claude Code GitHub Workflow by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-client/pull/4
* Add retry logic and rate limit header extraction by @Copilot in https://github.com/expertsystemsau/transmitsms-php-client/pull/6
* Add SSRF protection for callback URLs and fix phone number integer overflow by @Copilot in https://github.com/expertsystemsau/transmitsms-php-client/pull/7
* Add logic to TransmitSMS client library by @mitchello77 in https://github.com/expertsystemsau/transmitsms-php-client/pull/5

### New Contributors

* @mitchello77 made their first contribution in https://github.com/expertsystemsau/transmitsms-php-client/pull/2
* @dependabot[bot] made their first contribution in https://github.com/expertsystemsau/transmitsms-php-client/pull/1
* @Copilot made their first contribution in https://github.com/expertsystemsau/transmitsms-php-client/pull/6

**Full Changelog**: https://github.com/expertsystemsau/transmitsms-php-client/commits/v1.0.0
