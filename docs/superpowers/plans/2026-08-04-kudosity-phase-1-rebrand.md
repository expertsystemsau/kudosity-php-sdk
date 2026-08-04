# Kudosity Migration Phase 1: Rebrand Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the monorepo, both packages, the PHP namespace and every TransmitSMS-branded symbol to Kudosity, and ship a codemod so consuming projects can follow with one command — with zero behaviour change.

**Architecture:** Purely mechanical. Directory moves via `git mv`, namespace and symbol rewrites via `sed` over `git ls-files`, then targeted edits for the handful of places a text substitution cannot reach (removed methods, renamed exception factory, config comments). The existing 243-test suite is the correctness oracle: it must stay green at every commit, because nothing about behaviour is meant to change.

**Tech Stack:** PHP 8.2+, Saloon v3/v4, Pest 4 (root suite), PHPStan level 6 via Larastan, Laravel Pint, Orchestra Testbench, GNU sed, git.

## Global Constraints

- Runtime floor stays `"php": "^8.2"` in both package `composer.json` files. Do not raise it.
- Laravel support stays `illuminate/* ^11.0||^12.0`.
- The dev toolchain requires PHP 8.3+ (Pest 4.7 needs `^8.3.0`, PHPUnit 12 needs `>=8.3`). Run all local commands on 8.3 or 8.4.
- `composer analyse` (PHPStan **level 6**) must report `[OK] No errors` at the end of every task.
- `vendor/bin/pint --test` must pass at the end of every task. Run `composer format` to fix.
- The API hostnames are **not** renamed — Kudosity still serves `https://api.transmitsms.com` (V1) and `https://api.transmitmessage.com` (V2). Every occurrence of the literal string `api.transmitsms.com` must survive this plan untouched.
- **Never modify anything under `.agents/skills/`.** Those are vendored upstream Kudosity references; their `transmitsms.com` mentions are correct hostnames, not branding.
- `.github/workflows/split.yml` must keep `actions/checkout@v4`. `splitsh-action@v1.0.0` unsets the inline `http.extraheader` token that v4 writes; checkout v5+ stores credentials differently and the unset aborts the split before any push.
- Release tags must be prefixed `v` (e.g. `v2.0.0`) or `split.yml` never fires.
- Baseline to preserve: **243 tests, 430 assertions passing**; PHPStan clean; `phpstan-baseline.neon` empty.
- `/docs` is in `.gitignore`. Committing anything under `docs/` requires `git add -f`.
- Work on branch `feat/kudosity-v2`, which already exists and holds the design spec.

## Reference

The design spec is at `docs/superpowers/specs/2026-08-04-kudosity-v2-migration-design.md`. Section 1 holds the authoritative rename table.

This plan covers **Phase 1 of 6**. Later phases (V2 core, V2 channels, webhooks and senders, Laravel integration, tests/CI/docs/release) get their own plan documents. Phase 1 deliberately changes no behaviour, so nothing in it depends on the V2 API.

---

## File Structure

### Directory moves

| From | To |
|---|---|
| `packages/transmitsms-client/` | `packages/kudosity-client/` |
| `packages/transmitsms-laravel/` | `packages/kudosity-laravel/` |

### File renames

| From | To | Responsibility |
|---|---|---|
| `packages/kudosity-client/src/TransmitSmsConnector.php` | `KudosityV1Connector.php` | V1 transport: Basic auth, form body, `.json` paths, retry policy |
| `packages/kudosity-client/src/TransmitSmsClient.php` | `KudosityClient.php` | Resource factory and low-level send |
| `packages/kudosity-client/src/Requests/TransmitSmsRequest.php` | `Requests/KudosityV1Request.php` | Abstract base for V1 requests |
| `packages/kudosity-client/src/Exceptions/TransmitSmsException.php` | `Exceptions/KudosityException.php` | Root exception + V1 error-code mapping |
| `packages/kudosity-client/src/Pagination/TransmitSmsPaginator.php` | `Pagination/V1PagedPaginator.php` | V1 `page`/`max` pagination |
| `packages/kudosity-laravel/src/TransmitSmsServiceProvider.php` | `KudosityServiceProvider.php` | Container bindings, channel registration, webhook routes |
| `packages/kudosity-laravel/src/Notifications/TransmitSmsChannel.php` | `Notifications/KudosityChannel.php` | Notification channel |
| `packages/kudosity-laravel/src/Notifications/TransmitSmsMessage.php` | `Notifications/KudosityMessage.php` | Fluent message builder |
| `packages/kudosity-laravel/src/Facades/TransmitSms.php` | `Facades/Kudosity.php` | Facade |
| `packages/kudosity-laravel/config/transmitsms.php` | `config/kudosity.php` | Published config |
| `tests/Unit/TransmitSmsChannelTest.php` | `tests/Unit/KudosityChannelTest.php` | Channel tests |
| `tests/Unit/TransmitSmsMessageTest.php` | `tests/Unit/KudosityMessageTest.php` | Message builder tests |
| `tests/Unit/TransmitSmsServiceProviderTest.php` | `tests/Unit/KudosityServiceProviderTest.php` | Provider tests |

### New files

| Path | Responsibility |
|---|---|
| `rename-map.json` | The rename table as machine-readable data. Single source of truth for the codemod, the Rector config and `UPGRADING.md`. |
| `bin/kudosity-codemod` | Dependency-free PHP CLI that applies `rename-map.json` to a consuming project. Dry-run by default. |
| `tests/Unit/CodemodTest.php` | Tests for the codemod against a temp fixture project. |
| `UPGRADING.md` | Upgrade guide. Phase 1 writes the rename, config, env and removed-API sections; later phases append theirs. |

### Modified (not renamed)

