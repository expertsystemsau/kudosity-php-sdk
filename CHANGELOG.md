# Changelog

All notable changes to `kudosity-php-client` will be documented in this file.

## 2.0.2 - 2026-08-09

Bug fixes only, no new features and no signature or property changes. Every
defect below was found by installing the published packages into two fresh
applications and driving them against the live API — not by a unit test, which
is the point: six of the seven are one-word field-name mismatches, and a
hand-written fixture encodes the same wrong assumption as the code.

### Fixed

- **`V2PagedPaginator` silently dropped every page after the second.** The API
  reports `total_records` correctly on page 1 and as `"0"` on later pages even
  when they hold items, so `ceil(0/limit)` concluded "last page" one page early
  and the tail of every list was lost with no error raised. Observed live on a
  26-item, three-page read. A total that computes to zero is no longer trusted
  once the current page is known non-empty.
- **`getDeliveryStatus()` could never succeed.** It sent `mobile`; the API
  requires `msisdn` and answered `FIELD_EMPTY` every time.
- **`SmsStatsData` reported `sent` and `optouts` as `0` always.** The API calls
  them `total` and `opt-outs` — hyphenated.
- **`BulkProgressData` reported every count as `0`, and `isComplete()` and
  `isProcessing()` were always `false`.** The API sends `importlength`,
  `completed` and `imported`, and its status strings are `completed` and
  `in progress`. Since polling this endpoint is the only way to learn whether a
  bulk import succeeded, a caller waiting for completion waited forever.
- **`SmsSentItemData` crashed with a `TypeError` on real rows.** The API sends
  `id`, `msisdn` and `sent_at`; the DTO expected `message_id`, `mobile` and
  `send_at`.
- **`MessageReportData::$totalCount` silently reported a page count as an
  account total.** It read `total_count`, which does not exist; the real keys
  are `messages_total` and `sms_total`.
- **V1 error responses escaped `catch (KudosityException)`.** Every resource
  used `dtoOrFail()`, so Saloon wrapped the real exception in its own
  `LogicException` — uncatchable by any documented handler. All six resources
  now route through `sendAndDto()`.

### Changed — behaviour

Three changes are observable. Each replaces wrong data or an uncatchable
failure with correct behaviour, so code that appears to break was already
broken; it simply could not tell.

- **V1 errors now throw `KudosityException`** across every resource, instead of
  Saloon's `LogicException`. A `catch (KudosityException $e)` block that never
  fired before will now fire.
- **`getContactStats()` now throws instead of returning zeros.** The endpoint
  returns a paginated per-message record list, not aggregate counts, so the DTO
  could never represent it and previously handed back `mobile: ""` with every
  count `0` — for every call, not an edge case. The exception explains the real
  shape. A paginated reader is planned for 2.1.0.
- **`getDeliveryStatus()`, `getMessageReport()`, `SmsStatsData` and
  `BulkProgressData` now return real values** where they previously returned
  zeros or crashed.

### Documentation

- **Reply correlation guidance corrected, and it was wrong in a way that
  mattered.** `CLAUDE.md` and the webhook fixture README told readers to route
  replies on `last_message.message_ref` *because* matching on the phone number
  "breaks when one contact is in two flows at once". Verified live: an SMS and
  an MMS sent seconds apart, the customer replied to the SMS, and the inbound
  event named the **MMS**. `last_message` identifies the most recent outbound to
  that number, so it has the same failure mode — and is worse for it, because it
  looks like per-message correlation and fails silently. `SignedMessageRef` does
  not rescue this: it protects the ref's integrity, not its identity.
- **The Laravel README's listener registration double-processed every webhook.**
  It documented explicit `Event::listen()` without noting that Laravel 11 and 12
  auto-discover class-based listeners, so a consumer using a listener class
  received every event twice.

### Known issue

- **Laravel 13 is not supported.** The Laravel package requires
  `illuminate/* ^11.0||^12.0`, so `composer require` on a fresh Laravel 13
  install fails outright. Deliberately not changed in a patch release: widening
  the constraint requires testing against Laravel 13 rather than editing a
  version string.

## 2.0.1 - 2026-08-07

Metadata only — no code changes from 2.0.0.

### Changed

- Each package now declares a `replace` for the 1.x name it was renamed from, so a dependency graph still referencing `expertsystemsau/transmitsms-php-client` or `expertsystemsau/transmitsms-laravel-client` resolves to the 2.x package instead of installing both side by side. Note that the namespace changed in 2.0, so a package requiring the old name is satisfied by the replace but will not find the old classes — run the codemod, and see UPGRADING.md.

## 2.0.0 - 2026-08-07