| Path | Change |
|---|---|
| `composer.json` (root) | Package name, repository paths, PSR-4 prefixes and paths, `extra.laravel` provider/alias |
| `packages/kudosity-client/composer.json` | Package name, description, keywords, homepage, PSR-4 |
| `packages/kudosity-laravel/composer.json` | Package name, description, keywords, homepage, PSR-4, client dependency name, `extra.laravel` |
| `phpunit.xml.dist` | `<source>` directory paths |
| `phpstan.neon.dist` | `paths`, the `toKudosity` ignore rule and its file path |
| `.github/workflows/split.yml` | Matrix package names, paths and split targets |
| `.github/dependabot.yml` | Composer `directory` entries |
| `packages/kudosity-laravel/routes/webhooks.php` | Config keys and route names |
| `tests/Pest.php`, `tests/TestCase.php` | Namespace, provider class, config keys |
| `tests/ArchTest.php` | Namespace and client class assertions |
| `README.md`, both package `README.md`s, `CHANGELOG.md`, `CLAUDE.md` | Branding, namespaces, install instructions |

---

## Task 1: Move package directories and rewrite the namespace

Everything in one commit, because a namespace rewrite is not separable — the Laravel package imports the client's namespace, so a half-done rename does not autoload.

**Files:**
- Move: `packages/transmitsms-client/` → `packages/kudosity-client/`
- Move: `packages/transmitsms-laravel/` → `packages/kudosity-laravel/`
- Modify: every tracked `*.php` (namespace declarations and `use` statements)
- Modify: `composer.json`, `packages/*/composer.json`, `phpunit.xml.dist`, `phpstan.neon.dist`, `.github/dependabot.yml`, `.github/workflows/split.yml`

**Interfaces:**
- Consumes: nothing.
- Produces: the namespaces `ExpertSystems\Kudosity\`, `ExpertSystems\Kudosity\Laravel\` and `ExpertSystems\Kudosity\Tests\`; the package names `expertsystemsau/kudosity-php-client` and `expertsystemsau/kudosity-laravel-client`; the paths `packages/kudosity-client/` and `packages/kudosity-laravel/`. Every later task and phase uses these.

- [ ] **Step 1: Record the baseline so any regression is unambiguous**

```bash
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
```

Expected: `Tests: 243 passed (430 assertions)` and `[OK] No errors`. If either differs, stop and report — the plan assumes this baseline.

- [ ] **Step 2: Move both package directories**

```bash
git mv packages/transmitsms-client packages/kudosity-client
git mv packages/transmitsms-laravel packages/kudosity-laravel
```

- [ ] **Step 3: Rewrite the PHP namespace across every tracked PHP file**

Note the pattern has **no trailing backslash**. `tests/ArchTest.php` contains `'ExpertSystems\TransmitSms'` with nothing after it, and a pattern requiring a trailing separator would silently skip it.

```bash
git ls-files -z '*.php' | xargs -0 sed -i 's/ExpertSystems\\TransmitSms/ExpertSystems\\Kudosity/g'
```

- [ ] **Step 4: Rewrite the JSON-escaped namespace in the composer manifests**

`composer.json` stores the prefix with doubled backslashes (`"ExpertSystems\\TransmitSms\\"`), so the PHP pass in step 3 did not touch it.

```bash
sed -i 's/ExpertSystems\\\\TransmitSms/ExpertSystems\\\\Kudosity/g' \
  composer.json packages/kudosity-client/composer.json packages/kudosity-laravel/composer.json
```

- [ ] **Step 5: Repoint every package path reference**

```bash
sed -i 's#packages/transmitsms-client#packages/kudosity-client#g; s#packages/transmitsms-laravel#packages/kudosity-laravel#g' \
  composer.json phpunit.xml.dist phpstan.neon.dist .github/dependabot.yml .github/workflows/split.yml
```

- [ ] **Step 6: Rename the composer packages**

This also fixes the `homepage` URLs, which embed the same slugs.

```bash
sed -i \
  -e 's#expertsystemsau/transmitsms-php-sdk#expertsystemsau/kudosity-php-sdk#g' \
  -e 's#expertsystemsau/transmitsms-php-client#expertsystemsau/kudosity-php-client#g' \
  -e 's#expertsystemsau/transmitsms-laravel-client#expertsystemsau/kudosity-laravel-client#g' \
  composer.json packages/kudosity-client/composer.json packages/kudosity-laravel/composer.json .github/workflows/split.yml
```

- [ ] **Step 7: Update the two remaining split.yml matrix labels**

`sed` in step 5 fixed the `path:` values; the `name:` values are separate. Edit `.github/workflows/split.yml` so the matrix reads:

```yaml
        package:
          - name: kudosity-client
            path: packages/kudosity-client
            target: expertsystemsau/kudosity-php-client
          - name: kudosity-laravel
            path: packages/kudosity-laravel
            target: expertsystemsau/kudosity-laravel-client
```

Leave the `actions/checkout@v4` pin and its explanatory comment exactly as they are.

- [ ] **Step 8: Rebuild the autoloader**

The root `composer.json` autoloads the package `src/` directories directly by path, so a dump is enough — no `composer update` is needed.

```bash
composer dump-autoload
```

Expected: `Generated optimized autoload files` with no "does not comply with psr-4" warnings. A warning here means a namespace declaration and its directory disagree — fix it before continuing.

- [ ] **Step 9: Verify nothing broke**

```bash
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: 243 passed, `[OK] No errors`, Pint clean. Run `composer format` if Pint objects.

- [ ] **Step 10: Confirm the API hostnames survived**

```bash
git grep -c 'api\.transmitsms\.com' -- packages
```

Expected: a hit in `packages/kudosity-client/src/TransmitSmsConnector.php` (still its old filename until Task 2). Zero hits means step 3 or 6 ate a hostname; revert and narrow the pattern.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "refactor: move packages and rewrite namespace to ExpertSystems\\Kudosity"
```

---

## Task 2: Rename the core client classes

**Files:**
- Rename: `packages/kudosity-client/src/TransmitSmsConnector.php` → `KudosityV1Connector.php`
- Rename: `packages/kudosity-client/src/TransmitSmsClient.php` → `KudosityClient.php`
- Rename: `packages/kudosity-client/src/Requests/TransmitSmsRequest.php` → `Requests/KudosityV1Request.php`
- Rename: `packages/kudosity-client/src/Exceptions/TransmitSmsException.php` → `Exceptions/KudosityException.php`
- Rename: `packages/kudosity-client/src/Pagination/TransmitSmsPaginator.php` → `Pagination/V1PagedPaginator.php`
- Modify: every tracked `*.php` referencing those five symbols (43, 39, 44, 113 and 30 occurrences respectively)

**Interfaces:**
- Consumes: the `ExpertSystems\Kudosity\` namespace from Task 1.
- Produces:
  - `ExpertSystems\Kudosity\KudosityV1Connector` with `public const BASE_URL = 'https://api.transmitsms.com';` and no `BASE_URL_MMS`, `useSmsUrl()` or `useMmsUrl()`.
  - `ExpertSystems\Kudosity\KudosityClient` with `__construct(string $apiKey, string $apiSecret, string $baseUrl = KudosityV1Connector::BASE_URL, int $timeout = 30)`, `fromConnector(KudosityV1Connector $connector): self`, `connector(): KudosityV1Connector`, `setBaseUrl(string $baseUrl): self`, and no `useSmsUrl()`/`useMmsUrl()`.
  - `ExpertSystems\Kudosity\Requests\KudosityV1Request` (abstract).
  - `ExpertSystems\Kudosity\Exceptions\KudosityException` with `public static function fromV1Response(Response $response): self`.
  - `ExpertSystems\Kudosity\Pagination\V1PagedPaginator`.
- Phase 2 adds `KudosityV2Connector`, `KudosityV2Request` and `KudosityException::fromV2Response()` alongside these.

- [ ] **Step 1: Rename the five files**

```bash
cd packages/kudosity-client/src
git mv TransmitSmsConnector.php KudosityV1Connector.php
git mv TransmitSmsClient.php KudosityClient.php
git mv Requests/TransmitSmsRequest.php Requests/KudosityV1Request.php
git mv Exceptions/TransmitSmsException.php Exceptions/KudosityException.php
git mv Pagination/TransmitSmsPaginator.php Pagination/V1PagedPaginator.php
cd -
```

- [ ] **Step 2: Rewrite the five symbols everywhere**

`\b` word boundaries matter: without them `TransmitSmsClient` would also match inside `TransmitSmsClientTest`, and the substitution order would corrupt it.

```bash
git ls-files -z '*.php' | xargs -0 sed -i \
  -e 's/\bTransmitSmsConnector\b/KudosityV1Connector/g' \
  -e 's/\bTransmitSmsClient\b/KudosityClient/g' \
  -e 's/\bTransmitSmsRequest\b/KudosityV1Request/g' \
  -e 's/\bTransmitSmsException\b/KudosityException/g' \
  -e 's/\bTransmitSmsPaginator\b/V1PagedPaginator/g'
```

- [ ] **Step 3: Run the tests to see what the mechanical pass could not fix**

```bash
vendor/bin/pest --compact
```

Expected: still 243 passed. The renames are consistent, so this should pass before the manual edits below — the manual edits are removals and a method rename, not repairs.

- [ ] **Step 4: Collapse the base-URL constants in `KudosityV1Connector.php`**

Replace the two constants with one. In `packages/kudosity-client/src/KudosityV1Connector.php`:

```php
    public const BASE_URL = 'https://api.transmitsms.com';
```

Delete `BASE_URL_SMS`, `BASE_URL_MMS`, and both toggle methods:

```php
    // DELETE these three members:
    // public const BASE_URL_MMS = 'https://api.transmitmessage.com';
    // public function useSmsUrl(): self { ... }
    // public function useMmsUrl(): self { ... }
```

Update the constructor default:

```php
    public function __construct(
        protected string $apiKey,
        protected string $apiSecret,
        protected string $baseUrl = self::BASE_URL,
        protected int $timeout = 30,
    ) {}