**Full Changelog**: https://github.com/expertsystemsau/kudosity-php-sdk/compare/v1.9.0...v2.0.0

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
- `KudosityClient::webhooks()` and `senders()`, plus `@method` entries on the facade.
- `Resources\WebhooksResource` — account-level webhook CRUD over `POST/GET/PUT/DELETE /v2/webhook`, with `Data\V2\WebhookData` and `Data\V2\WebhookFilter`. **V2 has no per-send callback URL**, so this replaces V1's `dlr_callback` / `reply_callback` for V2 sends — see UPGRADING.md.
- `Webhooks\WebhookEvent::fromArray()` turns a delivered payload into one of `StatusEvent`, `InboundEvent`, `LinkHitEvent`, `OptOutEvent` or `UnknownEvent`, with `Webhooks\SourceMessage` for the message-shaped object they attach. `messageRef()` is a single accessor for the correlation key, which the API puts at a different path per event type. `raw` keeps the payload verbatim.
- `Enums\WebhookEventType` (ten types), `Enums\OptOutSource`, `Enums\SenderStatus`, `Enums\SenderRegistrationType` and `Enums\SenderVerificationMethod` — all tolerant, all resolving an undocumented value to `Unknown`.
- `Webhooks\StatusPrecedence` — the ordering and idempotency guard for status events. Multiple events fire per message, order is not guaranteed and deliveries are at-least-once, so a late `SENT` must never overwrite a recorded `DELIVERED`. It is a rank rather than a terminal-status check, because an RCS `READ` legitimately follows `DELIVERED`.
- `Webhooks\SignedMessageRef` — signs and verifies the `message_ref`. Kudosity does **not** sign webhook deliveries, so this is the only authenticity signal available to a receiver: it proves a delivery refers to one of your own entities. It protects correlation, not the payload.
- `Resources\SendersResource` — sender registrations and the SMS verification flow, with `Data\V2\SenderRegistrationData`. `SenderStatus::isReadyToUse()` exists because `VERIFIED` means *provisioning*: only `READY_TO_USE` can send. Note this endpoint registers a **personal mobile number** and is not the route to an alphanumeric sender ID, a WhatsApp Business sender or an RCS agent.
- `Concerns\ParsesV2Timestamps` and `Concerns\GuardsMessageRef`, each replacing four verbatim copies. `MAX_MESSAGE_REF_LENGTH` moved onto the latter and remains reachable as `SendSmsV2Request::MAX_MESSAGE_REF_LENGTH` and friends.
- Laravel: `base_url` config is now keyed by API version (`v1`/`v2`), and a config still carrying the flat string throws on boot rather than sending V2 traffic to the V1 host. `KudosityV2Connector` is registered as a singleton — it cannot autowire — and the client is built from both configured connectors, so `kudosity.timeout` now reaches V2.
- Laravel: three new notification channels — `kudosity-mms`, `kudosity-whatsapp` and `kudosity-rcs` — with `KudosityMmsMessage`, `KudosityWhatsAppMessage` and `KudosityRcsMessage`, plus per-channel sender config.
- Laravel: `KudosityChannel` routes to V2 by default and to V1 only for options V2 cannot express, reporting the decision through `KudosityMessage::apiVersion()` and `v1Reasons()`. `forceV2()` throws rather than dropping a V1-only option. **`send()` now returns `?SentMessage`** rather than `?SmsData`.
- Laravel: `POST {prefix}/events` receives all ten V2 webhook event types and dispatches `KudosityStatusReceived`, `KudosityInboundReceived`, `KudosityLinkHitReceived` or `KudosityOptOutReceived`. Authenticated by unguessable URL, since V2 deliveries are unsigned; an unsigned request is refused with 403 even though the V1 parser would allow it. A plaintext `http://` webhook URL is refused unless `allowInsecureUrl: true` is passed; `kudosity:webhook:install` opts in only when `APP_ENV=local`. The three V1 GET callback routes are unchanged.
- `Contracts\SentMessage`, implemented by `Data\SmsData` and `Data\V2\SmsMessageData`, so the SMS channel's return type is stable across its routing decision.
- `V2PagedPaginator` now also reads a total from `meta.pagination.total_count` and prefers the `limit` a response reports. `GET /v2/senders/registrations` is page-based but names its total differently from `GET /v2/sms` and defaults to 25 per page rather than 100.
- `Webhooks\InboundMedia` and `InboundEvent::$media` — an inbound MMS delivers its attachment as **inline base64** under `mo.media[]`, not as URLs. `InboundEvent::$contentUrls` reads `mo.content_urls`, which is the *outbound* request shape and is absent from a real `MMS_INBOUND`, so the picture parsed cleanly and was silently discarded. Captured live on 2026-08-06; see `packages/kudosity-client/tests/Fixtures/V2Webhooks/README.md`. `InboundMedia::mimeType()` sniffs the decoded bytes because the payload carries no content type at all, and `bytes()` returns null rather than throwing on content that will not decode.
- The client package ships its own PHPUnit 11 suite, installed and run standalone on PHP 8.2, 8.3 and 8.4. Previously nothing executed the declared `^8.2` floor — Pest 4 requires 8.3 — and nothing proved the package installs without Laravel. PHPStan now analyses the whole declared range, so 8.3-only syntax fails review rather than a consumer's runtime.
- `Requests\V2\CheckRcsCapabilitiesRequest` now throws `ValidationException` (`FIELD_INVALID`) from its constructor when `$sender` looks like a phone number, matching the guard `SendRcsRequest` already had — `rcs()->capabilities()` takes a registered agent ID, never a number. Previously such a call reached the live API and failed with the accurate but unhelpful `"sender is not owned by this account"`.

### Notes

- **Kudosity confirmed V2 webhook deliveries are unsigned** (2026-08-06). `x-transmitsms-signature` is V1-only and V2 support is on their roadmap; they recommend `message_ref` for correlation, which is what `Webhooks\SignedMessageRef` signs. Whether stable egress IP ranges exist for allowlisting is still an open question with them.
- Inbound MMS now works on a provisioned number. It produced no event at all during Phase 4; Kudosity replaced the account's virtual number, and the first reply to the replacement delivered. A number that sends MMS does not necessarily receive it.

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