```

The MMS host moves to `KudosityV2Connector` in Phase 2; nothing in this SDK ever sent an MMS request to it, so removing the toggle removes dead code rather than a feature.

- [ ] **Step 5: Remove the matching passthroughs from `KudosityClient.php`**

Delete the `useSmsUrl()` and `useMmsUrl()` methods and the `URL Configuration` comment banner above them. Keep `setBaseUrl()`. Update the constructor default and the `$baseUrl` docblock:

```php
    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $baseUrl = KudosityV1Connector::BASE_URL,
        int $timeout = 30,
    ) {
```

- [ ] **Step 6: Rename the exception factory to `fromV1Response()`**

Find the call sites — there are three, and the DTO classes have an unrelated `fromResponse()` that must not be touched:

```bash
git grep -n 'KudosityException::fromResponse'
```

Expected output (three lines):

```
packages/kudosity-client/src/KudosityClient.php:      throw KudosityException::fromResponse($response);
packages/kudosity-client/src/KudosityV1Connector.php: return Exceptions\KudosityException::fromResponse($response);
packages/kudosity-client/src/Exceptions/KudosityException.php: (the declaration)
```

Rename the declaration in `Exceptions/KudosityException.php`:

```php
    public static function fromV1Response(Response $response): self
```

and update its docblock first line to:

```php
     * Create an exception from a V1 API response.
```

Then update both call sites to `fromV1Response($response)`. Leave `RateLimitException::fromResponseWithMetadata()` alone — different method, still correct.

- [ ] **Step 7: Fix the remaining references in test files**

```bash
git grep -n 'fromResponse' -- tests
```

For every hit that is on `KudosityException` (not on a `*Data` DTO), change it to `fromV1Response`.

- [ ] **Step 8: Verify**

```bash
composer dump-autoload
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: 243 passed, `[OK] No errors`, Pint clean.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor: rename core client classes to Kudosity, drop base-URL toggles"
```

---

## Task 3: Rename the Laravel classes, config, env keys and notification hook

**Files:**
- Rename: `packages/kudosity-laravel/src/TransmitSmsServiceProvider.php` → `KudosityServiceProvider.php`
- Rename: `packages/kudosity-laravel/src/Notifications/TransmitSmsChannel.php` → `Notifications/KudosityChannel.php`
- Rename: `packages/kudosity-laravel/src/Notifications/TransmitSmsMessage.php` → `Notifications/KudosityMessage.php`
- Rename: `packages/kudosity-laravel/src/Facades/TransmitSms.php` → `Facades/Kudosity.php`
- Rename: `packages/kudosity-laravel/config/transmitsms.php` → `config/kudosity.php`
- Rename: `tests/Unit/TransmitSmsChannelTest.php` → `tests/Unit/KudosityChannelTest.php`
- Rename: `tests/Unit/TransmitSmsMessageTest.php` → `tests/Unit/KudosityMessageTest.php`
- Rename: `tests/Unit/TransmitSmsServiceProviderTest.php` → `tests/Unit/KudosityServiceProviderTest.php`
- Modify: `packages/kudosity-laravel/routes/webhooks.php`, `composer.json`, `packages/kudosity-laravel/composer.json`, `phpstan.neon.dist`, `tests/TestCase.php`

**Interfaces:**
- Consumes: `KudosityClient`, `KudosityV1Connector` from Task 2.
- Produces:
  - `ExpertSystems\Kudosity\Laravel\KudosityServiceProvider`
  - `ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel`
  - `ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage`
  - `ExpertSystems\Kudosity\Laravel\Facades\Kudosity` (facade alias `Kudosity`)
  - Config namespace `kudosity`, published as `config/kudosity.php` under the tag `kudosity-config`
  - Env prefix `KUDOSITY_`
  - Container aliases `kudosity` and `kudosity.connector`
  - Notification channel string `kudosity`; notification method `toKudosity()`; notifiable route method `routeNotificationForKudosity()`
  - Route names `kudosity.webhooks.dlr`, `kudosity.webhooks.reply`, `kudosity.webhooks.link-hits`
  - Default webhook prefix `webhooks/kudosity`
- Phase 5 adds the three extra channels (`kudosity-mms`, `kudosity-whatsapp`, `kudosity-rcs`) and splits `base_url` into a `v1`/`v2` array. Phase 1 leaves `base_url` as a scalar.

- [ ] **Step 1: Rename the files**

```bash
cd packages/kudosity-laravel
git mv src/TransmitSmsServiceProvider.php src/KudosityServiceProvider.php
git mv src/Notifications/TransmitSmsChannel.php src/Notifications/KudosityChannel.php
git mv src/Notifications/TransmitSmsMessage.php src/Notifications/KudosityMessage.php
git mv src/Facades/TransmitSms.php src/Facades/Kudosity.php
git mv config/transmitsms.php config/kudosity.php
cd -
git mv tests/Unit/TransmitSmsChannelTest.php tests/Unit/KudosityChannelTest.php
git mv tests/Unit/TransmitSmsMessageTest.php tests/Unit/KudosityMessageTest.php
git mv tests/Unit/TransmitSmsServiceProviderTest.php tests/Unit/KudosityServiceProviderTest.php
```

- [ ] **Step 2: Rewrite the class symbols**

`TransmitSmsChannel` and `TransmitSmsMessage` must be substituted before the bare `TransmitSms` facade name, or the facade pattern would chew the prefix off them. `sed -e` applies expressions in order per line, so the ordering below is load-bearing.

```bash
git ls-files -z '*.php' | xargs -0 sed -i \
  -e 's/\bTransmitSmsServiceProvider\b/KudosityServiceProvider/g' \
  -e 's/\bTransmitSmsChannel\b/KudosityChannel/g' \
  -e 's/\bTransmitSmsMessage\b/KudosityMessage/g' \
  -e 's/\bTransmitSms\b/Kudosity/g'
```

- [ ] **Step 3: Rewrite config keys, env keys, the channel string and the method hook**

The pathspec excludes are mandatory, and all three earn their place:

- `.agents/skills/` is vendored upstream reference material and must never be rewritten.
- `CHANGELOG.md` documents the old names on purpose — rewriting it falsifies released history.
- `docs/` holds tracked files that quote the old names deliberately. `docs/Transmit SMS.postman_collection.json` embeds the V1 hostname as a JSON host array, where `"transmitsms"` is a path segment — rewriting it yields the non-existent `api.kudosity.com` and breaks the hostname-survival constraint in 122 places. `docs/superpowers/specs/` and `docs/superpowers/plans/` hold this migration's own before/after tables, which a rename collapses into no-op pairs like `"toKudosity": "toKudosity"`. (`docs/` is in `.gitignore`, but these three files were force-added and so are tracked — `git ls-files` returns them.)

```bash
git ls-files -z '*.php' '*.json' '*.yml' '*.md' \
  ':(exclude).agents/*' ':(exclude)CHANGELOG.md' ':(exclude)docs/*' | xargs -0 sed -i \
  -e 's/\btoTransmitSms\b/toKudosity/g' \
  -e 's/\brouteNotificationForTransmitsms\b/routeNotificationForKudosity/g' \
  -e 's/\bTRANSMITSMS_/KUDOSITY_/g' \
  -e "s/'transmitsms'/'kudosity'/g" \
  -e 's/"transmitsms"/"kudosity"/g' \
  -e "s/'transmitsms\./'kudosity./g" \
  -e 's/"transmitsms\./"kudosity./g' \
  -e "s/'transmitsms-config'/'kudosity-config'/g" \
  -e 's#webhooks/transmitsms#webhooks/kudosity#g' \
  -e 's/transmitsms\.connector/kudosity.connector/g' \
  -e 's/transmitsms\.webhooks\./kudosity.webhooks./g'
```

- [ ] **Step 4: Fix the config filename references in the service provider**

`sed` cannot see inside the `__DIR__` concatenations. In `packages/kudosity-laravel/src/KudosityServiceProvider.php`:

```php
        $this->mergeConfigFrom(
            __DIR__.'/../config/kudosity.php',
            'kudosity'
        );
```

and:

```php
            $this->publishes([
                __DIR__.'/../config/kudosity.php' => config_path('kudosity.php'),
            ], 'kudosity-config');
```

- [ ] **Step 5: Fix the remaining prose in the service provider**

The signing-key error message still says TransmitSMS. Replace it with:

```php
            throw new \RuntimeException(
                'Kudosity webhook signing key is not configured. '.
                'Set KUDOSITY_SIGNING_KEY in your .env file or ensure APP_KEY is set.'
            );
```

- [ ] **Step 6: Fix the composer `extra.laravel` alias in both manifests**

In `composer.json` and `packages/kudosity-laravel/composer.json` the alias key is a bare string that step 3 may have left as `TransmitSms`. Both must read:

```json
    "extra": {
        "laravel": {
            "providers": [
                "ExpertSystems\\Kudosity\\Laravel\\KudosityServiceProvider"
            ],
            "aliases": {
                "Kudosity": "ExpertSystems\\Kudosity\\Laravel\\Facades\\Kudosity"
            }
        }
    }
```

- [ ] **Step 7: Update the PHPStan ignore rule**

In `phpstan.neon.dist` the rule references the old method and file name. It must read:

```yaml
        # toKudosity is a notification convention method
        -
            message: "#Call to an undefined method Illuminate\\\\Notifications\\\\Notification::toKudosity#"
            path: packages/kudosity-laravel/src/Notifications/KudosityChannel.php
```

- [ ] **Step 8: Fix the config file's own comments**

`packages/kudosity-laravel/config/kudosity.php` documents an MMS base URL that Task 2 removed. Replace the `API Base URL` block's comment body with:

```php
    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the Kudosity V1 API (contact lists, bulk and scheduled
    | sends, reporting, balance). Override only to point at a proxy or a test
    | double.
    |
    */
    'base_url' => env('KUDOSITY_BASE_URL', 'https://api.transmitsms.com'),
```

Also replace every remaining "TransmitSMS" in the file's prose with "Kudosity", and update the webhook block's example URLs to `{APP_URL}/webhooks/kudosity/dlr` and siblings.

- [ ] **Step 9: Sweep for anything the substitutions missed**

```bash
git grep -in transmitsms -- packages tests | grep -v 'api\.transmitsms\.com'
```

Expected: **no output.** Every hit is a real miss — usually prose in a docblock, or a `developer.transmitsms.com` `@see` link. Fix each one; point stale doc links at `https://developers.kudosity.com`.

- [ ] **Step 10: Verify**

```bash
composer dump-autoload
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: 243 passed, `[OK] No errors`, Pint clean. A failing `KudosityServiceProviderTest` almost always means a config key or publish tag was missed in steps 3–4.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "refactor: rename Laravel classes, config, env keys and notification hook to Kudosity"
```

---

## Task 4: Rename map and codemod

Consumers need a one-command upgrade. The map is data so the codemod, the Rector config and `UPGRADING.md` cannot drift apart.

**Files:**
- Create: `rename-map.json`
- Create: `bin/kudosity-codemod`
- Test: `tests/Unit/CodemodTest.php`

**Interfaces:**
- Consumes: every rename produced by Tasks 1–3.
- Produces: `rename-map.json` with the top-level keys `version`, `namespaces`, `classes`, `methods`, `strings`, `env`, `packages`, `files`; and the executable `bin/kudosity-codemod` with the usage `kudosity-codemod <path> [--write]`. Task 5 reads `rename-map.json` to generate the Rector snippet in `UPGRADING.md`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/CodemodTest.php`:

```php
<?php

declare(strict_types=1);

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/kudosity-codemod-'.bin2hex(random_bytes(6));
    mkdir($this->project.'/app/Notifications', 0777, true);
    mkdir($this->project.'/config', 0777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->project));
});

function runCodemod(string $path, bool $write = true): string
{
    // Not base_path() — under Testbench that resolves to the skeleton app,
    // not this repository. Walk up from tests/Unit/ instead.
    $codemod = dirname(__DIR__, 2).'/bin/kudosity-codemod';

    $cmd = escapeshellcmd(PHP_BINARY).' '.escapeshellarg($codemod)
        .' '.escapeshellarg($path).($write ? ' --write' : '');

    exec($cmd.' 2>&1', $output, $status);

    expect($status)->toBe(0, implode("\n", $output));

    return implode("\n", $output);
}

it('rewrites namespaces, classes, the notification hook and the channel string', function () {
    file_put_contents($this->project.'/app/Notifications/OrderShipped.php', <<<'PHP'
        <?php

        namespace App\Notifications;

        use ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsMessage;
        use Illuminate\Notifications\Notification;

        class OrderShipped extends Notification
        {
            public function via($notifiable): array
            {
                return ['transmitsms'];
            }

            public function toTransmitSms($notifiable): TransmitSmsMessage
            {
                return TransmitSmsMessage::create('Shipped')->from(config('transmitsms.from'));
            }
        }
        PHP);

    runCodemod($this->project);

    $result = file_get_contents($this->project.'/app/Notifications/OrderShipped.php');

    expect($result)
        ->toContain('use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;')
        ->toContain("return ['kudosity'];")
        ->toContain('public function toKudosity($notifiable): KudosityMessage')
        ->toContain("KudosityMessage::create('Shipped')")
        ->toContain("config('kudosity.from')")
        ->not->toContain('TransmitSms');
});

it('rewrites env keys without touching unrelated ones', function () {
    file_put_contents($this->project.'/.env', <<<'ENV'
        APP_NAME=Demo
        TRANSMITSMS_API_KEY=abc
        TRANSMITSMS_FROM=MyBrand
        OTHER_TRANSMIT_KEY=keepme
        ENV);

    runCodemod($this->project);

    expect(file_get_contents($this->project.'/.env'))
        ->toContain('KUDOSITY_API_KEY=abc')
        ->toContain('KUDOSITY_FROM=MyBrand')
        ->toContain('OTHER_TRANSMIT_KEY=keepme')
        ->not->toContain('TRANSMITSMS_');
});

it('rewrites the composer requirement', function () {
    file_put_contents($this->project.'/composer.json', json_encode([
        'require' => ['expertsystemsau/transmitsms-laravel-client' => '^1.9'],
    ], JSON_PRETTY_PRINT));

    runCodemod($this->project);

    expect(file_get_contents($this->project.'/composer.json'))
        ->toContain('expertsystemsau/kudosity-laravel-client');
});

it('preserves the V1 API hostname', function () {
    file_put_contents($this->project.'/config/services.php', <<<'PHP'
        <?php

        return ['base' => 'https://api.transmitsms.com'];
        PHP);

    runCodemod($this->project);

    expect(file_get_contents($this->project.'/config/services.php'))
        ->toContain('https://api.transmitsms.com');
});

it('changes nothing without --write and reports what it would change', function () {
    $file = $this->project.'/app/Notifications/OrderShipped.php';
    file_put_contents($file, '<?php use ExpertSystems\TransmitSms\TransmitSmsClient;');

    $output = runCodemod($this->project, write: false);

    expect(file_get_contents($file))->toContain('TransmitSmsClient')
        ->and($output)->toContain('OrderShipped.php')
        ->and($output)->toContain('dry run');
});

it('skips vendor and .git directories', function () {
    mkdir($this->project.'/vendor/acme', 0777, true);
    $vendored = $this->project.'/vendor/acme/Thing.php';
    file_put_contents($vendored, '<?php use ExpertSystems\TransmitSms\TransmitSmsClient;');

    runCodemod($this->project);

    expect(file_get_contents($vendored))->toContain('TransmitSmsClient');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/CodemodTest.php`
Expected: FAIL — every case errors because `bin/kudosity-codemod` does not exist, so `exec` returns a non-zero status.

- [ ] **Step 3: Write the rename map**

Create `rename-map.json`. Order matters inside `classes` and `strings`: longer keys first, so `TransmitSmsMessage` is consumed before the bare `TransmitSms`.

```json
{
  "version": "2.0.0",
  "namespaces": {
    "ExpertSystems\\TransmitSms\\Laravel\\": "ExpertSystems\\Kudosity\\Laravel\\",
    "ExpertSystems\\TransmitSms\\": "ExpertSystems\\Kudosity\\"
  },
  "classes": {
    "TransmitSmsServiceProvider": "KudosityServiceProvider",
    "TransmitSmsConnector": "KudosityV1Connector",
    "TransmitSmsPaginator": "V1PagedPaginator",
    "TransmitSmsException": "KudosityException",
    "TransmitSmsRequest": "KudosityV1Request",
    "TransmitSmsChannel": "KudosityChannel",
    "TransmitSmsMessage": "KudosityMessage",
    "TransmitSmsClient": "KudosityClient",
    "TransmitSms": "Kudosity"
  },
  "methods": {
    "toTransmitSms": "toKudosity",
    "routeNotificationForTransmitsms": "routeNotificationForKudosity",
    "fromResponse": null
  },
  "strings": {
    "'transmitsms'": "'kudosity'",
    "\"transmitsms\"": "\"kudosity\"",
    "'transmitsms.": "'kudosity.",
    "\"transmitsms.": "\"kudosity.",
    "webhooks/transmitsms": "webhooks/kudosity",
    "transmitsms-config": "kudosity-config"
  },
  "env": {
    "TRANSMITSMS_": "KUDOSITY_"
  },
  "packages": {
    "expertsystemsau/transmitsms-php-client": "expertsystemsau/kudosity-php-client",
    "expertsystemsau/transmitsms-laravel-client": "expertsystemsau/kudosity-laravel-client"
  },
  "files": {
    "config/transmitsms.php": "config/kudosity.php"
  },
  "preserve": [
    "api.transmitsms.com",
    "api.transmitmessage.com"
  ]
}
```

`methods.fromResponse` is `null` on purpose: `KudosityException::fromResponse()` became `fromV1Response()`, but `fromResponse()` also exists on every `*Data` DTO and must not be renamed. A `null` value means "documented in UPGRADING.md, not automated". The codemod skips null entries and prints a warning naming the file so a human can look.

- [ ] **Step 4: Write the codemod**

Create `bin/kudosity-codemod`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rewrites a project's references from the TransmitSMS SDK (1.x) to the
 * Kudosity SDK (2.x), driven by rename-map.json.
 *
 * Usage: kudosity-codemod <path-to-project> [--write]
 *
 * Without --write it reports what it would change and exits 0.
 */

const EXTENSIONS = ['php', 'json', 'yml', 'yaml', 'md', 'blade.php'];
const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache', 'public/build'];

$args = array_slice($argv, 1);
$write = in_array('--write', $args, true);
$paths = array_values(array_filter($args, static fn (string $a): bool => ! str_starts_with($a, '--')));

if (count($paths) !== 1) {
    fwrite(STDERR, "Usage: kudosity-codemod <path-to-project> [--write]\n");
    exit(2);
}

$root = realpath($paths[0]);

if ($root === false || ! is_dir($root)) {
    fwrite(STDERR, "Not a directory: {$paths[0]}\n");
    exit(2);
}

$mapPath = __DIR__.'/../rename-map.json';
$map = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);

/** Ordered replacement pairs. Namespaces first, then classes, then everything else. */
$replacements = [];

foreach (['namespaces', 'classes', 'methods', 'strings', 'env', 'packages'] as $group) {
    foreach ($map[$group] as $from => $to) {
        if ($to === null) {
            continue;   // documented manually — see UPGRADING.md
        }
        $replacements[] = [$group, $from, $to];
    }
}

$changed = [];
$manual = [];

foreach (files($root) as $file) {
    $original = (string) file_get_contents($file);
    $updated = $original;

    foreach ($replacements as [$group, $from, $to]) {
        $updated = in_array($group, ['classes', 'methods'], true)
            ? preg_replace('/\b'.preg_quote($from, '/').'\b/', $to, $updated)
            : str_replace($from, $to, $updated);
    }

    // Guard: never rewrite a preserved literal.
    foreach ($map['preserve'] as $literal) {
        if (str_contains($original, $literal) && ! str_contains($updated, $literal)) {
            fwrite(STDERR, "Refusing to rewrite preserved literal '{$literal}' in {$file}\n");
            exit(1);
        }
    }

    foreach (array_keys(array_filter($map['methods'], static fn ($v): bool => $v === null)) as $needsHuman) {
        if (str_contains($original, $needsHuman.'(')) {
            $manual[] = [$file, $needsHuman];
        }
    }

    if ($updated === $original) {
        continue;
    }

    $changed[] = [$file, countDiff($original, $updated)];

    if ($write) {
        file_put_contents($file, $updated);
    }
}

$relative = static fn (string $f): string => ltrim(substr($f, strlen($root)), DIRECTORY_SEPARATOR);

foreach ($changed as [$file, $lines]) {
    printf("%s %s (%d line%s)\n", $write ? 'updated' : 'would update', $relative($file), $lines, $lines === 1 ? '' : 's');
}

foreach ($map['files'] as $from => $to) {
    if (is_file($root.'/'.$from)) {
        printf("%s rename %s -> %s\n", $write ? 'TODO:' : 'would need:', $from, $to);
    }
}

foreach ($manual as [$file, $symbol]) {
    printf("review by hand: %s uses %s() — see UPGRADING.md\n", $relative($file), $symbol);
}

printf(
    "\n%d file%s %s.\n",
    count($changed),
    count($changed) === 1 ? '' : 's',
    $write ? 'updated' : 'would change (dry run — pass --write to apply)'
);

exit(0);

/**
 * @return iterable<string>
 */
function files(string $root): iterable
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($root): bool {
                if (! $current->isDir()) {
                    return true;
                }
                $rel = str_replace('\\', '/', ltrim(substr($current->getPathname(), strlen($root)), '/\\'));

                return ! in_array($rel, SKIP_DIRS, true) && ! in_array($current->getFilename(), SKIP_DIRS, true);
            }
        )
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $name = $file->getFilename();

        if (str_starts_with($name, '.env')) {
            yield $file->getPathname();

            continue;
        }

        foreach (EXTENSIONS as $ext) {
            if (str_ends_with($name, '.'.$ext)) {
                yield $file->getPathname();

                continue 2;
            }
        }
    }
}

function countDiff(string $before, string $after): int
{
    $a = explode("\n", $before);
    $b = explode("\n", $after);
    $n = 0;

    foreach ($a as $i => $line) {
        if (($b[$i] ?? null) !== $line) {
            $n++;
        }
    }

    return $n;
}
```

Make it executable:

```bash
chmod +x bin/kudosity-codemod
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/CodemodTest.php`
Expected: PASS, 6 tests.

If the "preserves the V1 API hostname" case fails, the `strings` map is matching inside `api.transmitsms.com` — the `preserve` guard is doing its job and the map entry needs tightening (quote-anchored keys, as written above).

- [ ] **Step 6: Run the codemod against this repo as a smoke test**

The source tree is already renamed, so a dry run over it must find nothing to do.
Point it at `packages` and `tests` rather than `.` — the codemod walks the
filesystem rather than git, so `.` would sweep up the gitignored `docs/` spec and
plan, which quote the old names throughout by design.

```bash
php bin/kudosity-codemod packages 2>&1 | tail -3
php bin/kudosity-codemod tests 2>&1 | tail -3
```

Expected: `packages` reports `0 files would change`. `tests` reports `1 file would change` — and that one file is `tests/Unit/CodemodTest.php` itself, which necessarily contains old-brand fixture strings to drive the script under test. That is correct and must not be "fixed"; confirm the named file is that one and no other.

Any other hit means Task 1–3 left something behind — fix the content, not the codemod. Expect the `packages` run to surface the two package `README.md` files on a first pass, since Task 3 deferred them; renaming their symbol references here is in scope, but they still need Task 5's substantive rewrite (install commands, package table, usage samples).

The run also prints `review by hand: Unit/DtoTest.php uses fromResponse()`, which is the `null` map entry working as designed — a human-review pointer, not a change.

- [ ] **Step 7: Verify the whole suite and static analysis**

```bash
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```

Expected: 249 passed (243 baseline + 6 codemod tests), `[OK] No errors`, Pint clean.

`bin/` is not in the PHPStan `paths`, so the codemod is checked by its tests rather than statically. Leave it out — it is a standalone script with no autoloader.

- [ ] **Step 8: Commit**

```bash
git add rename-map.json bin/kudosity-codemod tests/Unit/CodemodTest.php
git commit -m "feat: add rename-map.json and kudosity-codemod for consumer upgrades"
```

---

## Task 5: Upgrade guide, docs and CI

**Files:**
- Create: `UPGRADING.md`
- Modify: `README.md`, `packages/kudosity-client/README.md`, `packages/kudosity-laravel/README.md`
- Modify: `CHANGELOG.md`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: every rename from Tasks 1–3 and `rename-map.json` from Task 4.
- Produces: `UPGRADING.md` with the sections `Renames`, `Automated upgrade`, `Rector`, `Config and environment`, `Removed APIs` and `For maintainers`. Later phases append `V1 to V2 method equivalents`, `Changed DTO shapes`, `New exceptions` and `Webhook migration` to this same file.

- [ ] **Step 1: Confirm the docs still describe reality**

```bash
git grep -in 'transmitsms\|TransmitSMS' -- '*.md' ':(exclude).agents/*' | grep -v 'api\.transmitsms\.com'
```

Every hit is a doc that Task 1–3's `sed` passes touched only partially, or prose that needs rewriting by hand. Work through the list in the following steps.

- [ ] **Step 2: Rewrite the root `README.md`**

Update: the title and intro to name Kudosity; the install commands to `composer require expertsystemsau/kudosity-laravel-client`; the package table to the new names and sub-repo URLs; every code sample to `KudosityClient`, `Kudosity::`, `toKudosity()` and `'kudosity'`; the config publish command to `--tag=kudosity-config`; env examples to `KUDOSITY_*`. Add a one-paragraph note that this is the 2.x line, that Kudosity runs two APIs (V1 `api.transmitsms.com` with key+secret, V2 `api.transmitmessage.com` with key only), and that Phase 1 ships V1 support under the new name with V2 arriving in 2.0.

- [ ] **Step 3: Rewrite both package `README.md` files**

`packages/kudosity-client/README.md` — 11 namespace references plus install and usage samples. `packages/kudosity-laravel/README.md` — provider, facade, channel string, `toKudosity()`, config tag and env keys.

- [ ] **Step 4: Add the CHANGELOG entry**

Insert directly under the `# Changelog` heading. Keep it `Unreleased`; the final phase promotes it to `2.0.0` once the whole migration lands.

```markdown
## Unreleased

### Breaking

- Renamed the packages: `expertsystemsau/transmitsms-php-client` is now `expertsystemsau/kudosity-php-client`, and `expertsystemsau/transmitsms-laravel-client` is now `expertsystemsau/kudosity-laravel-client`. The old packages are abandoned and point at the replacements.
- Renamed the namespace `ExpertSystems\TransmitSms\` to `ExpertSystems\Kudosity\`.
- Renamed `TransmitSmsClient` to `KudosityClient`, `TransmitSmsConnector` to `KudosityV1Connector`, `TransmitSmsRequest` to `KudosityV1Request`, `TransmitSmsException` to `KudosityException`, `TransmitSmsPaginator` to `V1PagedPaginator`, `TransmitSmsServiceProvider` to `KudosityServiceProvider`, `TransmitSmsChannel` to `KudosityChannel`, `TransmitSmsMessage` to `KudosityMessage`, and the `TransmitSms` facade to `Kudosity`.
- Renamed the notification method `toTransmitSms()` to `toKudosity()` and the channel string `'transmitsms'` to `'kudosity'`.
- Renamed the config file `config/transmitsms.php` to `config/kudosity.php`, its publish tag `transmitsms-config` to `kudosity-config`, and every `TRANSMITSMS_*` environment variable to `KUDOSITY_*`. The default webhook prefix moved from `webhooks/transmitsms` to `webhooks/kudosity`.
- Renamed `KudosityException::fromResponse()` to `fromV1Response()`, making room for the V2 error format. The identically named factories on the `Data\*` DTOs are unchanged.
- Removed `useSmsUrl()` and `useMmsUrl()` from the client and connector, and the `BASE_URL_MMS` constant. `BASE_URL_SMS` is now `BASE_URL`. Nothing in the SDK ever issued a request against the MMS host; V2 support arrives with a dedicated connector.

### Added

- `rename-map.json` and `bin/kudosity-codemod`, which rewrite a consuming project's class references, notification hook, channel string, config keys, environment variables and composer requirements. Dry-run by default.
- `UPGRADING.md`.
```

- [ ] **Step 5: Write `UPGRADING.md`**

Create it with these sections:

1. **Renames** — the full table from the design spec §1, plus the class table from the CHANGELOG entry above.
2. **Automated upgrade** — install the new package, then:

```bash
composer require expertsystemsau/kudosity-laravel-client:^2.0
php vendor/expertsystemsau/kudosity-laravel-client/../../../bin/kudosity-codemod . --write
php artisan vendor:publish --tag=kudosity-config
```

  followed by a note that the codemod is dry-run without `--write`, that it skips `vendor/`, `node_modules/`, `.git/`, `storage/` and build caches, and that it refuses to run if a replacement would damage the literal `api.transmitsms.com`.

3. **Rector** — an equivalent config for projects that prefer it, generated from the same map:

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

  with a note that Rector renames classes only — the notification hook, channel string, config keys and env vars still need the codemod or a hand pass.

4. **Config and environment** — `config/transmitsms.php` → `config/kudosity.php`, publish tag `transmitsms-config` → `kudosity-config`, and this table of all eleven environment variables:

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

  Note that `KUDOSITY_WEBHOOKS_PREFIX` defaults to `webhooks/kudosity`, so any webhook URL already registered with Kudosity or hard-coded in a consumer needs updating — or pin the old value explicitly to avoid a redeploy race.
5. **Removed APIs** — `useSmsUrl()`, `useMmsUrl()`, `BASE_URL_MMS`, `BASE_URL_SMS` (now `BASE_URL`), and `KudosityException::fromResponse()` → `fromV1Response()`, each with the one-line replacement.
6. **For maintainers** — the release checklist:

```markdown
1. Rename the GitHub monorepo to `kudosity-php-sdk`.
2. Create `expertsystemsau/kudosity-php-client` and `expertsystemsau/kudosity-laravel-client` as split targets.
3. Register both on Packagist.
4. Mark `transmitsms-php-client` and `transmitsms-laravel-client` abandoned, replacement set to the new packages.
5. Tag `v2.0.0`. The `v` prefix is required or `split.yml` never fires.
6. Confirm both sub-repo releases appeared and Packagist picked them up.
```

- [ ] **Step 6: Update `CLAUDE.md`**

Rewrite the Project Overview, Architecture and Namespaces sections for the new package paths, package names and class names. Correct the static-analysis line to say PHPStan **level 6** (it currently claims level 5). Leave the "Kudosity API Skills" and "Two APIs, two auth schemes" sections as they are — both are already accurate.

- [ ] **Step 7: Final sweep**

```bash
git grep -in 'transmitsms' -- . ':(exclude).agents/*' ':(exclude)docs/*' ':(exclude)CHANGELOG.md' ':(exclude)UPGRADING.md' ':(exclude)rename-map.json' | grep -v 'api\.transmitsms\.com'
```

Expected: **no output.** `CHANGELOG.md`, `UPGRADING.md` and `rename-map.json` are excluded because they document the old names on purpose.

- [ ] **Step 8: Verify**

```bash
composer dump-autoload
vendor/bin/pest --compact
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
composer validate --strict
(cd packages/kudosity-client && composer validate --strict)
(cd packages/kudosity-laravel && composer validate --strict)
```

Expected: 249 passed, `[OK] No errors`, Pint clean, and all three manifests valid.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "docs: rewrite READMEs, add UPGRADING.md, log the Kudosity rebrand"
```

---

## Definition of done

- `git grep -i transmitsms` returns hits only in `.agents/skills/`, `CHANGELOG.md`, `UPGRADING.md`, `rename-map.json`, and as the literal hostname `api.transmitsms.com`.
- 249 tests pass; PHPStan level 6 clean; Pint clean; all three composer manifests validate strictly.
- `php bin/kudosity-codemod packages` reports zero changes; `php bin/kudosity-codemod tests` reports exactly one — `tests/Unit/CodemodTest.php`, whose old-brand fixtures are what drive the script under test.
- No behaviour changed. Every V1 request still hits the same URL with the same body and the same auth.
- Phase 2 can begin: `KudosityV2Connector`, `KudosityV2Request` and `KudosityException::fromV2Response()` have names reserved and nothing occupying them.
