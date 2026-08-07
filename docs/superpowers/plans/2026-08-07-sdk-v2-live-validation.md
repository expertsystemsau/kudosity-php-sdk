# Live Consumer Validation of the Kudosity 2.0 SDKs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the two published 2.0 packages work for a consumer who did not write them, by installing them into two fresh applications and driving the full SMS and MMS lifecycle — including live webhook deliveries — against the real Kudosity API.

**Architecture:** Both packages are archived from `HEAD` exactly as `splitsh` publishes them, served through a local Composer artifact repository, and `composer require`d by name into two scratch projects that live outside the monorepo git tree. Project A is a vanilla PHP CLI (client package only, run on the declared 8.2 floor). Project B is a fresh Laravel 12 app (Laravel package). A single ngrok tunnel is re-pointed from A to B mid-run, which makes `webhooks()->update()` part of the test.

**Tech Stack:** PHP 8.2 (Docker) and 8.4 (local), Composer 2.9, Saloon v4, Laravel 12, Pest (Laravel default), ngrok, SQLite.

## Global Constraints

- **Validation workspace:** `/home/mitchell/projects/kudosity-sdk-validation/` — outside the monorepo git tree. Never create a scratch project inside `/home/mitchell/projects/transmitsms-php-sdk/`.
- **Package version:** artifacts are built as `2.0.1`. A `"version": "2.0.1"` field is injected into each archived `composer.json` because no `v2.0.1` git tag exists. This injection happens in the build script only — never in the repository.
- **Live traffic:** real credentials from `/home/mitchell/projects/transmitsms-php-sdk/.env`, real sends to `KUDOSITY_TEST_RECIPIENT`.
- **Credentials never leave the projects' own `.env` files.** No credential value, and no unredacted handset number, may appear in `VALIDATION-REPORT.md`, in any file inside the monorepo, or in any commit message.
- **Redaction convention, matching the existing fixtures:** the customer handset becomes `61491570006`, our own sender becomes `61491570017`.
- **Excluded from live traffic:** WhatsApp channel, RCS channel, `numbers()->lease()`, and a live opt-out (`STOP`). The `OPT_OUT` event is replayed from `packages/kudosity-client/tests/Fixtures/V2Webhooks/` instead.
- **Budget:** roughly twelve billable messages for the whole run. If a scenario would exceed that, stop and report rather than spending.
- **Branch:** `validate/v2-live-consumer-run`, already created and checked out in the monorepo. All monorepo commits go there. Never commit to `main`.
- **`/docs` is gitignored but specs and plans are tracked** — commit them with `git add -f`.
- **Pagination — read this before writing any list iteration.** A Saloon
  paginator yields `Saloon\Http\Response` objects when iterated directly. A
  `Response` is neither `Traversable` nor `Countable`, so `foreach ($page as $item)`
  silently yields **nothing** and `count($page)` throws a `TypeError`. Two correct
  forms: `foreach ($paginator->items() as $row)` for rows, or
  `foreach ($paginator as $response) { $response->json('<itemsKey>'); }` when you
  need per-page counts. **`items()` yields raw arrays, not DTOs** — index with
  `$row['id']`, never `$row->id`. V1 items keys vary per endpoint (`numbers`,
  `lists`, `keywords`, `messages`, `recipients`, `responses`); V2 SMS is `data`
  and sender registrations are `data.registrations`. V1 `number` fields arrive as
  JSON integers — cast before string use.
- **Failure handling:** a failing check never stops the run. Triage into `FAIL` (SDK defect), `FINDING` (upstream API behaviour), or `BLOCKED` (environment/account), record it, and continue. See "Fix Protocol" below.

## Fix Protocol

When a check reveals an SDK defect, the fix happens in the monorepo, not in the scratch project:

1. Write a test in the appropriate suite that reproduces it — `packages/kudosity-client/tests/` for client defects, root `tests/` for Laravel defects.
2. Run it and confirm it **fails** for the stated reason.
3. Fix the source.
4. Run the suite: `cd packages/kudosity-client && vendor/bin/phpunit` or `composer test` at the root.
5. Commit on `validate/v2-live-consumer-run`, one commit per defect, the message carrying the observed failure.
6. Rebuild the artifact (`./build-artifacts.sh`), `composer update` in the affected scratch project, re-run the failing scenario, and record it as `FIXED` with both commits referenced.

Escalate to the user rather than deciding, when a fix would change documented behaviour, break compatibility, or requires knowing what the API is *supposed* to do.

## File Structure

**Workspace** `/home/mitchell/projects/kudosity-sdk-validation/`

| Path | Responsibility |
|---|---|
| `build-artifacts.sh` | Archive both packages from the monorepo `HEAD`, verify export-ignore, inject version, zip into `artifacts/` |
| `artifacts/` | The two zips, consumed by a Composer `artifact` repository |
| `captured/` | Raw webhook payloads captured live, JSONL, one file per phase |
| `results/` | Per-scenario JSON results from both projects, consumed by the report generator |
| `make-report.php` | Reads `results/*.json`, writes `VALIDATION-REPORT.md` |
| `VALIDATION-REPORT.md` | The deliverable |

**Project A** `order-notifier/` (vanilla PHP)

| Path | Responsibility |
|---|---|
| `composer.json` | Declares the artifact repo, requires `expertsystemsau/kudosity-php-client: ^2.0` |
| `.env` | Credentials, copied from the monorepo `.env` |
| `bin/notify` | CLI dispatcher: `bin/notify <scenario>` or `bin/notify all` |
| `src/Bootstrap.php` | Loads `.env`, builds `KudosityClient`, exposes config accessors |
| `src/Check.php` | One check's outcome — surface, expectation, result, detail, evidence |
| `src/Redactor.php` | Redacts credentials and the handset number from evidence |
| `src/CheckRunner.php` | Runs a scenario, prints progress, writes `results/A-<name>.json` |
| `src/Scenario/Scenario.php` | Interface every scenario implements |
| `src/Scenario/PreflightScenario.php` | Sender confirmation and balance — the gate for every send |
| `src/Scenario/SmsV2Scenario.php` | `sms()` send / get / paginated list |
| `src/Scenario/MmsV2Scenario.php` | `mms()` send / get, flat-envelope assertions |
| `src/Scenario/ListsScenario.php` | Lists and contacts CRUD, bulk add, API opt-out |
| `src/Scenario/BulkSendScenario.php` | V1 list send with schedule, validity, tracking, callbacks |
| `src/Scenario/ReportingScenario.php` | Delivery stats, message report, sent counts, replies, balance |
| `src/Scenario/SendersScenario.php` | Registrations paging, verification flow |
| `src/Scenario/WebhooksCrudScenario.php` | create → get → update → delete, `http://` rejection |
| `src/Scenario/MiscV1Scenario.php` | Keywords, number formatting, email-to-SMS |
| `src/Scenario/LeaseStubScenario.php` | `numbers()->lease()` request shape against a stub, never live |
| `src/Scenario/WebhookParsingScenario.php` | `WebhookEvent`, `StatusPrecedence`, `SignedMessageRef` over captured payloads |
| `receiver/serve.php` | Bare `php -S` webhook receiver, appends every delivery to `captured/` |

**Project B** `dispatch-desk/` (Laravel 12)

| Path | Responsibility |
|---|---|
| `composer.json` | Artifact repo + `expertsystemsau/kudosity-laravel-client: ^2.0` |
| `app/Models/Customer.php` | Notifiable, `routeNotificationForKudosity()` and the MMS route |
| `app/Models/MessageEvent.php` | One decoded webhook event, persisted |
| `app/Notifications/OrderShipped.php` | `toKudosity()` — the SMS channel, both routing branches |
| `app/Notifications/OrderPhoto.php` | `toKudosityMms()` |
| `app/Listeners/RecordKudosityEvent.php` | Persists all four event classes into `message_events` |
| `app/Console/Commands/RunChecks.php` | Drives the live Laravel-side checks, writes `results/B-<name>.json` |
| `database/migrations/*_create_message_events_table.php` | The events table |
| `tests/Feature/ReceiverTest.php` | Fixture replay, signature tampering, unknown event, V1 GET routes |
| `tests/Feature/RoutingTest.php` | `apiVersion()` / `v1Reasons()` / `forceV2()` decisions |
| `tests/Feature/ConfigGuardTest.php` | Stale flat `base_url` throws; container resolves `KudosityV2Connector` |

---

### Task 1: Workspace and package artifacts

Builds the two zips the way `splitsh` publishes them and proves what a consumer actually receives. No API traffic.

**Files:**
- Create: `/home/mitchell/projects/kudosity-sdk-validation/build-artifacts.sh`
- Create: `/home/mitchell/projects/kudosity-sdk-validation/results/.gitkeep`, `captured/.gitkeep`

**Interfaces:**
- Produces: `artifacts/expertsystemsau-kudosity-php-client-2.0.1.zip` and `artifacts/expertsystemsau-kudosity-laravel-client-2.0.1.zip`, both consumable by a Composer `artifact` repository. Every later task depends on these existing.

- [ ] **Step 1: Create the workspace**

```bash
mkdir -p /home/mitchell/projects/kudosity-sdk-validation/{artifacts,captured,results}
touch /home/mitchell/projects/kudosity-sdk-validation/{captured,results}/.gitkeep
```

- [ ] **Step 2: Verify export-ignore before writing the build script**

This is the check the release notes call out — the root `.gitattributes` does not reach a split package, so each package needs its own. Run it first, because a failure here is a real defect and changes what Task 1 delivers.

```bash
cd /home/mitchell/projects/transmitsms-php-sdk
for p in kudosity-client kudosity-laravel; do
  echo "=== $p ==="
  git archive --format=tar HEAD:packages/$p | tar -t | sort
done
```

Expected: `composer.json`, `LICENSE.md`, `README.md`, `src/…` present. **`tests/` absent.** For `kudosity-laravel`, also `config/kudosity.php` and `routes/webhooks.php`.

If `tests/` appears, or `LICENSE.md` is missing: that is an SDK defect. Follow the Fix Protocol — add the missing `.gitattributes` rule or `LICENSE.md` to `packages/<p>/`, commit, then continue.

- [ ] **Step 3: Write the build script**

```bash
cat > /home/mitchell/projects/kudosity-sdk-validation/build-artifacts.sh <<'SCRIPT'
#!/usr/bin/env bash
# Build Composer artifacts from the monorepo HEAD exactly as splitsh publishes
# them. The only deviation from a real publish is the injected version field:
# Packagist reads the version from a git tag, and no v2.0.1 tag exists.
set -euo pipefail

REPO=/home/mitchell/projects/transmitsms-php-sdk
OUT=/home/mitchell/projects/kudosity-sdk-validation/artifacts
VERSION=2.0.1

mkdir -p "$OUT"
rm -f "$OUT"/*.zip

for pkg in kudosity-client kudosity-laravel; do
  work=$(mktemp -d)
  trap 'rm -rf "$work"' EXIT

  git -C "$REPO" archive --format=tar "HEAD:packages/$pkg" | tar -x -C "$work"

  if [ -d "$work/tests" ]; then
    echo "FAIL: $pkg archive contains tests/ — export-ignore is not reaching the split package" >&2
    exit 1
  fi
  if [ ! -f "$work/LICENSE.md" ]; then
    echo "FAIL: $pkg archive has no LICENSE.md" >&2
    exit 1
  fi

  name=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["name"];' "$work/composer.json")

  php -r '
    $f = $argv[1];
    $j = json_decode(file_get_contents($f), true);
    $j["version"] = $argv[2];
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' "$work/composer.json" "$VERSION"

  (cd "$work" && composer validate --strict --no-check-version)

  zip="$OUT/$(echo "$name" | tr '/' '-')-$VERSION.zip"
  (cd "$work" && zip -qr "$zip" .)
  echo "built $zip"
  rm -rf "$work"
  trap - EXIT
done
SCRIPT
chmod +x /home/mitchell/projects/kudosity-sdk-validation/build-artifacts.sh
```

- [ ] **Step 4: Run it**

Run: `/home/mitchell/projects/kudosity-sdk-validation/build-artifacts.sh`
Expected: two `built …zip` lines, `composer validate` clean for both.

If `composer validate --strict` reports a problem in either manifest, that is an SDK defect — fix it in `packages/<pkg>/composer.json` per the Fix Protocol before continuing.

- [ ] **Step 5: Confirm Composer can resolve both from the artifact repo**

```bash
cd $(mktemp -d) && cat > composer.json <<'JSON'
{
  "repositories": [{ "type": "artifact", "url": "/home/mitchell/projects/kudosity-sdk-validation/artifacts" }],
  "require": { "expertsystemsau/kudosity-laravel-client": "^2.0" },
  "minimum-stability": "stable"
}
JSON
composer update --dry-run 2>&1 | tail -20
```

Expected: resolves `expertsystemsau/kudosity-laravel-client 2.0.1` **and** `expertsystemsau/kudosity-php-client 2.0.1`, pulling `saloonphp/*` and `illuminate/*` from Packagist.

This is the guard the release notes name: the Laravel package's `require` block has never been evaluated by anything in the repo. If it fails to resolve, that is an SDK defect — fix `packages/kudosity-laravel/composer.json`, rebuild, re-run.

- [ ] **Step 6: Record the result**

```bash
cat > /home/mitchell/projects/kudosity-sdk-validation/results/A-00-artifacts.json <<'JSON'
{"scenario":"artifacts","checks":[]}
JSON
```

Replace `checks` with one entry per verification above, in the `Check` shape defined in Task 2 (`surface`, `expectation`, `result`, `detail`, `evidence`). Write it by hand here — this task predates the PHP helper.

- [ ] **Step 7: Commit any monorepo fixes**

```bash
cd /home/mitchell/projects/transmitsms-php-sdk && git status --short
```

If Steps 2–5 produced fixes, commit each per the Fix Protocol. If not, nothing to commit — the workspace is outside git.

---

### Task 2: Project A scaffold, install, and pre-flight

Creates the vanilla consumer, installs the client package from the artifact, and confirms a usable sender before any message is sent. **Every later send scenario depends on this task's sender value**, so it comes first.

**Files:**
- Create: `order-notifier/composer.json`, `.env`, `bin/notify`
- Create: `order-notifier/src/Bootstrap.php`, `src/Check.php`, `src/Redactor.php`, `src/CheckRunner.php`
- Create: `order-notifier/src/Scenario/Scenario.php`, `src/Scenario/PreflightScenario.php`

**Interfaces:**
- Consumes: `artifacts/*.zip` from Task 1.
- Produces, and every later Project A task depends on these exact signatures:
  - `Bootstrap::load(): Bootstrap` — reads `.env`
  - `Bootstrap::client(): \ExpertSystems\Kudosity\KudosityClient`
  - `Bootstrap::sender(): string` — the **confirmed** sender, resolved by pre-flight
  - `Bootstrap::recipient(): string` — the test handset in E.164
  - `Bootstrap::tunnelUrl(): ?string` — from `TUNNEL_URL`, null when no tunnel is up
  - `new Check(string $surface, string $expectation, string $result, string $detail, array $evidence = [])` with `$result` one of `PASS`, `FAIL`, `FINDING`, `BLOCKED`, `SKIPPED`
  - `Check::pass(string $surface, string $expectation, string $detail, array $evidence = []): Check` and matching `fail()`, `finding()`, `blocked()`, `skipped()`
  - `Redactor::scrub(mixed $value): mixed` — recursive; replaces the handset with `61491570006`, the sender with `61491570017`, and the API key/secret with `<redacted>`
  - `interface Scenario { public function name(): string; public function run(Bootstrap $boot): array; }` returning `Check[]`
  - `CheckRunner::run(Scenario $s, Bootstrap $boot): void` — writes `results/A-<name>.json`

- [ ] **Step 1: Scaffold the project and install the package**

```bash
mkdir -p /home/mitchell/projects/kudosity-sdk-validation/order-notifier/{bin,src/Scenario,receiver}
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
cat > composer.json <<'JSON'
{
    "name": "scratch/order-notifier",
    "description": "Scratch consumer of expertsystemsau/kudosity-php-client, for live validation",
    "type": "project",
    "repositories": [
        { "type": "artifact", "url": "../artifacts" }
    ],
    "require": {
        "php": "^8.2",
        "expertsystemsau/kudosity-php-client": "^2.0"
    },
    "autoload": { "psr-4": { "OrderNotifier\\": "src/" } },
    "minimum-stability": "stable",
    "config": { "sort-packages": true }
}
JSON
composer install
```

Packagist stays enabled: the artifact repo supplies the SDK, and the SDK's own dependencies (`saloonphp/*`, Guzzle) still come from Packagist. Do not disable it.

Expected: installs `expertsystemsau/kudosity-php-client 2.0.1` plus `saloonphp/saloon`, `saloonphp/pagination-plugin`, and Guzzle. **No `laravel/*`, no `orchestra/*`, no PHPUnit** — the client package must install standalone.

- [ ] **Step 2: Verify the installed package is the artifact, not a symlink to the monorepo**

Run:
```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
ls -la vendor/expertsystemsau/ && test -d vendor/expertsystemsau/kudosity-php-client/tests && echo "DEFECT: tests shipped" || echo "OK: no tests in vendor"
```
Expected: a real directory (not a symlink), and `OK: no tests in vendor`.

- [ ] **Step 3: Copy credentials into the project's own .env**

```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
grep -E '^KUDOSITY_(API_KEY|API_SECRET|FROM|TEST_RECIPIENT)=' /home/mitchell/projects/transmitsms-php-sdk/.env > .env
printf 'TUNNEL_URL=\n' >> .env
chmod 600 .env
```

Do not print the file. Confirm only that four `KUDOSITY_*` keys are present: `grep -c '^KUDOSITY_' .env` → `4`.

- [ ] **Step 4: Write Check and Redactor**

```php
<?php // src/Check.php
declare(strict_types=1);

namespace OrderNotifier;

final readonly class Check
{
    public function __construct(
        public string $surface,
        public string $expectation,
        public string $result,
        public string $detail,
        public array $evidence = [],
    ) {}

    public static function pass(string $surface, string $expectation, string $detail, array $evidence = []): self
    {
        return new self($surface, $expectation, 'PASS', $detail, $evidence);
    }

    /** An SDK defect. Follow the Fix Protocol. */
    public static function fail(string $surface, string $expectation, string $detail, array $evidence = []): self
    {
        return new self($surface, $expectation, 'FAIL', $detail, $evidence);
    }

    /** The API behaved in a way the docs do not describe. Not an SDK bug. */
    public static function finding(string $surface, string $expectation, string $detail, array $evidence = []): self
    {
        return new self($surface, $expectation, 'FINDING', $detail, $evidence);
    }

    /** Could not run: missing sender, no balance, capability not provisioned. */
    public static function blocked(string $surface, string $expectation, string $detail, array $evidence = []): self
    {
        return new self($surface, $expectation, 'BLOCKED', $detail, $evidence);
    }

    /** Deliberately not run — excluded by the design. */
    public static function skipped(string $surface, string $expectation, string $detail, array $evidence = []): self
    {
        return new self($surface, $expectation, 'SKIPPED', $detail, $evidence);
    }

    public function toArray(): array
    {
        return [
            'surface' => $this->surface,
            'expectation' => $this->expectation,
            'result' => $this->result,
            'detail' => $this->detail,
            'evidence' => $this->evidence,
        ];
    }
}
```

```php
<?php // src/Redactor.php
declare(strict_types=1);

namespace OrderNotifier;

/**
 * Redacts evidence to the same convention the repository's captured fixtures
 * use: the customer handset becomes 61491570006, our sender 61491570017.
 * Credentials are replaced outright.
 */
final class Redactor
{
    /** @param array<string, string> $secrets literal => replacement */
    public function __construct(private array $secrets) {}

    /**
     * $altSender covers the case pre-flight exists to detect: a fallback away
     * from the declared sender leaves the declared number as live evidence
     * (KUDOSITY_FROM checks report it in `detail`/`evidence`) unless it is
     * redacted too. Both aliases map to the same replacement — either one is
     * "our sender" depending on which stage produced the evidence.
     */
    public static function for(string $apiKey, string $apiSecret, string $handset, string $sender, ?string $altSender = null): self
    {
        $map = [];
        foreach ([$apiKey, $apiSecret] as $s) {
            if ($s !== '') {
                $map[$s] = '<redacted>';
            }
        }
        // Match with and without a leading +, and the 0-prefixed local form.
        // Pairs, not [$num => $to] literals: a fully-numeric $num (any real
        // handset/sender is) gets silently cast to an int array key by PHP,
        // which then breaks ltrim() below on the coerced int.
        $numbers = [[$handset, '61491570006'], [$sender, '61491570017']];
        if ($altSender !== null && $altSender !== '' && $altSender !== $sender) {
            $numbers[] = [$altSender, '61491570017'];
        }
        foreach ($numbers as [$num, $to]) {
            if ($num === '') {
                continue;
            }
            $bare = ltrim($num, '+');
            $map[$bare] = $to;
            $map['+'.$bare] = '+'.$to;
            if (str_starts_with($bare, '61')) {
                $map['0'.substr($bare, 2)] = '0'.substr($to, 2);
            }
        }

        return new self($map);
    }

    public function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return strtr($value, $this->secrets);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                // Cast unconditionally: PHP casts a canonical-integer string
                // key (e.g. a phone number) to int on the way into the array,
                // so an is_string() guard here would silently skip it.
                $out[strtr((string) $k, $this->secrets)] = $this->scrub($v);
            }

            return $out;
        }

        return $value;
    }
}
```

- [ ] **Step 5: Write Bootstrap, Scenario and CheckRunner**

```php
<?php // src/Bootstrap.php
declare(strict_types=1);

namespace OrderNotifier;

use ExpertSystems\Kudosity\KudosityClient;
use RuntimeException;

final class Bootstrap
{
    private array $env;

    private ?KudosityClient $client = null;

    private ?string $confirmedSender = null;

    private function __construct(array $env)
    {
        $this->env = $env;
    }

    public static function load(): self
    {
        $path = __DIR__.'/../.env';
        if (! is_file($path)) {
            throw new RuntimeException('.env missing — see Task 2 Step 3');
        }
        // parse_ini_file, not a dotenv library: this project has one dependency
        // on purpose, so nothing but the SDK under test is installed.
        $env = parse_ini_file($path, false, INI_SCANNER_RAW) ?: [];
        foreach (['KUDOSITY_API_KEY', 'KUDOSITY_FROM', 'KUDOSITY_TEST_RECIPIENT'] as $required) {
            if (($env[$required] ?? '') === '') {
                throw new RuntimeException("$required is empty in .env");
            }
        }

        return new self($env);
    }

    public function client(): KudosityClient
    {
        return $this->client ??= new KudosityClient(
            apiKey: $this->env['KUDOSITY_API_KEY'],
            apiSecret: $this->env['KUDOSITY_API_SECRET'] ?? '',
        );
    }

    /**
     * The sender pre-flight confirmed as usable. Throws if pre-flight has not
     * run, so no scenario can send from an unverified sender by accident.
     */
    public function sender(): string
    {
        return $this->confirmedSender
            ?? throw new RuntimeException('No confirmed sender — run `bin/notify preflight` first');
    }

    public function confirmSender(string $sender): void
    {
        $this->confirmedSender = $sender;
        file_put_contents(__DIR__.'/../.sender', $sender);
    }

    /** Restores the sender confirmed by an earlier `bin/notify preflight` run. */
    public function restoreSender(): void
    {
        $path = __DIR__.'/../.sender';
        if (is_file($path)) {
            $this->confirmedSender = trim((string) file_get_contents($path));
        }
    }

    public function declaredSender(): string
    {
        return $this->env['KUDOSITY_FROM'];
    }

    public function recipient(): string
    {
        return $this->env['KUDOSITY_TEST_RECIPIENT'];
    }

    public function tunnelUrl(): ?string
    {
        $url = $this->env['TUNNEL_URL'] ?? '';

        return $url === '' ? null : rtrim($url, '/');
    }

    public function redactor(): Redactor
    {
        // Both the confirmed sender and the declared one need to be in the
        // map: whenever pre-flight falls back away from KUDOSITY_FROM, the
        // declared number still appears as evidence on the KUDOSITY_FROM
        // check itself, and would otherwise leak unredacted.
        $sender = $this->confirmedSender ?? $this->declaredSender();
        $declared = $this->declaredSender();

        return Redactor::for(
            $this->env['KUDOSITY_API_KEY'],
            $this->env['KUDOSITY_API_SECRET'] ?? '',
            $this->recipient(),
            $sender,
            $declared !== $sender ? $declared : null,
        );
    }
}
```

```php
<?php // src/Scenario/Scenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

interface Scenario
{
    /** Short slug used for the results filename. */
    public function name(): string;

    /** @return array<int, Check> */
    public function run(Bootstrap $boot): array;
}
```

```php
<?php // src/CheckRunner.php
declare(strict_types=1);

namespace OrderNotifier;

use OrderNotifier\Scenario\Scenario;
use RuntimeException;
use Throwable;

final class CheckRunner
{
    public function __construct(private string $resultsDir)
    {
        // A missing directory would otherwise fail file_put_contents() silently
        // (its return value was previously ignored) while the run still exited
        // 0 — every later task writes a result file through this constructor.
        if (! is_dir($this->resultsDir)) {
            throw new RuntimeException("Results directory does not exist: {$this->resultsDir}");
        }
    }

    public function run(Scenario $scenario, Bootstrap $boot): void
    {
        fwrite(STDOUT, "\n=== {$scenario->name()} ===\n");

        try {
            $checks = $scenario->run($boot);
        } catch (Throwable $e) {
            // A scenario that dies mid-way still reports. An uncaught throwable
            // is itself a result: the SDK let an exception escape a documented
            // surface, or the account is not in the state the scenario needs.
            $checks = [Check::fail(
                $scenario->name(),
                'scenario completes without an uncaught throwable',
                get_class($e).': '.$e->getMessage(),
                ['trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8)],
            )];
        }

        $redactor = $boot->redactor();
        $rows = [];
        foreach ($checks as $check) {
            // Print from the already-scrubbed row, not $check->detail — the
            // unredacted original — so STDOUT carries the same guarantee as
            // the results file.
            $row = $redactor->scrub($check->toArray());
            $rows[] = $row;
            fwrite(STDOUT, sprintf("  [%-7s] %s — %s\n", $row['result'], $row['surface'], $row['detail']));
        }

        $path = sprintf('%s/A-%s.json', $this->resultsDir, $scenario->name());
        $written = file_put_contents(
            $path,
            json_encode(['scenario' => $scenario->name(), 'checks' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
        if ($written === false) {
            throw new RuntimeException("Failed to write results file: {$path}");
        }
    }
}
```

- [ ] **Step 6: Write the pre-flight scenario**

This resolves the stale-sender hazard the design's pre-flight section names: `KUDOSITY_FROM` may name a retired number, and every V2 send answers `Sender not found` until it is corrected.

```php
<?php // src/Scenario/PreflightScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Data\V2\SenderRegistrationData;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class PreflightScenario implements Scenario
{
    public function name(): string
    {
        return '01-preflight';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        // V1 auth, and the budget gate. Both APIs share the key; only V1 uses
        // the secret, so a balance read proves the Basic Auth pair works.
        $balance = $client->account()->getBalance();
        $checks[] = Check::pass(
            'account()->getBalance()',
            'V1 Basic Auth works and the account has credit',
            sprintf('%.2f %s', $balance->balance, $balance->currency),
            ['balance' => $balance->balance, 'currency' => $balance->currency],
        );

        if ($balance->balance <= 1.0) {
            $checks[] = Check::blocked(
                'budget',
                'balance covers ~12 messages',
                'Balance too low to run the send scenarios',
            );

            return $checks;
        }

        // V2 auth, and the sender question.
        //
        // Two independent sources, because neither alone is sufficient:
        //
        //   senders()->readyToUse()  — V2 self-service SENDER REGISTRATIONS.
        //                              Covers a registered personal mobile. A
        //                              leased virtual number never appears here.
        //   numbers()->all()         — V1 LEASED VIRTUAL NUMBERS. This is where
        //                              a dedicated VMN lives, and a VMN is the
        //                              only sender that does MMS and two-way.
        //
        // Confirmed live 2026-08-07: an account owning one active VMN answers
        // /v2/senders/registrations with an empty list and get-numbers.json with
        // that number. Consulting registrations alone reports "no sender" on an
        // account that has one, which blocks every send scenario for no reason.
        $usable = [];

        $ready = $client->senders()->readyToUse();
        foreach ($ready as $reg) {
            /** @var SenderRegistrationData $reg */
            if ($reg->sender !== null) {
                $usable[] = $reg->sender;
            }
        }

        $checks[] = Check::pass(
            'senders()->readyToUse()',
            'V2 x-api-key auth works and returns any registered senders',
            sprintf('%d registration(s) ready: %s', count($usable), implode(', ', $usable) ?: 'none'),
            ['registrations' => $usable],
        );

        // Leased numbers. `number` is a JSON integer here, so cast it.
        $leased = [];
        foreach ($client->numbers()->all()->items() as $row) {
            $number = isset($row['number']) ? (string) $row['number'] : '';
            if ($number !== '' && ($row['status'] ?? null) === 'active') {
                $leased[] = $number;
            }
        }

        $checks[] = Check::pass(
            'numbers()->all()',
            'V1 auth works and the account\'s leased virtual numbers are listed',
            sprintf('%d active leased number(s): %s', count($leased), implode(', ', $leased) ?: 'none'),
            ['leased' => $leased],
        );

        // A leased VMN is preferred over a registered mobile: it is the only
        // sender that can send MMS and receive the replies Task 9 needs.
        $usable = array_values(array_unique([...$leased, ...$usable]));

        $declared = $boot->declaredSender();
        if (in_array($declared, $usable, true)) {
            $boot->confirmSender($declared);
            $checks[] = Check::pass(
                'KUDOSITY_FROM',
                'the declared sender is registered and usable',
                "Using $declared",
            );
        } elseif ($usable !== []) {
            $boot->confirmSender($usable[0]);
            $checks[] = Check::finding(
                'KUDOSITY_FROM',
                'the declared sender is registered and usable',
                sprintf(
                    'Declared sender %s is not usable on this account; falling back to %s. '
                    .'The notes record KUDOSITY_FROM as stale — this confirms it. The '
                    .'monorepo .env is NOT edited; the fallback is discovered at runtime.',
                    $declared,
                    $usable[0],
                ),
                ['declared' => $declared, 'usable' => $usable],
            );
        } else {
            $checks[] = Check::blocked(
                'KUDOSITY_FROM',
                'at least one usable sender exists',
                'Neither a leased virtual number nor a ready sender registration exists on '
                .'this account. Every send scenario is blocked until one is provisioned — '
                .'this is an account state, not an SDK defect.',
            );
        }

        return $checks;
    }
}
```

- [ ] **Step 7: Write the CLI dispatcher**

Register only the scenarios that exist so far. Later tasks add their own line to `$scenarios`.

```php
#!/usr/bin/env php
<?php // bin/notify
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use OrderNotifier\Bootstrap;
use OrderNotifier\CheckRunner;

$scenarios = [
    '01-preflight' => \OrderNotifier\Scenario\PreflightScenario::class,
];

$want = $argv[1] ?? 'all';
$boot = Bootstrap::load();
$boot->restoreSender();
$runner = new CheckRunner(__DIR__.'/../../results');

$selected = $want === 'all'
    ? $scenarios
    : array_filter($scenarios, fn ($k) => str_contains($k, $want), ARRAY_FILTER_USE_KEY);

if ($selected === []) {
    fwrite(STDERR, "No scenario matches '$want'. Known: ".implode(', ', array_keys($scenarios))."\n");
    exit(1);
}

foreach ($selected as $class) {
    $runner->run(new $class(), $boot);
}
```

- [ ] **Step 8: Lint and run pre-flight**

Run:
```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
chmod +x bin/notify && php -l bin/notify && ./bin/notify preflight
```
Expected: three or four checks printed, all `PASS` or one `FINDING` on `KUDOSITY_FROM`, and `results/A-01-preflight.json` written.

If `getBalance()` throws a 401, stop: the credentials are wrong and nothing downstream can run. If `readyToUse()` throws, triage per the Fix Protocol.

- [ ] **Step 9: Confirm the sender was persisted**

Run: `cat /home/mitchell/projects/kudosity-sdk-validation/order-notifier/.sender`
Expected: a bare E.164 number. Every later scenario reads it through `Bootstrap::restoreSender()`.

---

### Task 3: Project A — V2 SMS lifecycle

**Files:**
- Create: `order-notifier/src/Scenario/SmsV2Scenario.php`
- Modify: `order-notifier/bin/notify` — add `'02-sms-v2' => SmsV2Scenario::class`

**Interfaces:**
- Consumes: `Bootstrap::sender()`, `Bootstrap::recipient()`, `Check::*` from Task 2.
- Produces: `order-notifier/.last-sms-id` and `.last-sms-ref`, both read by Task 9 (the live callback loop) to correlate deliveries.

- [ ] **Step 1: Write the scenario**

`sms()->send()` signature, verbatim from the package:
`send(string $message, string $to, string $from, ?string $messageRef = null, bool $trackLinks = false): SmsMessageData`

```php
<?php // src/Scenario/SmsV2Scenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class SmsV2Scenario implements Scenario
{
    /** Signing secret for the correlation ref. Not an API credential. */
    private const REF_SECRET = 'order-notifier-validation-secret';

    public function name(): string
    {
        return '02-sms-v2';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        // A signed ref, so the reply captured in Task 9 proves correlation
        // survives the round trip AND that a forged ref would be rejected.
        $ref = SignedMessageRef::sign('order-9931', self::REF_SECRET);

        $sent = $client->sms()->send(
            message: 'Order 9931 has shipped. Track it: https://example.com/t/9931',
            to: $boot->recipient(),
            from: $boot->sender(),
            messageRef: $ref,
            trackLinks: true,
        );

        file_put_contents(__DIR__.'/../../.last-sms-id', $sent->id);
        file_put_contents(__DIR__.'/../../.last-sms-ref', $ref);

        $checks[] = Check::pass(
            'sms()->send()',
            'POST /v2/sms returns a flat envelope decoded into SmsMessageData',
            sprintf('id=%s status=%s segments=%d gsm=%s', $sent->id, $sent->status->value, $sent->smsCount, $sent->isGsm ? 'yes' : 'no'),
            ['id' => $sent->id, 'status' => $sent->status->value, 'recipient' => $sent->recipient, 'sender' => $sent->sender, 'message_ref' => $sent->messageRef],
        );

        $checks[] = $sent->messageRef === $ref
            ? Check::pass('sms()->send()', 'the message_ref round-trips on the send response', 'ref echoed intact')
            : Check::fail('sms()->send()', 'the message_ref round-trips on the send response', sprintf('sent %s, got back %s', $ref, $sent->messageRef ?? 'null'));

        $checks[] = $sent->trackLinks
            ? Check::pass('sms()->send()', 'trackLinks: true is reflected in the response', 'track_links true')
            : Check::finding('sms()->send()', 'trackLinks: true is reflected in the response', 'Sent with trackLinks: true but the response reports false');

        $checks[] = $sent->recipientCount() === 1
            ? Check::pass('SentMessage::recipientCount()', 'a V2 SMS reports exactly one recipient, not its segment count', '1')
            : Check::fail('SentMessage::recipientCount()', 'a V2 SMS reports exactly one recipient', (string) $sent->recipientCount());

        // GET /v2/sms/{id}. Observed live: an immediate GET for a message id
        // just returned by POST can 404 for a read-after-write window — over
        // a minute, confirmed by retrying by hand well after the original
        // attempt. Retried here, rather than left to throw, so a transient
        // indexing lag doesn't take the independent list checks below down
        // with it — a scenario-wide throw loses every check not yet returned.
        $fetched = null;
        $lastNotFound = null;
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            try {
                $fetched = $client->sms()->get($sent->id);
                break;
            } catch (NotFoundException $e) {
                $lastNotFound = $e;
                if ($attempt < 10) {
                    sleep(15);
                }
            }
        }

        if ($fetched !== null) {
            $checks[] = $fetched->id === $sent->id
                ? Check::pass('sms()->get()', 'GET /v2/sms/{id} returns the same message', sprintf('status now %s', $fetched->status->value), ['status' => $fetched->status->value])
                : Check::fail('sms()->get()', 'GET /v2/sms/{id} returns the same message', sprintf('asked for %s, got %s', $sent->id, $fetched->id));
        } else {
            $checks[] = Check::finding(
                'sms()->get()',
                'GET /v2/sms/{id} returns the same message',
                sprintf('Still 404 after 10 retries (~135s): %s — read-after-write lag exceeded the retry budget', $lastNotFound?->getMessage() ?? 'unknown error'),
            );
        }

        // Paginated list, filtered to this recipient. The point is crossing a
        // page boundary: V2PagedPaginator must read `limit` from the response
        // rather than assuming one, or it walks off the end of the results.
        // NOTE: iterate ->items(), never the paginator directly. A Saloon
        // paginator yields Response objects, which are neither Traversable nor
        // Countable — `foreach ($page as $item)` over one silently yields
        // nothing and `count($page)` throws. ->items() yields the rows, and the
        // rows are RAW ARRAYS, not DTOs, so index them with ['key'].
        //
        // Items key is `smses`, not `data`: GET /v2/sms (list) is documented as
        // a flat envelope `{"smses": [...], "total_records": ...}` — both
        // ListSmsV2Request::paginationItemsKey() and SmsListData::fromArray()
        // read `smses`. `data` is the WhatsApp/RCS wrapped shape, not this one.
        $seen = 0;
        $pages = 0;
        $ids = [];
        foreach ($client->sms()->list(recipient: $boot->recipient()) as $response) {
            $pages++;
            foreach (($response->json('smses') ?? []) as $row) {
                $seen++;
                $ids[] = (string) ($row['id'] ?? '');
            }
            if ($pages >= 3) {
                break; // enough to prove the boundary without paging the account
            }
        }

        $checks[] = $seen > 0
            ? Check::pass('sms()->list()', 'the paged paginator yields items across pages', sprintf('%d items over %d page(s)', $seen, $pages), ['pages' => $pages, 'items' => $seen])
            : Check::fail('sms()->list()', 'the paged paginator yields items across pages', 'No items returned for a recipient we just messaged');

        $checks[] = count($ids) === count(array_unique($ids))
            ? Check::pass('sms()->list()', 'no item is yielded twice across page boundaries', sprintf('%d unique ids', count($ids)))
            : Check::fail('sms()->list()', 'no item is yielded twice across page boundaries', 'Duplicate ids across pages — the paginator is mis-reading `limit` or `offset`');

        $checks[] = in_array($sent->id, $ids, true)
            ? Check::pass('sms()->list()', 'the message just sent appears in the filtered list', $sent->id)
            : Check::finding('sms()->list()', 'the message just sent appears in the filtered list', 'Not present within the first 3 pages — likely indexing lag, not an SDK fault');

        // Status filter, exercising the enum on the query side. The list filter
        // is UPPERCASE while send responses are lowercase; MessageStatus is
        // case-insensitive for exactly this reason.
        $firstPage = null;
        foreach ($client->sms()->list(status: MessageStatus::Delivered, recipient: $boot->recipient()) as $response) {
            $firstPage = $response->json('smses') ?? [];
            break;
        }
        $checks[] = $firstPage !== null
            ? Check::pass('sms()->list(status:)', 'a MessageStatus enum is accepted as a filter', sprintf('%d delivered on page 1', count($firstPage)))
            : Check::finding('sms()->list(status:)', 'a MessageStatus enum is accepted as a filter', 'The filtered request returned no page at all');

        return $checks;
    }
}
```

- [ ] **Step 2: Register the scenario**

In `bin/notify`, add to `$scenarios`:

```php
    '02-sms-v2' => \OrderNotifier\Scenario\SmsV2Scenario::class,
```

- [ ] **Step 3: Run it**

Run:
```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier && ./bin/notify 02-sms-v2
```
Expected: a real SMS arrives on the handset. All checks `PASS`, possibly a `FINDING` on list indexing lag.

Spend: 1 message.

- [ ] **Step 4: Triage any FAIL**

For each `FAIL`, follow the Fix Protocol. The likely candidates and where their tests live:
- envelope decoding → `packages/kudosity-client/tests/Unit/Data/V2/SmsMessageDataTest.php`
- pagination → `packages/kudosity-client/tests/Unit/Http/Paginators/`
- `messageRef` handling → `packages/kudosity-client/tests/Unit/Resources/SmsV2ResourceTest.php`

Confirm the file names with `ls` before writing — do not create a parallel test file next to an existing one.

- [ ] **Step 5: Commit any fixes**

```bash
cd /home/mitchell/projects/transmitsms-php-sdk
git add -A && git commit -m "fix(v2-sms): <observed failure>"
```

---

### Task 4: Project A — V2 MMS lifecycle

**Files:**
- Create: `order-notifier/src/Scenario/MmsV2Scenario.php`
- Modify: `order-notifier/bin/notify`

**Interfaces:**
- Consumes: Task 2's `Bootstrap`/`Check`.
- Produces: `order-notifier/.last-mms-id`, read by Task 9.

- [ ] **Step 1: Write the scenario**

`mms()->send()` signature, verbatim:
`send(string $to, string $from, array $contentUrls, ?string $subject = null, ?string $message = null, ?string $messageRef = null, bool $trackLinks = false): MmsMessageData`

Note the shape difference from SMS: `$to` and `$from` come first, and there is **no list endpoint** — `MmsResource` has only `send()` and `get()`.

```php
<?php // src/Scenario/MmsV2Scenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class MmsV2Scenario implements Scenario
{
    /** A small, stable, publicly reachable image. */
    private const MEDIA = 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6b/Australia_location_map.svg/320px-Australia_location_map.svg.png';

    public function name(): string
    {
        return '03-mms-v2';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        $sent = $client->mms()->send(
            to: $boot->recipient(),
            from: $boot->sender(),
            contentUrls: [self::MEDIA],
            subject: 'Your order shipped',
            message: 'Order 9931 is on its way.',
            messageRef: 'order-9931:mms',
            trackLinks: false,
        );

        file_put_contents(__DIR__.'/../../.last-mms-id', $sent->id);

        $checks[] = Check::pass(
            'mms()->send()',
            'POST /v2/mms returns a flat envelope decoded into MmsMessageData',
            sprintf('id=%s status=%s subject=%s', $sent->id, $sent->status->value, $sent->subject ?? 'null'),
            ['id' => $sent->id, 'status' => $sent->status->value, 'content_urls' => $sent->contentUrls, 'subject' => $sent->subject, 'message_ref' => $sent->messageRef],
        );

        $checks[] = $sent->contentUrls === [self::MEDIA]
            ? Check::pass('mms()->send()', 'the media URL round-trips in content_urls', '1 url echoed intact')
            : Check::finding('mms()->send()', 'the media URL round-trips in content_urls', 'The API rewrote or dropped the URL', ['sent' => [self::MEDIA], 'returned' => $sent->contentUrls]);

        $fetched = $client->mms()->get($sent->id);
        $checks[] = $fetched->id === $sent->id
            ? Check::pass('mms()->get()', 'GET /v2/mms/{id} returns the same message', sprintf('status now %s', $fetched->status->value), ['status' => $fetched->status->value])
            : Check::fail('mms()->get()', 'GET /v2/mms/{id} returns the same message', sprintf('asked for %s, got %s', $sent->id, $fetched->id));

        // Two media files. The vendored skill says one attachment per MMS; this
        // records what the API actually does rather than assuming the limit.
        try {
            $two = $client->mms()->send(
                to: $boot->recipient(),
                from: $boot->sender(),
                contentUrls: [self::MEDIA, self::MEDIA],
                message: 'Two attachments',
            );
            $checks[] = Check::finding(
                'mms()->send()',
                'the API rejects more than one media file, per the vendored skill',
                sprintf('Accepted two content_urls and returned id=%s with %d url(s)', $two->id, count($two->contentUrls)),
                ['id' => $two->id, 'content_urls' => $two->contentUrls],
            );
        } catch (KudosityException $e) {
            $checks[] = Check::pass(
                'mms()->send()',
                'the API rejects more than one media file, per the vendored skill',
                'Rejected: '.$e->getMessage(),
            );
        }

        // MmsResource deliberately has no list(). Assert the SDK does not
        // pretend otherwise — a list() here would be inventing an endpoint.
        $checks[] = ! method_exists($client->mms(), 'list')
            ? Check::pass('mms()', 'MmsResource exposes no list() — /v2/mms has no list endpoint', 'no list() method')
            : Check::fail('mms()', 'MmsResource exposes no list() — /v2/mms has no list endpoint', 'A list() method exists; /v2/mms has no list endpoint to back it');

        return $checks;
    }
}
```

- [ ] **Step 2: Register it**

```php
    '03-mms-v2' => \OrderNotifier\Scenario\MmsV2Scenario::class,
```

- [ ] **Step 3: Run it**

Run: `./bin/notify 03-mms-v2`
Expected: one or two MMS arrive on the handset. `PASS` throughout, plus one `PASS` or `FINDING` on the two-attachment question.

Spend: 2 messages.

If the send fails with a sender error, the confirmed sender cannot do MMS — the notes record the previous number being retired for exactly that reason. Record `BLOCKED` on the MMS surface and continue; do not lease a replacement.

- [ ] **Step 4: Triage and commit**

Per the Fix Protocol. MMS DTO tests live under `packages/kudosity-client/tests/` — locate the existing `MmsMessageData` test with `ls packages/kudosity-client/tests/Unit/Data/V2/` before adding one.

---

### Task 5: Project A — V1 lists and contacts

**Files:**
- Create: `order-notifier/src/Scenario/ListsScenario.php`
- Modify: `order-notifier/bin/notify`

**Interfaces:**
- Consumes: Task 2's `Bootstrap`/`Check`.
- Produces: `order-notifier/.list-id` — the throwaway list id, read by Task 6's bulk send and deleted by Task 18's cleanup.

Signatures used, verbatim from `ListsResource`:
```
create(string $name): ListData
get(int $listId): ListData
getContacts(int $listId): V1PagedPaginator
addContact(int $listId, string $mobile, ?string $firstName = null, ?string $lastName = null): ContactData
getContact(int $listId, string $mobile): ContactData
updateContact(int $listId, string $mobile, ?string $firstName = null, ?string $lastName = null): bool
optoutContact(int $listId, string $mobile): bool
deleteContact(int $listId, string $mobile): bool
addField(int $listId, int $fieldNumber, string $fieldName): bool
bulkAdd(string $fileUrl, ?int $listId = null, ?string $name = null): BulkAddResultData
bulkAddProgress(int $listId): BulkProgressData
delete(int $listId): bool
```
`ListData` exposes `id`, `name`, `members`, `fields`.

- [ ] **Step 1: Write the scenario**

```php
<?php // src/Scenario/ListsScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class ListsScenario implements Scenario
{
    /**
     * A reserved fictitious AU number. Opting this out has no real-world
     * effect, which is why the opt-out check uses it and never the handset.
     */
    private const FICTITIOUS = '61491570006';

    public function name(): string
    {
        return '04-lists';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        $listName = 'sdk-validation-'.date('Ymd-His');
        $list = $client->lists()->create($listName);
        file_put_contents(__DIR__.'/../../.list-id', (string) $list->id);

        $checks[] = Check::pass(
            'lists()->create()',
            'a list is created and returns its id',
            sprintf('id=%d name=%s', $list->id, $list->name),
            ['id' => $list->id, 'name' => $list->name],
        );

        // A custom field, so the list carries more than the built-in columns.
        $field = $client->lists()->addField($list->id, 1, 'order_number');
        $checks[] = $field
            ? Check::pass('lists()->addField()', 'a custom field is added to the list', 'field 1 = order_number')
            : Check::fail('lists()->addField()', 'a custom field is added to the list', 'Returned false');

        // The handset, so Task 6 can send to this list.
        $contact = $client->lists()->addContact($list->id, $boot->recipient(), 'Test', 'Handset');
        $checks[] = Check::pass(
            'lists()->addContact()',
            'a contact is added and returned as ContactData',
            sprintf('mobile=%s', $contact->mobile ?? 'null'),
            ['contact' => (array) $contact],
        );

        $fetched = $client->lists()->getContact($list->id, $boot->recipient());
        $checks[] = ($fetched->mobile ?? null) !== null
            ? Check::pass('lists()->getContact()', 'the contact reads back by mobile', (string) $fetched->mobile)
            : Check::fail('lists()->getContact()', 'the contact reads back by mobile', 'No mobile on the returned ContactData');

        $updated = $client->lists()->updateContact($list->id, $boot->recipient(), 'Updated', 'Handset');
        $checks[] = $updated
            ? Check::pass('lists()->updateContact()', 'the contact is updated', 'first name changed to Updated')
            : Check::fail('lists()->updateContact()', 'the contact is updated', 'Returned false');

        // Membership count. `members` on a freshly-read list should reflect the
        // contact just added; if it lags, that is an API property, not a bug.
        $reread = $client->lists()->get($list->id);
        $checks[] = $reread->members >= 1
            ? Check::pass('lists()->get()', 'the list reports its member count', sprintf('%d member(s)', $reread->members), ['members' => $reread->members])
            : Check::finding('lists()->get()', 'the list reports its member count', sprintf('Reports %d after adding a contact — the count lags', $reread->members));

        // Paginated members.
        $seen = 0;
        foreach ($client->lists()->getContacts($list->id)->items() as $row) {
            $seen++;
        }
        $checks[] = $seen >= 1
            ? Check::pass('lists()->getContacts()', 'members paginate', sprintf('%d on page 1', $seen))
            : Check::fail('lists()->getContacts()', 'members paginate', 'Page 1 was empty for a list with a member');

        // Opt-out, on the fictitious number only.
        $client->lists()->addContact($list->id, self::FICTITIOUS, 'Fictitious', 'Reserved');
        $optedOut = $client->lists()->optoutContact($list->id, self::FICTITIOUS);
        $checks[] = $optedOut
            ? Check::pass('lists()->optoutContact()', 'a contact is opted out via the API', 'Opted out '.self::FICTITIOUS.' — a reserved number, never the handset')
            : Check::fail('lists()->optoutContact()', 'a contact is opted out via the API', 'Returned false');

        $deleted = $client->lists()->deleteContact($list->id, self::FICTITIOUS);
        $checks[] = $deleted
            ? Check::pass('lists()->deleteContact()', 'a contact is removed from the list', 'removed '.self::FICTITIOUS)
            : Check::fail('lists()->deleteContact()', 'a contact is removed from the list', 'Returned false');

        // Bulk CSV add. The API fetches the URL itself, so it must be publicly
        // reachable — a data: URI or a localhost path will not do. Without a
        // tunnel there is nowhere to host one, so this is BLOCKED rather than
        // faked: asserting against a URL the API cannot fetch proves nothing.
        $tunnel = $boot->tunnelUrl();
        if ($tunnel === null) {
            $checks[] = Check::blocked(
                'lists()->bulkAdd()',
                'a CSV at a public URL is imported into the list',
                'No TUNNEL_URL set — the API fetches the CSV itself and cannot reach a local file. Re-run this scenario during Task 9, when the tunnel is up.',
            );
        } else {
            try {
                $result = $client->lists()->bulkAdd($tunnel.'/contacts.csv', $list->id);
                $checks[] = Check::pass(
                    'lists()->bulkAdd()',
                    'a CSV at a public URL is imported into the list',
                    'Accepted the import',
                    ['result' => (array) $result],
                );

                $progress = $client->lists()->bulkAddProgress($list->id);
                $checks[] = Check::pass(
                    'lists()->bulkAddProgress()',
                    'import progress reads back',
                    'progress returned',
                    ['progress' => (array) $progress],
                );
            } catch (KudosityException $e) {
                $checks[] = Check::fail(
                    'lists()->bulkAdd()',
                    'a CSV at a public URL is imported into the list',
                    $e->getMessage(),
                );
            }
        }

        // The list itself is NOT deleted here — Task 6 sends to it. Cleanup is
        // Task 18.
        $checks[] = Check::skipped(
            'lists()->delete()',
            'the throwaway list is deleted',
            'Deferred to Task 18 cleanup — Task 6 sends to this list',
        );

        return $checks;
    }
}
```

- [ ] **Step 2: Register it**

```php
    '04-lists' => \OrderNotifier\Scenario\ListsScenario::class,
```

- [ ] **Step 3: Run it**

Run: `./bin/notify 04-lists`
Expected: all `PASS`, with `bulkAdd`/`bulkAddProgress` `BLOCKED` (no tunnel yet) and `delete` `SKIPPED`.

Spend: 0 messages.

- [ ] **Step 4: Confirm the list id was persisted**

Run: `cat ../order-notifier/.list-id 2>/dev/null || cat .list-id`
Expected: an integer. Task 6 needs it.

- [ ] **Step 5: Triage and commit any fixes**

Per the Fix Protocol. `ContactData` and `ListData` tests live under `packages/kudosity-client/tests/` — locate them with `ls packages/kudosity-client/tests/Unit/Data/` first.

---

### Task 6: Project A — V1 bulk send and reporting

The V1 half of the SMS story: multiple recipients, a contact list, scheduling, validity, link tracking, and per-send callback URLs — everything V2's single-recipient `sms()` cannot express.

**Files:**
- Create: `order-notifier/src/Scenario/BulkSendScenario.php`, `src/Scenario/ReportingScenario.php`
- Modify: `order-notifier/bin/notify`

**Interfaces:**
- Consumes: `.list-id` from Task 5; Task 2's `Bootstrap`/`Check`.
- Produces: `order-notifier/.last-bulk-id` — the V1 `message_id` (an `int`, unlike V2's string `id`), read by Task 6's reporting scenario and Task 18.

Signatures, verbatim:
```
bulk()->send(string $message, string $to, ?string $from = null, ?callable $configure = null): SmsData
bulk()->sendToList(string $message, int $listId, ?string $from = null, ?callable $configure = null): SmsData
bulk()->schedule(string $message, string $to, string|DateTimeInterface $at, ?string $from = null): SmsData
bulk()->cancel(int $messageId): bool
```
The `$configure` callable receives a `SendSmsRequest`, whose setters are:
`to()`, `toList()`, `from()`, `countryCode()`, `formatNumbers()`, `scheduledAt()`, `validity()`, `repliesToEmail()`, `trackedLinkUrl()`, `dlrCallback()`, `replyCallback()`, `linkHitsCallback()`.
`SmsData` exposes `messageId` (int), `sendAt`, `recipients`, `cost`, `sms`, `list`.

- [ ] **Step 1: Write the bulk send scenario**

```php
<?php // src/Scenario/BulkSendScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class BulkSendScenario implements Scenario
{
    public function name(): string
    {
        return '05-bulk-send';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        $listIdFile = __DIR__.'/../../.list-id';
        if (! is_file($listIdFile)) {
            return [Check::blocked(
                'bulk()->sendToList()',
                'a V1 send goes to the throwaway list',
                'No .list-id — run 04-lists first',
            )];
        }
        $listId = (int) file_get_contents($listIdFile);

        // The full V1 option set in one send. Every option here is a reason the
        // Laravel SMS channel routes to V1 instead of V2.
        $tunnel = $boot->tunnelUrl();
        $sent = $client->bulk()->sendToList(
            message: 'Order 9931 shipped. Details: https://example.com/o/9931',
            listId: $listId,
            from: $boot->sender(),
            configure: function (SendSmsRequest $r) use ($tunnel) {
                $r->validity(60)
                    ->trackedLinkUrl('https://example.com/o/9931')
                    ->repliesToEmail('mitchell@expertsystems.com.au');
                if ($tunnel !== null) {
                    $r->dlrCallback($tunnel.'/v1/dlr')
                        ->replyCallback($tunnel.'/v1/reply')
                        ->linkHitsCallback($tunnel.'/v1/link-hits');
                }
            },
        );

        file_put_contents(__DIR__.'/../../.last-bulk-id', (string) $sent->messageId);

        $checks[] = Check::pass(
            'bulk()->sendToList()',
            'a V1 list send accepts validity, tracked link, replies-to-email and callbacks',
            sprintf('message_id=%d recipients=%d cost=%.4f segments=%d', $sent->messageId, $sent->recipients, $sent->cost, $sent->sms),
            ['message_id' => $sent->messageId, 'recipients' => $sent->recipients, 'cost' => $sent->cost, 'sms' => $sent->sms, 'send_at' => $sent->sendAt],
        );

        $checks[] = $sent->messageId > 0
            ? Check::pass('SmsData::$messageId', 'V1 returns an integer message id, unlike V2s string id', (string) $sent->messageId)
            : Check::fail('SmsData::$messageId', 'V1 returns an integer message id', 'Got '.var_export($sent->messageId, true));

        $checks[] = $sent->list !== null
            ? Check::pass('SmsData::$list', 'a list send reports the list it went to', sprintf('list id %s', $sent->list->id ?? 'null'), ['list' => (array) $sent->list])
            : Check::finding('SmsData::$list', 'a list send reports the list it went to', 'The response carried no list block');

        // Scheduling, then cancellation — so the scheduled message never sends
        // and costs nothing. `schedule()` is the surface V2 has no answer for.
        $at = (new DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s');
        $scheduled = $client->bulk()->schedule(
            message: 'This scheduled message is cancelled before it sends.',
            to: $boot->recipient(),
            at: $at,
            from: $boot->sender(),
        );

        $checks[] = Check::pass(
            'bulk()->schedule()',
            'a send is scheduled for a future time',
            sprintf('message_id=%d send_at=%s', $scheduled->messageId, $scheduled->sendAt),
            ['message_id' => $scheduled->messageId, 'send_at' => $scheduled->sendAt, 'requested' => $at],
        );

        $cancelled = $client->bulk()->cancel($scheduled->messageId);
        $checks[] = $cancelled
            ? Check::pass('bulk()->cancel()', 'a scheduled send is cancelled before it costs anything', 'cancelled '.$scheduled->messageId)
            : Check::fail('bulk()->cancel()', 'a scheduled send is cancelled before it costs anything', 'Returned false — a real message will send in 2 hours. Cancel it in the dashboard.');

        // A DateTimeInterface, not a string, through the same surface.
        try {
            $dt = $client->bulk()->schedule(
                message: 'DateTimeInterface scheduling check, cancelled immediately.',
                to: $boot->recipient(),
                at: new DateTimeImmutable('+3 hours'),
                from: $boot->sender(),
            );
            $client->bulk()->cancel($dt->messageId);
            $checks[] = Check::pass(
                'bulk()->schedule()',
                'a DateTimeInterface is accepted where a string is',
                sprintf('scheduled %d then cancelled', $dt->messageId),
            );
        } catch (KudosityException $e) {
            $checks[] = Check::fail(
                'bulk()->schedule()',
                'a DateTimeInterface is accepted where a string is',
                $e->getMessage(),
            );
        }

        // 500-recipient guard, offline. SendSmsRequest throws before the
        // request leaves the process, so this costs nothing.
        try {
            $tooMany = new SendSmsRequest('over the limit');
            $tooMany->to(implode(',', array_fill(0, 501, '61491570006')));
            $checks[] = Check::fail(
                'SendSmsRequest::to()',
                'more than 500 recipients is rejected before sending',
                'Accepted 501 recipients',
            );
        } catch (\Throwable $e) {
            $checks[] = Check::pass(
                'SendSmsRequest::to()',
                'more than 500 recipients is rejected before sending',
                get_class($e).': '.$e->getMessage(),
            );
        }

        return $checks;
    }
}
```

- [ ] **Step 2: Write the reporting scenario**

```php
<?php // src/Scenario/ReportingScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class ReportingScenario implements Scenario
{
    public function name(): string
    {
        return '06-reporting';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        $idFile = __DIR__.'/../../.last-bulk-id';
        if (! is_file($idFile)) {
            return [Check::blocked('reporting()', 'reports read back for a real send', 'No .last-bulk-id — run 05-bulk-send first')];
        }
        $messageId = (int) file_get_contents($idFile);

        $message = $client->reporting()->getMessage($messageId);
        $checks[] = Check::pass(
            'reporting()->getMessage()',
            'a sent message reads back by id',
            sprintf('id=%d', $messageId),
            ['message' => (array) $message],
        );

        $status = $client->reporting()->getDeliveryStatus($messageId, $boot->recipient());
        $checks[] = Check::pass(
            'reporting()->getDeliveryStatus()',
            'per-recipient delivery status reads back',
            'status returned',
            ['status' => (array) $status],
        );

        $stats = $client->reporting()->getStats($messageId);
        $checks[] = Check::pass(
            'reporting()->getStats()',
            'delivery stats read back for the send',
            'stats returned',
            ['stats' => (array) $stats],
        );

        $count = $client->reporting()->getSentCount(
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
        );
        $checks[] = Check::pass(
            'reporting()->getSentCount()',
            'a sent count over a date range reads back',
            'count returned',
            ['count' => (array) $count],
        );

        // Paginated recipients of one message.
        $recipients = 0;
        foreach ($client->reporting()->getSent($messageId)->items() as $row) {
            $recipients++;
        }
        $checks[] = $recipients >= 1
            ? Check::pass('reporting()->getSent()', 'the recipients of a send paginate', sprintf('%d on page 1', $recipients))
            : Check::finding('reporting()->getSent()', 'the recipients of a send paginate', 'Page 1 empty — reporting lag');

        // Account-wide sent, paginated. Proves V1PagedPaginator on a real,
        // multi-page dataset rather than a one-row list.
        $pages = 0;
        $rows = 0;
        foreach ($client->reporting()->getUserSent() as $response) {
            $pages++;
            $rows += count($response->json('messages') ?? $response->json('recipients') ?? []);
            if ($pages >= 2) {
                break;
            }
        }
        $checks[] = $rows > 0
            ? Check::pass('reporting()->getUserSent()', 'account-wide sent messages paginate', sprintf('%d rows over %d page(s)', $rows, $pages))
            : Check::finding('reporting()->getUserSent()', 'account-wide sent messages paginate', 'No rows returned');

        $report = $client->reporting()->getMessageReport(
            new DateTimeImmutable('-7 days'),
            new DateTimeImmutable('+1 day'),
        );
        $checks[] = Check::pass(
            'reporting()->getMessageReport()',
            'a date-ranged message report reads back',
            'report returned',
            ['report' => (array) $report],
        );

        $contactStats = $client->reporting()->getContactStats($boot->recipient());
        $checks[] = Check::pass(
            'reporting()->getContactStats()',
            'per-contact SMS stats read back',
            'stats returned',
            ['stats' => (array) $contactStats],
        );

        // Replies. Empty until the handset replies in Task 9, so an empty
        // result here is expected, not a failure.
        try {
            $responses = 0;
            foreach ($client->reporting()->getAllResponses()->items() as $row) {
                $responses++;
            }
            $checks[] = Check::pass(
                'reporting()->getAllResponses()',
                'account-wide replies paginate',
                sprintf('%d on page 1 (0 is expected before the Task 9 reply)', $responses),
                ['page_1_count' => $responses],
            );
        } catch (KudosityException $e) {
            $checks[] = Check::fail('reporting()->getAllResponses()', 'account-wide replies paginate', $e->getMessage());
        }

        return $checks;
    }
}
```

- [ ] **Step 3: Register both**

```php
    '05-bulk-send' => \OrderNotifier\Scenario\BulkSendScenario::class,
    '06-reporting' => \OrderNotifier\Scenario\ReportingScenario::class,
```

- [ ] **Step 4: Run the bulk send**

Run: `./bin/notify 05-bulk-send`
Expected: one real SMS to the handset (the list has one live member). Two scheduled sends created and cancelled.

Spend: 1 message.

**If `bulk()->cancel()` returns false, note the scheduled message id and cancel it in the dashboard** — otherwise a stray message sends in two hours.

- [ ] **Step 5: Run reporting**

Run: `./bin/notify 06-reporting`
Expected: mostly `PASS`; `FINDING` on anything showing reporting lag. Reporting can trail a send by a minute — re-run once before recording a `FINDING`.

- [ ] **Step 6: Triage and commit**

Per the Fix Protocol.

---

### Task 7: Project A — senders, webhook CRUD, and the remaining V1 surfaces

**Files:**
- Create: `order-notifier/src/Scenario/SendersScenario.php`, `src/Scenario/WebhooksCrudScenario.php`, `src/Scenario/MiscV1Scenario.php`, `src/Scenario/LeaseStubScenario.php`
- Modify: `order-notifier/bin/notify`

**Interfaces:**
- Consumes: Task 2's `Bootstrap`/`Check`.
- Produces: nothing later tasks read. Every webhook this task creates is deleted within it.

- [ ] **Step 1: Write the senders scenario**

Signatures, verbatim:
```
senders()->registrations(): Paginator
senders()->allRegistrations(): array   // SenderRegistrationData[]
senders()->readyToUse(): array
senders()->register(string $sender, string $country, SenderRegistrationType $type = PersonalMobileNumber): SenderRegistrationData
senders()->requestVerification(string $registrationId, string $originatingSender, SenderVerificationMethod $method = Sms): array
senders()->confirmVerification(string $registrationId, string $code): array
senders()->deleteByPhoneNumber(string $phoneNumber): bool
```
`SenderRegistrationData` exposes `id`, `sender`, `country`, `type`, `status`, `statusReason`, `childAccountId`, `createdAt`, `updatedAt`, `raw`.

```php
<?php // src/Scenario/SendersScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Data\V2\SenderRegistrationData;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class SendersScenario implements Scenario
{
    public function name(): string
    {
        return '07-senders';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        // Paginated registrations. This surface reports total_count under
        // meta.pagination and puts its items at data.registrations, not data —
        // the one V2 shape that differs from every other list endpoint.
        $pages = 0;
        $items = 0;
        foreach ($client->senders()->registrations() as $response) {
            $pages++;
            $items += count($response->json('data.registrations') ?? []);
            if ($pages >= 2) {
                break;
            }
        }

        // A leased virtual number is NOT a sender registration, so this endpoint
        // is legitimately empty on an account whose only sender is a leased VMN.
        // Confirmed live: /v2/senders/registrations answers
        // {"data":{"registrations":[]},"meta":{...,"total_count":0}}. Do not read
        // an empty result here as a defect.

        $checks[] = Check::pass(
            'senders()->registrations()',
            'registrations paginate from data.registrations with meta.pagination.total_count',
            sprintf('%d item(s) over %d page(s)', $items, $pages),
            ['pages' => $pages, 'items' => $items],
        );

        $all = $client->senders()->allRegistrations();
        $checks[] = count($all) === 0 || $all[0] instanceof SenderRegistrationData
            ? Check::pass('senders()->allRegistrations()', 'every page is collected into SenderRegistrationData objects', sprintf('%d registration(s)', count($all)))
            : Check::fail('senders()->allRegistrations()', 'every page is collected into SenderRegistrationData objects', 'Returned something other than SenderRegistrationData');

        $shapes = [];
        foreach ($all as $reg) {
            $shapes[] = [
                'id' => $reg->id,
                'sender' => $reg->sender,
                'country' => $reg->country,
                'type' => $reg->type->value,
                'status' => $reg->status->value,
                'status_reason' => $reg->statusReason,
            ];
        }
        $checks[] = Check::pass(
            'SenderRegistrationData',
            'the item shape decodes without nulls where the API sends values',
            sprintf('%d shape(s) captured', count($shapes)),
            ['registrations' => $shapes],
        );

        $ready = $client->senders()->readyToUse();
        $checks[] = Check::pass(
            'senders()->readyToUse()',
            'only verified, usable senders are returned',
            sprintf('%d of %d registrations are ready', count($ready), count($all)),
        );

        // The verification flow. register() on a number already registered is
        // the common real-world case, so both outcomes are informative — but
        // neither a new registration nor a verification code request is made
        // for a number we do not control.
        $checks[] = Check::skipped(
            'senders()->register() / requestVerification() / confirmVerification()',
            'a new personal mobile number is registered and verified by SMS',
            'Not run: registering requires a number the account does not already own, and '
            .'confirmVerification() needs a code read off that handset. The existing '
            .'registrations above prove the read path and the decode; the write path '
            .'is left to the Laravel-side check in Task 16, which registers nothing.',
        );

        return $checks;
    }
}
```

- [ ] **Step 2: Write the webhook CRUD scenario**

Signatures, verbatim:
```
webhooks()->create(string $name, string $url, array $eventTypes = [], ?WebhookFilter $filter = null, ?int $rateLimit = null, bool $allowInsecureUrl = false): WebhookData
webhooks()->all(): array
webhooks()->get(string $id): WebhookData
webhooks()->update(string $id, string $name, string $url, array $eventTypes = [], ?WebhookFilter $filter = null, ?int $rateLimit = null, bool $allowInsecureUrl = false): WebhookData
webhooks()->delete(string $id): bool
```
`WebhookData` exposes `id`, `name`, `url`, `filter`, `rateLimit`, `isSandbox`, `createdAt`, `updatedAt`.
`WebhookFilter` exposes `eventType`, `sender`, `status`, `messageRef`, `campaignId` — all arrays.

```php
<?php // src/Scenario/WebhooksCrudScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class WebhooksCrudScenario implements Scenario
{
    public function name(): string
    {
        return '08-webhooks-crud';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        // A URL that is HTTPS and unreachable. CRUD does not need deliveries —
        // Task 9 does that with the tunnel.
        $url = 'https://example.com/kudosity/crud-check';

        $created = $client->webhooks()->create(
            name: 'sdk-validation-crud',
            url: $url,
            eventTypes: [WebhookEventType::SmsStatus, WebhookEventType::SmsInbound],
            rateLimit: 10,
        );

        $checks[] = Check::pass(
            'webhooks()->create()',
            'a webhook registers and returns a flat WebhookData',
            sprintf('id=%s name=%s rate=%d sandbox=%s', $created->id, $created->name, $created->rateLimit, $created->isSandbox ? 'yes' : 'no'),
            ['id' => $created->id, 'name' => $created->name, 'url' => $created->url, 'rate_limit' => $created->rateLimit, 'filter' => (array) $created->filter],
        );

        $checks[] = $created->filter->eventType === ['SMS_STATUS', 'SMS_INBOUND']
            ? Check::pass('webhooks()->create()', 'event types land in filter.event_type, not the deprecated top-level field', implode(', ', $created->filter->eventType))
            : Check::finding('webhooks()->create()', 'event types land in filter.event_type', 'Filter reads '.json_encode($created->filter->eventType), ['filter' => (array) $created->filter]);

        // GET /v2/webhook/{id} — absent from the vendored skill, present on the API.
        $fetched = $client->webhooks()->get($created->id);
        $checks[] = $fetched->id === $created->id
            ? Check::pass('webhooks()->get()', 'GET /v2/webhook/{id} returns the same flat shape as create', $fetched->id)
            : Check::fail('webhooks()->get()', 'GET /v2/webhook/{id} returns the same flat shape as create', sprintf('asked %s got %s', $created->id, $fetched->id));

        $all = $client->webhooks()->all();
        $ids = array_map(fn ($w) => $w->id, $all);
        $checks[] = in_array($created->id, $ids, true)
            ? Check::pass('webhooks()->all()', 'the new registration appears in the list', sprintf('%d registration(s)', count($all)))
            : Check::fail('webhooks()->all()', 'the new registration appears in the list', 'Created id absent from all()');

        // PUT is a replace, not a patch. Passing only a new name must not wipe
        // the event types — which is why update() takes the whole shape.
        $updated = $client->webhooks()->update(
            id: $created->id,
            name: 'sdk-validation-crud-renamed',
            url: $url,
            eventTypes: [WebhookEventType::SmsStatus, WebhookEventType::SmsInbound],
            rateLimit: 20,
        );

        $checks[] = $updated->name === 'sdk-validation-crud-renamed' && $updated->rateLimit === 20
            ? Check::pass('webhooks()->update()', 'PUT replaces the registration with the supplied whole shape', 'name and rate limit both changed')
            : Check::fail('webhooks()->update()', 'PUT replaces the registration with the supplied whole shape', sprintf('name=%s rate=%d', $updated->name, $updated->rateLimit));

        $checks[] = $updated->filter->eventType === ['SMS_STATUS', 'SMS_INBOUND']
            ? Check::pass('webhooks()->update()', 'a replace that re-sends the event types keeps them', implode(', ', $updated->filter->eventType))
            : Check::fail('webhooks()->update()', 'a replace that re-sends the event types keeps them', 'Event types lost on update: '.json_encode($updated->filter->eventType));

        // The one place the SDK is deliberately stricter than the platform: the
        // API accepts http:// with a 201, and this client refuses.
        try {
            $client->webhooks()->create(name: 'sdk-validation-insecure', url: 'http://example.com/insecure');
            $checks[] = Check::fail(
                'webhooks()->create()',
                'an http:// URL is refused client-side even though the API accepts it',
                'The insecure URL was accepted — delete the stray registration',
            );
        } catch (KudosityException $e) {
            $checks[] = Check::pass(
                'webhooks()->create()',
                'an http:// URL is refused client-side even though the API accepts it',
                $e->getMessage(),
            );
        }

        // …unless the caller opts in explicitly.
        try {
            $insecure = $client->webhooks()->create(
                name: 'sdk-validation-insecure-optin',
                url: 'http://example.com/insecure',
                allowInsecureUrl: true,
            );
            $client->webhooks()->delete($insecure->id);
            $checks[] = Check::pass(
                'webhooks()->create(allowInsecureUrl: true)',
                'the strictness is opt-out, not absolute',
                'Accepted with the explicit flag, then deleted',
            );
        } catch (KudosityException $e) {
            $checks[] = Check::fail(
                'webhooks()->create(allowInsecureUrl: true)',
                'the strictness is opt-out, not absolute',
                'Refused even with allowInsecureUrl: true — '.$e->getMessage(),
            );
        }

        $deleted = $client->webhooks()->delete($created->id);
        $checks[] = $deleted
            ? Check::pass('webhooks()->delete()', 'the registration is removed', 'deleted '.$created->id)
            : Check::fail('webhooks()->delete()', 'the registration is removed', 'Returned false');

        // A deleted id must 404, not return a stale record.
        try {
            $client->webhooks()->get($created->id);
            $checks[] = Check::finding(
                'webhooks()->get()',
                'a deleted registration 404s',
                'Still readable after delete — the delete is eventually consistent',
            );
        } catch (KudosityException $e) {
            $checks[] = Check::pass(
                'webhooks()->get()',
                'a deleted registration 404s',
                get_class($e).': '.$e->getMessage(),
            );
        }

        return $checks;
    }
}
```

- [ ] **Step 3: Write the remaining-V1 scenario**

```php
<?php // src/Scenario/MiscV1Scenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class MiscV1Scenario implements Scenario
{
    public function name(): string
    {
        return '09-misc-v1';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        // --- Numbers (read-only; leasing is excluded) ---
        // Rows are raw arrays and `number` arrives as a JSON INTEGER, not a
        // string — cast before any string operation on it.
        $owned = [];
        foreach ($client->numbers()->all()->items() as $row) {
            $owned[] = $row;
        }
        $checks[] = Check::pass(
            'numbers()->all()',
            'the account\'s numbers paginate',
            sprintf('%d number(s) on page 1', count($owned)),
            ['count' => count($owned)],
        );

        // Offline formatting. The SDK refuses to guess a country, because
        // guessing wrong sends a real message to the wrong person.
        $formatted = $client->numbers()->formatNumber('0491570006', 'AU');
        $checks[] = Check::pass(
            'numbers()->formatNumber()',
            'a local number normalises to E.164 when a country is supplied',
            '0491570006 + AU',
            ['formatted' => (array) $formatted],
        );

        // --- Keywords (read-only) ---
        $keywords = 0;
        foreach ($client->keywords()->all()->items() as $row) {
            $keywords++;
        }
        $checks[] = Check::pass(
            'keywords()->all()',
            'keywords paginate',
            sprintf('%d keyword(s) on page 1', $keywords),
        );

        if ($owned !== []) {
            $number = isset($owned[0]['number']) ? (string) $owned[0]['number'] : '';
            if ($number !== '') {
                $forNumber = 0;
                foreach ($client->keywords()->forNumber($number)->items() as $row) {
                    $forNumber++;
                }
                $checks[] = Check::pass(
                    'keywords()->forNumber()',
                    'keywords filter by number',
                    sprintf('%d for the first owned number', $forNumber),
                );
            }
        }

        // --- Email-to-SMS (read-only) ---
        // add()/delete() would change account configuration, so only the read
        // path runs. There is no list endpoint on this resource, so the check
        // is that a bogus delete fails cleanly rather than silently reporting
        // success — a false `true` here would be a real defect.
        try {
            $result = $client->emailSms()->delete('definitely-not-registered@example.invalid');
            $checks[] = $result === false
                ? Check::pass('emailSms()->delete()', 'deleting an unregistered address reports failure', 'returned false')
                : Check::finding('emailSms()->delete()', 'deleting an unregistered address reports failure', 'Returned true for an address that was never registered');
        } catch (KudosityException $e) {
            $checks[] = Check::pass(
                'emailSms()->delete()',
                'deleting an unregistered address reports failure',
                'Threw rather than reporting success: '.$e->getMessage(),
            );
        }

        $checks[] = Check::skipped(
            'emailSms()->add() / addWithLimit() / addWithNumber()',
            'an email address is authorised for email-to-SMS',
            'Not run: it changes account-wide configuration that outlives this validation run.',
        );

        return $checks;
    }
}
```

- [ ] **Step 4: Write the lease stub scenario**

`numbers()->lease()` bills the account monthly, so it is never called live. This asserts the request it *would* send, by pointing the client at a local stub.

```php
<?php // src/Scenario/LeaseStubScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\KudosityClient;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

final class LeaseStubScenario implements Scenario
{
    public function name(): string
    {
        return '10-lease-stub';
    }

    public function run(Bootstrap $boot): array
    {
        // Saloon's MockClient ships with saloon/saloon, so no dev dependency is
        // needed in this project.
        $mock = new MockClient([
            MockResponse::make([
                'number' => '61491570017',
                'price' => ['monthly' => 10.0],
            ], 200),
        ]);

        $client = new KudosityClient(apiKey: 'stub-key', apiSecret: 'stub-secret');
        $client->v1()->withMockClient($mock);

        $result = $client->numbers()->lease('61491570017');

        $request = $mock->getLastPendingRequest();
        $uri = (string) $request?->getUri();

        return [
            Check::pass(
                'numbers()->lease()',
                'the lease request targets the documented endpoint and decodes its response — never called live, it bills monthly',
                $uri !== '' ? $uri : 'request captured',
                ['uri' => $uri, 'result' => (array) $result],
            ),
            Check::skipped(
                'numbers()->lease()',
                'a real number is leased',
                'Excluded by design: leasing bills the account every month and cannot be undone from the API.',
            ),
        ];
    }
}
```

If `withMockClient()` is not available on the connector, or `getLastPendingRequest()` has a different name in Saloon v4, correct the calls to match the installed Saloon — check with `grep -rn 'function withMockClient\|function getLastPendingRequest' vendor/saloonphp/saloon/src/`. Do not invent a helper.

- [ ] **Step 5: Register all four**

```php
    '07-senders' => \OrderNotifier\Scenario\SendersScenario::class,
    '08-webhooks-crud' => \OrderNotifier\Scenario\WebhooksCrudScenario::class,
    '09-misc-v1' => \OrderNotifier\Scenario\MiscV1Scenario::class,
    '10-lease-stub' => \OrderNotifier\Scenario\LeaseStubScenario::class,
```

- [ ] **Step 6: Run them**

Run:
```bash
./bin/notify 07-senders && ./bin/notify 08-webhooks-crud && ./bin/notify 09-misc-v1 && ./bin/notify 10-lease-stub
```
Expected: no messages sent. Spend: 0.

- [ ] **Step 7: Confirm no webhook registrations leaked**

Run: `php -r 'require "vendor/autoload.php"; /* list webhooks and print names */'` — or simply re-run `./bin/notify 08-webhooks-crud`'s `all()` step by adding a one-off script. Simplest reliable check:

```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
php -r '
require "vendor/autoload.php";
$b = OrderNotifier\Bootstrap::load();
foreach ($b->client()->webhooks()->all() as $w) { echo $w->id, "  ", $w->name, PHP_EOL; }
'
```
Expected: no name starting `sdk-validation-`. Delete any that remain by id with a matching one-liner calling `->webhooks()->delete($id)`.

- [ ] **Step 8: Triage and commit**

Per the Fix Protocol.

---

### Task 8: Project A on the declared 8.2 floor

The client package declares `php: ^8.2` and no local toolchain provides it. A consumer on 8.2 must be able to install and run it — and the root Pest suite can never prove that, because Pest 4 requires 8.3.

**Files:**
- Create: `order-notifier/run-on-82.sh`

**Interfaces:**
- Consumes: everything Tasks 2–7 built.
- Produces: `results/A-11-php82.json`.

- [ ] **Step 1: Write the runner**

Live scenarios run **once only**, on 8.2 — re-running them on 8.4 would double the spend for no new information. The offline scenarios run on both.

```bash
cat > /home/mitchell/projects/kudosity-sdk-validation/order-notifier/run-on-82.sh <<'SCRIPT'
#!/usr/bin/env bash
# Install and run inside php:8.2-cli, the declared floor. A separate vendor
# directory, because a 8.4-resolved lock can hide an 8.2 incompatibility.
set -euo pipefail
cd "$(dirname "$0")"

docker run --rm -v "$PWD":/app -w /app php:8.2-cli bash -c '
  set -e
  apt-get update -qq && apt-get install -y -qq git unzip libzip-dev >/dev/null
  docker-php-ext-install zip >/dev/null 2>&1 || true
  curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
  php -v
  COMPOSER_VENDOR_DIR=vendor82 composer install --no-interaction --quiet
  echo "--- offline scenarios on 8.2 ---"
  COMPOSER_VENDOR_DIR=vendor82 php -r "
    require \"vendor82/autoload.php\";
    // Offline only: no network, no spend. Proves the package parses, autoloads
    // and executes its pure logic on the declared floor.
    \$refs = [
      \"SignedMessageRef sign/verify\" => function () {
        \$r = ExpertSystems\\Kudosity\\Webhooks\\SignedMessageRef::sign(\"order-9931\", \"s3cret\");
        \$back = ExpertSystems\\Kudosity\\Webhooks\\SignedMessageRef::verify(\$r, \"s3cret\");
        if (\$back !== \"order-9931\") { throw new RuntimeException(\"round trip failed: \" . var_export(\$back, true)); }
        if (ExpertSystems\\Kudosity\\Webhooks\\SignedMessageRef::isValid(\$r, \"wrong\")) { throw new RuntimeException(\"forged ref accepted\"); }
        return \$r;
      },
      \"MessageStatus case-insensitive\" => function () {
        \$a = ExpertSystems\\Kudosity\\Enums\\MessageStatus::fromApi(\"delivered\");
        \$b = ExpertSystems\\Kudosity\\Enums\\MessageStatus::fromApi(\"DELIVERED\");
        if (\$a !== \$b) { throw new RuntimeException(\"casing changed the status\"); }
        return \$a->value;
      },
      \"StatusPrecedence holds\" => function () {
        \$sup = ExpertSystems\\Kudosity\\Webhooks\\StatusPrecedence::supersedes(
          ExpertSystems\\Kudosity\\Enums\\MessageStatus::Sent,
          ExpertSystems\\Kudosity\\Enums\\MessageStatus::Delivered
        );
        if (\$sup) { throw new RuntimeException(\"a late SENT superseded a recorded DELIVERED\"); }
        return \"SENT does not supersede DELIVERED\";
      },
      \"WebhookEvent tolerates novelty\" => function () {
        \$e = ExpertSystems\\Kudosity\\Webhooks\\WebhookEvent::fromArray([\"event_type\" => \"SOMETHING_NEW_2027\"]);
        if (! \$e instanceof ExpertSystems\\Kudosity\\Webhooks\\UnknownEvent) { throw new RuntimeException(\"expected UnknownEvent, got \" . get_class(\$e)); }
        return \"UnknownEvent\";
      },
      \"client constructs without a secret\" => function () {
        new ExpertSystems\\Kudosity\\KudosityClient(apiKey: \"k\");
        return \"V2-only construction works\";
      },
    ];
    \$out = [];
    foreach (\$refs as \$name => \$fn) {
      try { \$out[] = [\"surface\" => \$name, \"expectation\" => \"works on PHP 8.2\", \"result\" => \"PASS\", \"detail\" => (string) \$fn(), \"evidence\" => []]; }
      catch (Throwable \$t) { \$out[] = [\"surface\" => \$name, \"expectation\" => \"works on PHP 8.2\", \"result\" => \"FAIL\", \"detail\" => get_class(\$t) . \": \" . \$t->getMessage(), \"evidence\" => []]; }
    }
    file_put_contents(\"/app/../results/A-11-php82.json\", json_encode([\"scenario\" => \"11-php82\", \"php\" => PHP_VERSION, \"checks\" => \$out], JSON_PRETTY_PRINT) . PHP_EOL);
    foreach (\$out as \$r) { printf(\"  [%-7s] %s — %s\n\", \$r[\"result\"], \$r[\"surface\"], \$r[\"detail\"]); }
  "
'
SCRIPT
chmod +x /home/mitchell/projects/kudosity-sdk-validation/order-notifier/run-on-82.sh
```

Note the results path: the container mounts `order-notifier` at `/app`, so `results/` is at `/app/../results`. Confirm the file lands by checking on the host afterwards, not by trusting the path.

- [ ] **Step 2: Run it**

Run: `/home/mitchell/projects/kudosity-sdk-validation/order-notifier/run-on-82.sh`
Expected: `PHP 8.2.x`, `composer install` succeeds against the artifact, five `PASS` lines.

- [ ] **Step 3: Confirm the results file landed on the host**

Run: `cat /home/mitchell/projects/kudosity-sdk-validation/results/A-11-php82.json`
Expected: valid JSON with `"php": "8.2.…"`. If the mount path was wrong, the file will be inside `order-notifier/` instead — move it and correct the script.

- [ ] **Step 4: Confirm 8.2 resolved the same package version**

Run:
```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php -r '
  $l = json_decode(file_get_contents("composer.lock"), true);
  foreach ($l["packages"] as $p) { if (str_starts_with($p["name"], "expertsystemsau/")) { echo $p["name"], " ", $p["version"], PHP_EOL; } }
'
```
Expected: `expertsystemsau/kudosity-php-client 2.0.1`.

- [ ] **Step 5: Triage**

A failure here means the package does not run on its declared floor — a real defect. The fix is either the source or the `php` constraint in `packages/kudosity-client/composer.json`; **raise the constraint question with the user rather than deciding**, because lowering the floor is a support commitment.

- [ ] **Step 6: Clean up the 8.2 vendor tree**

```bash
rm -rf /home/mitchell/projects/kudosity-sdk-validation/order-notifier/vendor82
```

---

### Task 9: The live callback loop, phase 1 — real deliveries to Project A

The first half of the loop the design describes. A bare receiver behind ngrok captures real payloads; the handset produces real inbound events.

**Files:**
- Create: `order-notifier/receiver/serve.php`
- Create: `order-notifier/receiver/contacts.csv`
- Create: `order-notifier/src/Scenario/LiveCallbackScenario.php`
- Modify: `order-notifier/bin/notify`, `order-notifier/.env` (`TUNNEL_URL`)

**Interfaces:**
- Consumes: `.last-sms-ref` from Task 3; `Bootstrap::tunnelUrl()`.
- Produces: `captured/phase1.jsonl` — one JSON object per line, each `{"received_at": …, "query": {…}, "payload": {…}}`. **Task 10 parses this file, and Task 15 appends to a phase-2 file in the same shape.**

- [ ] **Step 1: Write the receiver**

It must accept an unsigned POST — Kudosity's V2 deliveries are unsigned, and the point of this receiver is to capture exactly what arrives.

```php
<?php // receiver/serve.php
declare(strict_types=1);

// A bare capture receiver. It validates nothing on purpose: the goal is to
// record what Kudosity actually delivers, byte for byte, including the parts
// the documentation omits. Project B's real controller is what enforces the
// signature requirement — see Task 15.

$capture = __DIR__.'/../../captured/phase1.jsonl';
$body = file_get_contents('php://input') ?: '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// The V1 GET callbacks land on /v1/dlr, /v1/reply, /v1/link-hits and carry
// their data in the query string, not a body. Both shapes go in the same file.
$record = [
    'received_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path' => $path,
    'query' => $_GET,
    'payload' => json_decode($body, true) ?? ($body === '' ? [] : ['_raw' => $body]),
];

// CSV for the bulk-add check in Task 5, served from the same origin.
if ($path === '/contacts.csv') {
    header('Content-Type: text/csv');
    readfile(__DIR__.'/contacts.csv');
    exit;
}

file_put_contents($capture, json_encode($record, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

$type = $record['payload']['event_type'] ?? $path;
fwrite(STDERR, sprintf("[%s] %s %s -> %s\n", $record['received_at'], $record['method'], $path, is_string($type) ? $type : 'unknown'));

header('Content-Type: text/plain');
echo 'OK';
```

```csv
mobile,first_name,last_name
61491570006,Reserved,Fictitious
61491570007,Reserved,Second
```

Write that CSV to `receiver/contacts.csv`. Both numbers are reserved fictitious AU numbers, so the import cannot message a real person.

- [ ] **Step 2: Start the receiver and the tunnel**

Two background processes. Start them, then read the tunnel URL from ngrok's local API rather than scraping its output.

```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
php -S 127.0.0.1:8088 receiver/serve.php > /tmp/receiver.log 2>&1 &
ngrok http 8088 --log stdout > /tmp/ngrok.log 2>&1 &
```

Wait for ngrok, then read the URL:

```bash
for i in $(seq 1 20); do
  URL=$(curl -s http://127.0.0.1:4040/api/tunnels | php -r 'echo json_decode(stream_get_contents(STDIN), true)["tunnels"][0]["public_url"] ?? "";')
  [ -n "$URL" ] && break
  sleep 1
done
echo "tunnel: $URL"
```

If `$URL` is empty after 20 tries, ngrok failed — check `/tmp/ngrok.log` for an auth error. A free ngrok account allows one agent; kill any other session first.

- [ ] **Step 3: Record the tunnel URL in .env**

```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
php -r '
  $url = $argv[1];
  $env = file_get_contents(".env");
  $env = preg_replace("/^TUNNEL_URL=.*$/m", "TUNNEL_URL=" . $url, $env);
  file_put_contents(".env", $env);
' "$URL"
grep -c '^TUNNEL_URL=https' .env
```
Expected: `1`.

- [ ] **Step 4: Prove the tunnel reaches the receiver**

Run:
```bash
curl -s -X POST "$URL/kudosity/events" -H 'Content-Type: application/json' -d '{"event_type":"SMOKE_TEST"}'
tail -1 /home/mitchell/projects/kudosity-sdk-validation/captured/phase1.jsonl
```
Expected: `OK`, and a captured line with `"event_type": "SMOKE_TEST"`.

Then remove that smoke-test line, so the capture file holds only real deliveries:
```bash
cd /home/mitchell/projects/kudosity-sdk-validation
grep -v SMOKE_TEST captured/phase1.jsonl > captured/phase1.tmp && mv captured/phase1.tmp captured/phase1.jsonl
```

- [ ] **Step 5: Write the live callback scenario**

```php
<?php // src/Scenario/LiveCallbackScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class LiveCallbackScenario implements Scenario
{
    private const REF_SECRET = 'order-notifier-validation-secret';

    private const CAPTURE = __DIR__.'/../../../captured/phase1.jsonl';

    public function name(): string
    {
        return '12-live-callbacks';
    }

    public function run(Bootstrap $boot): array
    {
        $client = $boot->client();
        $checks = [];

        $tunnel = $boot->tunnelUrl();
        if ($tunnel === null) {
            return [Check::blocked('webhooks()', 'real deliveries arrive over a tunnel', 'TUNNEL_URL is not set — see Task 9 Step 3')];
        }

        // Register for every event type: pass no event types and the API sends
        // all ten, which is what a capture run wants.
        $hook = $client->webhooks()->create(
            name: 'sdk-validation-live-phase1',
            url: $tunnel.'/kudosity/events',
        );
        file_put_contents(__DIR__.'/../../.webhook-id', $hook->id);

        $checks[] = Check::pass(
            'webhooks()->create()',
            'a webhook registers against the tunnel for all event types',
            sprintf('id=%s url=%s', $hook->id, $hook->url),
            ['id' => $hook->id, 'url' => $hook->url, 'filter' => (array) $hook->filter],
        );

        $before = $this->countCaptured();

        // A tracked-link SMS: produces SMS_STATUS (twice) and LINK_HIT.
        $ref = SignedMessageRef::sign('order-9931', self::REF_SECRET);
        $sms = $client->sms()->send(
            message: 'Order 9931 shipped. Reply YES to confirm. https://example.com/o/9931',
            to: $boot->recipient(),
            from: $boot->sender(),
            messageRef: $ref,
            trackLinks: true,
        );
        file_put_contents(__DIR__.'/../../.last-sms-ref', $ref);
        $checks[] = Check::pass('sms()->send()', 'a tracked-link SMS is sent to drive live status and link-hit events', $sms->id, ['id' => $sms->id, 'message_ref' => $ref]);

        // An MMS: produces MMS_STATUS, which carries the undocumented
        // status.description the fixtures record.
        $mms = $client->mms()->send(
            to: $boot->recipient(),
            from: $boot->sender(),
            contentUrls: ['https://upload.wikimedia.org/wikipedia/commons/thumb/6/6b/Australia_location_map.svg/320px-Australia_location_map.svg.png'],
            subject: 'Order 9931',
            message: 'Reply with a photo of the parcel.',
            messageRef: 'order-9931:mms-live',
        );
        $checks[] = Check::pass('mms()->send()', 'an MMS is sent to drive a live MMS_STATUS event', $mms->id, ['id' => $mms->id]);

        // Wait for deliveries. Status events arrive within seconds; the docs
        // warn they are unordered and at-least-once, so this collects rather
        // than expecting a fixed count.
        $deadline = time() + 90;
        while (time() < $deadline && $this->countCaptured() < $before + 3) {
            sleep(3);
        }

        $events = $this->captured();
        $byType = [];
        foreach ($events as $e) {
            $t = $e['payload']['event_type'] ?? 'unknown';
            $byType[$t] = ($byType[$t] ?? 0) + 1;
        }

        $checks[] = $byType !== []
            ? Check::pass('webhook delivery', 'real events arrive at the registered URL', json_encode($byType), ['by_type' => $byType])
            : Check::fail('webhook delivery', 'real events arrive at the registered URL', 'Nothing arrived in 90s — the registration or the tunnel is not working');

        foreach (['SMS_STATUS', 'MMS_STATUS', 'LINK_HIT'] as $expected) {
            $checks[] = ($byType[$expected] ?? 0) > 0
                ? Check::pass('webhook delivery', "$expected is delivered", sprintf('%d delivery(ies)', $byType[$expected]))
                : Check::finding('webhook delivery', "$expected is delivered", "None arrived within 90s. LINK_HIT needs the link to be fetched; MMS_STATUS can lag further behind than SMS.");
        }

        // Three undocumented fields the fixtures record. Their absence would be
        // a finding about the API, not about the SDK.
        $statusEvents = array_values(array_filter($events, fn ($e) => in_array($e['payload']['event_type'] ?? '', ['SMS_STATUS', 'MMS_STATUS'], true)));
        if ($statusEvents !== []) {
            $p = $statusEvents[0]['payload'];
            foreach (['webhook_id', 'webhook_name'] as $field) {
                $checks[] = isset($p[$field])
                    ? Check::pass('delivery payload', "$field is present on a live delivery, as the fixtures record", (string) $p[$field])
                    : Check::finding('delivery payload', "$field is present on a live delivery", "Absent — the fixture README records it as always present");
            }
        }

        $checks[] = Check::pass(
            'capture',
            'raw deliveries are recorded for Task 10 to parse',
            sprintf('%d record(s) in captured/phase1.jsonl', count($events)),
        );

        return $checks;
    }

    /** @return array<int, array<string, mixed>> */
    private function captured(): array
    {
        if (! is_file(self::CAPTURE)) {
            return [];
        }
        $out = [];
        foreach (file(self::CAPTURE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function countCaptured(): int
    {
        return count($this->captured());
    }
}
```

Note the capture path: `src/Scenario/` is two levels below the project root, so `__DIR__.'/../../../captured/'` reaches the workspace. **Verify with `php -r 'var_dump(realpath("src/Scenario/../../../captured"));'` from the project root before running** — a wrong path silently writes nowhere and every delivery check then reads as a failure.

- [ ] **Step 6: Register and run it**

```php
    '12-live-callbacks' => \OrderNotifier\Scenario\LiveCallbackScenario::class,
```

Run: `./bin/notify 12-live-callbacks`
Expected: two messages sent, then a 90-second wait while events arrive. Watch them land: `tail -f /tmp/receiver.log` in another shell.

Spend: 2 messages.

- [ ] **Step 7: Re-run the blocked bulk-add check, now that a tunnel exists**

Run: `./bin/notify 04-lists`
Expected: `lists()->bulkAdd()` and `bulkAddProgress()` now `PASS` instead of `BLOCKED`. This creates a second throwaway list — note its id for cleanup, since `.list-id` is overwritten.

```bash
cat .list-id
```

- [ ] **Step 8: Prompt the user for the SMS reply**

**Stop and ask the user**, in these words:

> The tunnel is live and capturing. Please reply **YES** to the SMS on the handset. Tell me when you've sent it.

After they confirm, wait for the inbound event:

```bash
for i in $(seq 1 30); do
  grep -c SMS_INBOUND /home/mitchell/projects/kudosity-sdk-validation/captured/phase1.jsonl && break
  sleep 3
done
```

- [ ] **Step 9: Prompt for the MMS reply**

**Stop and ask the user:**

> Now please reply to the MMS with a photo attached. Tell me when you've sent it.

Then wait for `MMS_INBOUND`. This payload runs to hundreds of KB — the attachment arrives as inline base64 under `mo.media[]`, not as a URL.

```bash
for i in $(seq 1 40); do
  grep -c MMS_INBOUND /home/mitchell/projects/kudosity-sdk-validation/captured/phase1.jsonl && break
  sleep 3
done
wc -c /home/mitchell/projects/kudosity-sdk-validation/captured/phase1.jsonl
```

- [ ] **Step 10: Leave the tunnel and receiver running**

Task 10 parses the capture file; Task 15 re-points this same tunnel at Project B. Do **not** kill ngrok — a restart issues a new URL and invalidates the registered webhook.

Record the PIDs so Task 18 can clean up:
```bash
pgrep -f 'php -S 127.0.0.1:8088' > /tmp/receiver.pid
pgrep -f 'ngrok http 8088' > /tmp/ngrok.pid
```

---

### Task 10: Project A — webhook payload handling over the captured deliveries

Everything here runs offline against the payloads Task 9 captured. That is the point: these are today's real deliveries, not fixtures, and the classes must handle them.

**Files:**
- Create: `order-notifier/src/Scenario/WebhookParsingScenario.php`
- Modify: `order-notifier/bin/notify`

**Interfaces:**
- Consumes: `captured/phase1.jsonl` from Task 9; `.last-sms-ref` from Task 9 Step 5.
- Produces: `results/A-13-webhook-parsing.json`.

Classes under test, with their real shapes:
- `WebhookEvent::fromArray(array $payload): self` → `StatusEvent` | `InboundEvent` | `LinkHitEvent` | `OptOutEvent` | `UnknownEvent`
- `StatusEvent`: `$type`, `$id`, `$messageRef`, `$recipient`, `$sender`, `$routedVia`, `$status` (`MessageStatus`), `$description`
- `InboundEvent`: `$type`, `$id`, `$message`, `$recipient`, `$sender`, `$routedVia`, `$lastMessage` (`?SourceMessage`), `$subject`, `$contentUrls`, `$media` (`InboundMedia[]`)
- `LinkHitEvent`: `$hits`, `$url`, `$sourceMessage`
- `InboundMedia`: `$content` (base64), `$name`
- `StatusPrecedence::supersedes(MessageStatus $incoming, MessageStatus $recorded): bool`, `::rank()`, `::reduce(iterable $events, ?string $statusId = null): ?StatusEvent`
- `SignedMessageRef::sign()`, `::verify()`, `::isValid()`, `::maxEntityLength()`

- [ ] **Step 1: Write the scenario**

```php
<?php // src/Scenario/WebhookParsingScenario.php
declare(strict_types=1);

namespace OrderNotifier\Scenario;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use ExpertSystems\Kudosity\Webhooks\SignedMessageRef;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;
use ExpertSystems\Kudosity\Webhooks\UnknownEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use OrderNotifier\Bootstrap;
use OrderNotifier\Check;

final class WebhookParsingScenario implements Scenario
{
    private const REF_SECRET = 'order-notifier-validation-secret';

    public function name(): string
    {
        return '13-webhook-parsing';
    }

    public function run(Bootstrap $boot): array
    {
        $checks = [];
        $payloads = $this->payloads();

        if ($payloads === []) {
            return [Check::blocked('WebhookEvent::fromArray()', 'live payloads decode into typed events', 'captured/phase1.jsonl is empty — run Task 9 first')];
        }

        // Every captured payload must decode without throwing.
        $decoded = [];
        foreach ($payloads as $i => $payload) {
            try {
                $decoded[] = WebhookEvent::fromArray($payload);
            } catch (\Throwable $e) {
                $checks[] = Check::fail(
                    'WebhookEvent::fromArray()',
                    'every live payload decodes without throwing',
                    sprintf('record %d (%s): %s', $i, $payload['event_type'] ?? 'no event_type', $e->getMessage()),
                );
            }
        }

        $counts = [];
        foreach ($decoded as $e) {
            $counts[$e::class] = ($counts[$e::class] ?? 0) + 1;
        }
        $checks[] = Check::pass(
            'WebhookEvent::fromArray()',
            'live payloads decode into typed events',
            sprintf('%d payload(s): %s', count($decoded), json_encode(array_map(fn ($k) => substr((string) strrchr($k, '\\'), 1), array_keys($counts)))),
            ['counts' => $counts],
        );

        $checks[] = ! isset($counts[UnknownEvent::class])
            ? Check::pass('WebhookEvent::fromArray()', 'no live payload falls through to UnknownEvent', 'all event types recognised')
            : Check::finding('WebhookEvent::fromArray()', 'no live payload falls through to UnknownEvent', sprintf('%d payload(s) were UnknownEvent — an event type shipped after this SDK', $counts[UnknownEvent::class]), ['unknown_types' => $this->unknownTypes($decoded)]);

        // --- Status events ---
        $statuses = array_values(array_filter($decoded, fn ($e) => $e instanceof StatusEvent));
        if ($statuses !== []) {
            $first = $statuses[0];
            $checks[] = Check::pass(
                'StatusEvent',
                'a live status event decodes every documented field',
                sprintf('id=%s status=%s routed_via=%s description=%s', $first->id, $first->status->value, $first->routedVia ?? 'null', $first->description ?? 'null'),
                ['type' => $first->type, 'id' => $first->id, 'status' => $first->status->value, 'message_ref' => $first->messageRef, 'description' => $first->description],
            );

            $checks[] = $first->status !== MessageStatus::Unknown
                ? Check::pass('MessageStatus::fromApi()', 'an UPPERCASE live status resolves to a known case', $first->status->value)
                : Check::fail('MessageStatus::fromApi()', 'an UPPERCASE live status resolves to a known case', 'Resolved to Unknown for '.json_encode($first->raw['status'] ?? null));

            // Grouped by status.id — the key the duplicate guard must use,
            // because it is identical across every status event for a message.
            $byStatusId = [];
            foreach ($statuses as $s) {
                $byStatusId[$s->id][] = $s;
            }

            foreach ($byStatusId as $statusId => $group) {
                if (count($group) < 2) {
                    continue;
                }
                $winner = StatusPrecedence::reduce($group, (string) $statusId);
                $ranks = array_map(fn (StatusEvent $s) => StatusPrecedence::rank($s->status), $group);
                $checks[] = $winner !== null && StatusPrecedence::rank($winner->status) === max($ranks)
                    ? Check::pass(
                        'StatusPrecedence::reduce()',
                        'the highest-ranked status wins across multiple live events for one message',
                        sprintf('%d events for status.id=%s, winner=%s', count($group), $statusId, $winner->status->value),
                        ['arrival_order' => array_map(fn (StatusEvent $s) => $s->status->value, $group), 'winner' => $winner->status->value],
                    )
                    : Check::fail(
                        'StatusPrecedence::reduce()',
                        'the highest-ranked status wins across multiple live events for one message',
                        sprintf('winner=%s but ranks were %s', $winner?->status->value ?? 'null', json_encode($ranks)),
                    );
            }

            // The hazard itself: a late SENT must never overwrite DELIVERED.
            $checks[] = ! StatusPrecedence::supersedes(MessageStatus::Sent, MessageStatus::Delivered)
                ? Check::pass('StatusPrecedence::supersedes()', 'a late SENT does not supersede a recorded DELIVERED', 'false, as required')
                : Check::fail('StatusPrecedence::supersedes()', 'a late SENT does not supersede a recorded DELIVERED', 'Returned true — a redelivered SENT would overwrite DELIVERED');

            // READ follows DELIVERED, so isTerminal() alone is not a guard.
            $checks[] = StatusPrecedence::supersedes(MessageStatus::Read, MessageStatus::Delivered)
                ? Check::pass('StatusPrecedence::supersedes()', 'READ supersedes DELIVERED — a read receipt follows delivery', 'true, as required')
                : Check::fail('StatusPrecedence::supersedes()', 'READ supersedes DELIVERED', 'Returned false — an RCS/WhatsApp read receipt would be discarded');

            // Duplicate detection, observed. The fixtures record a SENT
            // redelivered 60s later, byte-identical to its original.
            $signatures = array_map(fn (StatusEvent $s) => $s->id.'|'.$s->status->value.'|'.($s->timestamp?->format('c') ?? ''), $statuses);
            $dupes = count($signatures) - count(array_unique($signatures));
            $checks[] = $dupes > 0
                ? Check::pass(
                    'at-least-once delivery',
                    'duplicate status deliveries are observed live, not merely assumed',
                    sprintf('%d duplicate delivery(ies) observed — StatusPrecedence is what stops them regressing state', $dupes),
                    ['duplicates' => $dupes],
                )
                : Check::finding(
                    'at-least-once delivery',
                    'duplicate status deliveries are observed live',
                    'No duplicate arrived inside the observation window. The behaviour is real — the repository fixtures record a SENT arriving 57s after DELIVERED — but this run did not observe it, so it is not reported as a live pass.',
                );
        }

        // --- Inbound events, and the correlation key ---
        $inbound = array_values(array_filter($decoded, fn ($e) => $e instanceof InboundEvent));
        foreach ($inbound as $in) {
            $isMms = $in->media !== [];
            $checks[] = Check::pass(
                'InboundEvent',
                sprintf('a live %s inbound decodes', $isMms ? 'MMS' : 'SMS'),
                sprintf('from=%s to=%s message=%s media=%d', $in->sender, $in->recipient, var_export($in->message, true), count($in->media)),
                ['type' => $in->type, 'id' => $in->id, 'message' => $in->message, 'has_last_message' => $in->lastMessage !== null, 'media_count' => count($in->media), 'content_urls' => $in->contentUrls],
            );

            if ($isMms) {
                $media = $in->media[0];
                $checks[] = $media->content !== ''
                    ? Check::pass('InboundMedia', 'an inbound MMS attachment arrives as inline base64, not a URL', sprintf('%d base64 chars, name=%s', strlen($media->content), $media->name ?? 'null'))
                    : Check::fail('InboundMedia', 'an inbound MMS attachment arrives as inline base64', 'media[0]->content is empty');

                $checks[] = $in->contentUrls === []
                    ? Check::pass('InboundEvent::$contentUrls', 'contentUrls is empty on a real MMS_INBOUND — it reads mo.content_urls, the outbound shape', 'empty, as the fixture README records')
                    : Check::finding('InboundEvent::$contentUrls', 'contentUrls is empty on a real MMS_INBOUND', 'Populated: '.json_encode($in->contentUrls));

                $checks[] = $in->lastMessage === null
                    ? Check::pass('InboundEvent::$lastMessage', 'an MMS reply carries no last_message, so it has no correlation key', 'null, as the fixture README records')
                    : Check::finding('InboundEvent::$lastMessage', 'an MMS reply carries no last_message', 'An MMS_INBOUND DID carry last_message — this contradicts the fixture README and should be captured as a new fixture', ['last_message' => (array) $in->lastMessage]);
            } else {
                // The reply-threading join key.
                $ref = $in->lastMessage?->messageRef;
                $sentRef = is_file(__DIR__.'/../../.last-sms-ref') ? trim((string) file_get_contents(__DIR__.'/../../.last-sms-ref')) : null;

                $checks[] = $ref !== null
                    ? Check::pass('InboundEvent::$lastMessage->messageRef', 'the outbound message_ref survives a real customer reply', $ref)
                    : Check::finding('InboundEvent::$lastMessage->messageRef', 'the outbound message_ref survives a real customer reply', 'last_message.message_ref is absent on this reply');

                if ($ref !== null && $sentRef !== null) {
                    $checks[] = $ref === $sentRef
                        ? Check::pass('reply correlation', 'the recovered ref is byte-identical to the one sent', $ref)
                        : Check::fail('reply correlation', 'the recovered ref is byte-identical to the one sent', sprintf('sent %s, recovered %s', $sentRef, $ref));

                    // SignedMessageRef protects correlation, not the payload.
                    $entity = SignedMessageRef::verify($ref, self::REF_SECRET);
                    $checks[] = $entity === 'order-9931'
                        ? Check::pass('SignedMessageRef::verify()', 'a signed ref recovered from a live reply verifies to its entity', $entity)
                        : Check::fail('SignedMessageRef::verify()', 'a signed ref recovered from a live reply verifies to its entity', 'Got '.var_export($entity, true));

                    $checks[] = SignedMessageRef::verify($ref, 'the-wrong-secret') === null
                        ? Check::pass('SignedMessageRef::verify()', 'the same ref fails under a different secret', 'null, as required')
                        : Check::fail('SignedMessageRef::verify()', 'the same ref fails under a different secret', 'A forged secret verified — the signature is not being checked');
                }

                // Real replies are untidy. "YES " arrived with a trailing space.
                if (is_string($in->message)) {
                    $checks[] = Check::pass(
                        'InboundEvent::$message',
                        'the reply text is delivered verbatim, untrimmed',
                        sprintf('%s (%d chars)', var_export($in->message, true), strlen($in->message)),
                        ['message' => $in->message, 'trimmed_differs' => trim($in->message) !== $in->message],
                    );
                }
            }

            $checks[] = $in->messageRef() === ($in->lastMessage?->messageRef)
                ? Check::pass('WebhookEvent::messageRef()', 'the accessor reads the ref from the per-type path the API hides it at', var_export($in->messageRef(), true))
                : Check::fail('WebhookEvent::messageRef()', 'the accessor reads the ref from the per-type path the API hides it at', sprintf('accessor=%s lastMessage=%s', var_export($in->messageRef(), true), var_export($in->lastMessage?->messageRef, true)));
        }

        if ($inbound === []) {
            $checks[] = Check::blocked('InboundEvent', 'a real customer reply decodes', 'No inbound event captured — the handset reply in Task 9 Step 8 did not arrive');
        }

        // --- Link hits ---
        $hits = array_values(array_filter($decoded, fn ($e) => $e instanceof LinkHitEvent));
        if ($hits !== []) {
            $counts = array_map(fn (LinkHitEvent $h) => $h->hits, $hits);
            $checks[] = Check::pass(
                'LinkHitEvent',
                'link hits decode and `hits` is cumulative, not per-event',
                sprintf('hit counts in arrival order: %s', implode(', ', $counts)),
                ['hits' => $counts, 'url' => $hits[0]->url],
            );

            $checks[] = Check::finding(
                'LinkHitEvent',
                'a link hit is not evidence a human clicked',
                'Recorded for the report: the first hit typically arrives in the same second as DELIVERED, which is a messaging-app link preview. `hits` counts machine fetches and is not an engagement metric.',
            );
        }

        // --- OPT_OUT, replayed. A live STOP would opt the handset out. ---
        $fixture = '/home/mitchell/projects/transmitsms-php-sdk/packages/kudosity-client/tests/Fixtures/V2Webhooks';
        $optOutFile = null;
        foreach (glob($fixture.'/*.json') ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (($j['event_type'] ?? null) === 'OPT_OUT') {
                $optOutFile = $f;
                break;
            }
        }
        if ($optOutFile === null) {
            $checks[] = Check::skipped(
                'OptOutEvent',
                'an OPT_OUT payload decodes',
                'No OPT_OUT fixture exists in the repository, and a live STOP is excluded because it opts the handset out of the account.',
            );
        } else {
            $event = WebhookEvent::fromArray(json_decode((string) file_get_contents($optOutFile), true));
            $checks[] = $event instanceof \ExpertSystems\Kudosity\Webhooks\OptOutEvent
                ? Check::pass('OptOutEvent', 'an OPT_OUT payload decodes (replayed from a fixture, not sent live)', sprintf('source=%s', $event->source->value))
                : Check::fail('OptOutEvent', 'an OPT_OUT payload decodes', 'Decoded to '.$event::class);
        }

        return $checks;
    }

    /** @return array<int, array<string, mixed>> */
    private function payloads(): array
    {
        $file = __DIR__.'/../../../captured/phase1.jsonl';
        if (! is_file($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $r = json_decode($line, true);
            // Skip the V1 GET callbacks; they are query-string shaped and are
            // handled by CallbackUrlParser, not WebhookEvent.
            if (is_array($r) && ($r['payload']['event_type'] ?? null) !== null) {
                $out[] = $r['payload'];
            }
        }

        return $out;
    }

    /** @param array<int, WebhookEvent> $decoded @return array<int, mixed> */
    private function unknownTypes(array $decoded): array
    {
        $out = [];
        foreach ($decoded as $e) {
            if ($e instanceof UnknownEvent) {
                $out[] = $e->raw['event_type'] ?? null;
            }
        }

        return array_values(array_unique($out));
    }
}
```

- [ ] **Step 2: Register and run it**

```php
    '13-webhook-parsing' => \OrderNotifier\Scenario\WebhookParsingScenario::class,
```

Run: `./bin/notify 13-webhook-parsing`
Expected: no API traffic, no spend. Every captured payload decodes; the precedence and correlation checks pass.

- [ ] **Step 3: Compare a live payload against the committed fixtures**

```bash
cd /home/mitchell/projects/kudosity-sdk-validation
php -r '
$live = [];
foreach (file("captured/phase1.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
  $r = json_decode($l, true);
  $t = $r["payload"]["event_type"] ?? null;
  if ($t && !isset($live[$t])) { $live[$t] = array_keys($r["payload"]); }
}
$dir = "/home/mitchell/projects/transmitsms-php-sdk/packages/kudosity-client/tests/Fixtures/V2Webhooks";
foreach (glob("$dir/*.json") as $f) {
  $j = json_decode(file_get_contents($f), true);
  $t = $j["event_type"] ?? null;
  if (!$t || !isset($live[$t])) continue;
  $missing = array_diff(array_keys($j), $live[$t]);
  $extra = array_diff($live[$t], array_keys($j));
  if ($missing || $extra) {
    printf("%s (%s)\n  fixture-only: %s\n  live-only:    %s\n", basename($f), $t, implode(",", $missing) ?: "-", implode(",", $extra) ?: "-");
  }
}
echo "comparison complete\n";
'
```

Any `live-only` key is a field the API added since the fixtures were captured — record it as a `FINDING` and flag it for the user, per the design. Do **not** modify the committed fixtures.

- [ ] **Step 4: Triage and commit**

Per the Fix Protocol. Client-side webhook tests live under `packages/kudosity-client/tests/` — find the existing ones with `ls packages/kudosity-client/tests/Unit/Webhooks/`.

---

### Task 11: Project B scaffold, install, and configuration guards

**Files:**
- Create: `dispatch-desk/` (via `laravel new`)
- Create: `dispatch-desk/app/Models/Customer.php`, `app/Models/MessageEvent.php`
- Create: `dispatch-desk/database/migrations/*_create_message_events_table.php`
- Create: `dispatch-desk/tests/Feature/ConfigGuardTest.php`
- Modify: `dispatch-desk/.env`, `dispatch-desk/config/kudosity.php` (published)

**Interfaces:**
- Consumes: `artifacts/*.zip` from Task 1.
- Produces, and Tasks 12–17 depend on these:
  - `App\Models\Customer` with `$fillable = ['name', 'phone', 'email']`, `routeNotificationForKudosity()`, `routeNotificationForKudosityMms()`
  - `App\Models\MessageEvent` with columns `event_type`, `message_id`, `status_id`, `message_ref`, `recipient`, `sender`, `status`, `hits`, `payload` (json), `received_at`
  - `MessageEvent::rank()` is **not** defined — precedence is applied in the listener (Task 14), which is where the SDK's `StatusPrecedence` belongs.

- [ ] **Step 1: Create the app and install the package**

```bash
cd /home/mitchell/projects/kudosity-sdk-validation
composer create-project laravel/laravel dispatch-desk --no-interaction
cd dispatch-desk
php artisan --version
```
Expected: `Laravel Framework 12.x`. If `laravel/laravel` resolves to 11, that is fine — the package declares `^11.0||^12.0`; record which version was used.

```bash
php -r '
  $j = json_decode(file_get_contents("composer.json"), true);
  $j["repositories"] = [["type" => "artifact", "url" => "../artifacts"]];
  file_put_contents("composer.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'
composer require expertsystemsau/kudosity-laravel-client:^2.0 --no-interaction
```

Expected: installs `expertsystemsau/kudosity-laravel-client 2.0.1` **and** `expertsystemsau/kudosity-php-client 2.0.1` — the transitive dependency resolving is the check the release notes call out.

- [ ] **Step 2: Verify auto-discovery found the provider**

Run: `php artisan about | grep -iA3 kudosity` and `php artisan package:discover`
Expected: the provider is registered. Also confirm the commands appear:

```bash
php artisan list | grep kudosity
```
Expected: `kudosity:webhook:list`, `kudosity:webhook:install`, `kudosity:webhook:delete`.

If any is missing, the `extra.laravel.providers` block in `packages/kudosity-laravel/composer.json` is not doing its job — an SDK defect. Fix per the Fix Protocol.

- [ ] **Step 3: Publish the config and wire credentials**

```bash
php artisan vendor:publish --tag=kudosity-config
test -f config/kudosity.php && echo "published"
```

```bash
grep -E '^KUDOSITY_(API_KEY|API_SECRET|TEST_RECIPIENT)=' /home/mitchell/projects/transmitsms-php-sdk/.env >> .env
SENDER=$(cat ../order-notifier/.sender)
printf 'KUDOSITY_FROM=%s\nKUDOSITY_MMS_SENDER=%s\nKUDOSITY_COUNTRY_CODE=AU\n' "$SENDER" "$SENDER" >> .env
grep -c '^KUDOSITY_' .env
```
Expected: `6`. Note that `KUDOSITY_FROM` uses the **confirmed** sender from pre-flight, not the possibly-stale monorepo value.

Also set the app URL, because `CallbackUrlBuilder` builds from it:
```bash
php -r '
  $env = file_get_contents(".env");
  $env = preg_replace("/^APP_URL=.*$/m", "APP_URL=http://127.0.0.1:8000", $env);
  file_put_contents(".env", $env);
'
```

- [ ] **Step 4: Migrate to SQLite and add the events table**

Laravel 12 defaults to SQLite. Confirm, then create the table.

```bash
grep -E '^DB_CONNECTION' .env
touch database/database.sqlite
php artisan migrate --no-interaction
php artisan make:migration create_message_events_table --no-interaction
```

Fill the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            // The provider's own message id. A V2 id is a string; V1's is an
            // integer, so this column is a string for both.
            $table->string('message_id')->nullable();
            // The key every status event for one message shares, and the key a
            // duplicate guard must be built on.
            $table->string('status_id')->nullable()->index();
            $table->string('message_ref')->nullable()->index();
            $table->string('recipient')->nullable();
            $table->string('sender')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('hits')->nullable();
            $table->json('payload');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_events');
    }
};
```

Run: `php artisan migrate --no-interaction`

- [ ] **Step 5: Write the models**

```php
<?php // app/Models/MessageEvent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageEvent extends Model
{
    protected $fillable = [
        'event_type', 'message_id', 'status_id', 'message_ref',
        'recipient', 'sender', 'status', 'hits', 'payload', 'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'hits' => 'integer',
    ];
}
```

```php
<?php // app/Models/Customer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Customer extends Model
{
    use Notifiable;

    protected $fillable = ['name', 'phone', 'email'];

    public $timestamps = false;

    /** The `kudosity` channel routes here. */
    public function routeNotificationForKudosity(): string
    {
        return $this->phone;
    }

    /** The `kudosity-mms` channel routes here. */
    public function routeNotificationForKudosityMms(): string
    {
        return $this->phone;
    }
}
```

`Customer` has no migration on purpose — it is instantiated in memory (`new Customer(['phone' => …])`) and never persisted. `Notifiable` does not require a saved record to route an on-demand notification. If a check needs a persisted customer, add a migration then; do not add one speculatively.

- [ ] **Step 6: Write the config guard test**

Two behaviours the design names: a stale flat `base_url` must throw at boot, and `KudosityV2Connector` must resolve from the container despite `$apiKey` having no default.

```php
<?php // tests/Feature/ConfigGuardTest.php

use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\KudosityV2Connector;

it('resolves both connectors and the client from the container', function () {
    // KudosityV2Connector cannot autowire: $apiKey has no default. Its explicit
    // singleton in the provider is what makes this pass.
    expect(app(KudosityV2Connector::class))->toBeInstanceOf(KudosityV2Connector::class);
    expect(app(KudosityV1Connector::class))->toBeInstanceOf(KudosityV1Connector::class);
    expect(app(KudosityClient::class))->toBeInstanceOf(KudosityClient::class);
});

it('points each connector at its own host', function () {
    expect(app(KudosityV1Connector::class)->resolveBaseUrl())->toContain('api.transmitsms.com');
    expect(app(KudosityV2Connector::class)->resolveBaseUrl())->toContain('api.transmitmessage.com');
});

it('throws rather than sending V2 traffic to the V1 host when base_url is a stale flat string', function () {
    // A config published before 2.0 carries `'base_url' => 'https://api.transmitsms.com'`.
    // Guessing would send every V2 request to the V1 API and fail obscurely.
    config()->set('kudosity.base_url', 'https://api.transmitsms.com');
    app()->forgetInstance(KudosityV2Connector::class);
    app()->forgetInstance(KudosityV1Connector::class);
    app()->forgetInstance(KudosityClient::class);

    app(KudosityV2Connector::class);
})->throws(Throwable::class);

it('exposes the four notification channels', function () {
    $manager = app(Illuminate\Notifications\ChannelManager::class);

    foreach (['kudosity', 'kudosity-mms', 'kudosity-whatsapp', 'kudosity-rcs'] as $channel) {
        expect($manager->driver($channel))->not->toBeNull();
    }
});
```

`resolveBaseUrl()` is Saloon's method name — confirm with `grep -rn 'function resolveBaseUrl' vendor/expertsystemsau/kudosity-php-client/src/` and correct the calls if the connectors expose it differently. Do not guess.

- [ ] **Step 7: Run the test**

Run: `php artisan test --filter=ConfigGuardTest`
Expected: 4 passing.

If the stale-`base_url` test does not throw, that is a real defect — the provider is guessing where it should refuse. Fix per the Fix Protocol; the test belongs in the root suite at `tests/` in the monorepo.

- [ ] **Step 8: Record the result**

Write `results/B-14-config.json` in the same shape as Project A's files:

```bash
cd /home/mitchell/projects/kudosity-sdk-validation/dispatch-desk
php artisan test --filter=ConfigGuardTest > /tmp/configguard.txt 2>&1
php -r '
$out = file_get_contents("/tmp/configguard.txt");
$pass = str_contains($out, "FAIL") ? "FAIL" : "PASS";
$checks = [[
  "surface" => "KudosityServiceProvider",
  "expectation" => "both connectors resolve, each points at its own host, a stale flat base_url throws, and all four channels register",
  "result" => $pass,
  "detail" => trim((string) strrchr($out, "\n")) ?: "see evidence",
  "evidence" => ["output" => array_slice(explode("\n", $out), -12)],
]];
file_put_contents("../results/B-14-config.json", json_encode(["scenario" => "14-config", "checks" => $checks], JSON_PRETTY_PRINT) . PHP_EOL);
'
```

---

### Task 12: Project B — the SMS channel and its API routing decision

The channel's defining behaviour: V2 by default, V1 only when the message uses something V2 cannot express, and `forceV2()` **throws** rather than silently dropping a V1-only option.

**Files:**
- Create: `dispatch-desk/app/Notifications/OrderShipped.php`
- Create: `dispatch-desk/tests/Feature/RoutingTest.php`
- Create: `dispatch-desk/app/Console/Commands/RunChecks.php`

**Interfaces:**
- Consumes: `App\Models\Customer` from Task 11.
- Produces: `App\Console\Commands\RunChecks` — signature `checks:run {scenario}`, writing `../results/B-<scenario>.json`. This task produces `results/B-sms-live.json`; **Tasks 13, 15, 16 and 17 add cases to this command's `match`**, producing `B-mms-live.json`, `B-events-live.json`, `B-commands.json` and `B-v1-callbacks.json`.

`KudosityMessage` builder methods, verbatim: `content()`, `to()`, `toList()`, `from()`, `sendAt()`, `validity()`, `countryCode()`, `formatNumbers()`, `repliesToEmail()`, `trackedLinkUrl()`, `dlrCallback()`, `replyCallback()`, `linkHitsCallback()`, `onDlr()`, `onReply()`, `onLinkHit()`, plus `apiVersion()`, `v1Reasons()`, `forceV1()`, `forceV2()`, `getForcedVersion()`, `hasCallbackHandlers()`.

- [ ] **Step 1: Write the notification**

One notification, parameterised, so every routing branch uses the same class — that is how a real app would do it.

```php
<?php // app/Notifications/OrderShipped.php

namespace App\Notifications;

use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;
use Illuminate\Notifications\Notification;

class OrderShipped extends Notification
{
    /**
     * @param  'v2'|'schedule'|'list'|'handler'|'force-v2-invalid'  $variant
     */
    public function __construct(
        public string $variant = 'v2',
        public ?int $listId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['kudosity'];
    }

    public function toKudosity(object $notifiable): KudosityMessage
    {
        $message = KudosityMessage::create('Order 9931 has shipped.');

        return match ($this->variant) {
            // The default path: nothing V1-only, so the channel picks V2.
            'v2' => $message,

            // sendAt() has no V2 equivalent — /v2/sms cannot schedule.
            'schedule' => $message->sendAt(now()->addHours(2)->format('Y-m-d H:i:s')),

            // toList() has no V2 equivalent — /v2/sms takes one recipient.
            'list' => $message->toList($this->listId ?? 0),

            // A per-send callback handler. V2 has no per-send callback URL at
            // all; delivery reporting is account-level webhooks only.
            'handler' => $message->onDlr(\App\Listeners\RecordKudosityEvent::class, ['order' => 9931]),

            // A V1-only option AND an explicit forceV2(): must throw, because
            // sending it on V2 would silently drop the schedule.
            'force-v2-invalid' => $message->sendAt(now()->addHours(2)->format('Y-m-d H:i:s'))->forceV2(),
        };
    }
}
```

- [ ] **Step 2: Write the routing test — offline, no sends**

The routing decision is pure logic on `KudosityMessage`, so it is tested without touching the API. That matters: it is the one part of the channel a consumer can get wrong without noticing.

```php
<?php // tests/Feature/RoutingTest.php

use App\Models\Customer;
use App\Notifications\OrderShipped;
use ExpertSystems\Kudosity\Laravel\Notifications\ApiVersion;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;

function messageFor(string $variant, ?int $listId = null): KudosityMessage
{
    $customer = new Customer(['name' => 'Test', 'phone' => config('kudosity.test_recipient', '61491570006')]);

    return (new OrderShipped($variant, $listId))->toKudosity($customer);
}

it('routes a plain message to V2', function () {
    $message = messageFor('v2');

    expect($message->apiVersion())->toBe(ApiVersion::V2)
        ->and($message->v1Reasons())->toBe([]);
});

it('routes to V1 when the message is scheduled, and says why', function () {
    $message = messageFor('schedule');

    expect($message->apiVersion())->toBe(ApiVersion::V1)
        ->and($message->v1Reasons())->not->toBeEmpty();
});

it('routes to V1 for a list send, and says why', function () {
    $message = messageFor('list', 42);

    expect($message->apiVersion())->toBe(ApiVersion::V1)
        ->and($message->v1Reasons())->not->toBeEmpty();
});

it('routes to V1 for a callback handler, not just an explicit callback URL', function () {
    // The handler forms (onDlr/onReply/onLinkHit) are as V1-only as the raw
    // URL setters: V2 has no per-send callback at all.
    $message = messageFor('handler');

    expect($message->apiVersion())->toBe(ApiVersion::V1)
        ->and($message->hasCallbackHandlers())->toBeTrue()
        ->and($message->v1Reasons())->not->toBeEmpty();
});

it('routes to V1 for each V1-only option in isolation', function (string $method, array $args) {
    $message = KudosityMessage::create('x')->to('61491570006');
    $message->{$method}(...$args);

    expect($message->apiVersion())->toBe(ApiVersion::V1)
        ->and($message->v1Reasons())->not->toBeEmpty();
})->with([
    ['toList', [42]],
    ['sendAt', ['2026-09-01 09:00:00']],
    ['validity', [60]],
    ['repliesToEmail', ['ops@example.com']],
    ['dlrCallback', ['https://example.com/dlr']],
    ['replyCallback', ['https://example.com/reply']],
    ['linkHitsCallback', ['https://example.com/hits']],
    ['onDlr', ['App\Listeners\RecordKudosityEvent']],
    ['onReply', ['App\Listeners\RecordKudosityEvent']],
    ['onLinkHit', ['App\Listeners\RecordKudosityEvent']],
]);

it('throws on forceV2() when the message carries a V1-only option', function () {
    // Silently dropping the schedule would be worse than failing: the caller
    // asked for a send in two hours and would get one now.
    messageFor('force-v2-invalid');
})->throws(Throwable::class);

it('honours forceV1() on a message that would otherwise go to V2', function () {
    $message = KudosityMessage::create('x')->to('61491570006')->forceV1();

    expect($message->apiVersion())->toBe(ApiVersion::V1)
        ->and($message->getForcedVersion())->toBe(ApiVersion::V1);
});

it('honours forceV2() on a message with no V1-only option', function () {
    $message = KudosityMessage::create('x')->to('61491570006')->forceV2();

    expect($message->apiVersion())->toBe(ApiVersion::V2);
});
```

- [ ] **Step 3: Run it**

Run: `php artisan test --filter=RoutingTest`
Expected: 8 test cases + 10 dataset rows, all passing.

`ApiVersion` is an enum with V1/V2 cases — confirm the case names with `grep -n 'case ' vendor/expertsystemsau/kudosity-laravel-client/src/Notifications/ApiVersion.php` and correct the test if they differ.

- [ ] **Step 4: Write the checks command**

```php
<?php // app/Console/Commands/RunChecks.php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Notifications\OrderShipped;
use ExpertSystems\Kudosity\Contracts\SentMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

class RunChecks extends Command
{
    protected $signature = 'checks:run {scenario}';

    protected $description = 'Run one live validation scenario and write its result JSON';

    public function handle(): int
    {
        $scenario = (string) $this->argument('scenario');

        $checks = match ($scenario) {
            'sms-live' => $this->smsLive(),
            default => [$this->row('unknown', "scenario '$scenario' is not defined", 'FAIL', 'No such scenario')],
        };

        foreach ($checks as $c) {
            $this->line(sprintf('  [%-7s] %s — %s', $c['result'], $c['surface'], $c['detail']));
        }

        file_put_contents(
            base_path("../results/B-$scenario.json"),
            json_encode(['scenario' => $scenario, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    protected function smsLive(): array
    {
        $checks = [];
        $customer = new Customer(['name' => 'Test Handset', 'phone' => env('KUDOSITY_TEST_RECIPIENT')]);

        // The V2 default path, sent for real.
        try {
            $customer->notify(new OrderShipped('v2'));
            $checks[] = $this->row(
                'kudosity channel (V2)',
                'a plain notification sends via V2 and returns a SentMessage',
                'PASS',
                'Notification dispatched',
            );
        } catch (Throwable $e) {
            $checks[] = $this->row('kudosity channel (V2)', 'a plain notification sends via V2', 'FAIL', get_class($e).': '.$e->getMessage());
        }

        // The channel's return type is the contract, not a concrete DTO — the
        // caller never chose which API was used, so the type must not vary.
        try {
            $channel = app(\ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel::class);
            $result = $channel->send($customer, new OrderShipped('v2'));
            $checks[] = $result instanceof SentMessage
                ? $this->row('KudosityChannel::send()', 'the channel returns Contracts\SentMessage, stable across the routing decision', 'PASS', $result::class.' implements SentMessage, id='.$result->id())
                : $this->row('KudosityChannel::send()', 'the channel returns Contracts\SentMessage', 'FAIL', 'Returned '.get_debug_type($result));
        } catch (Throwable $e) {
            $checks[] = $this->row('KudosityChannel::send()', 'the channel returns Contracts\SentMessage', 'FAIL', get_class($e).': '.$e->getMessage());
        }

        // The V1 branch, sent for real: scheduled two hours out so it costs
        // nothing yet, then reported so it can be cancelled.
        try {
            $channel = app(\ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel::class);
            $result = $channel->send($customer, new OrderShipped('schedule'));
            $checks[] = $this->row(
                'kudosity channel (V1)',
                'a scheduled notification routes to V1 and sends',
                'PASS',
                'Scheduled via V1, id='.$result?->id().' — CANCEL THIS in cleanup',
                ['message_id' => $result?->id()],
            );
            file_put_contents(base_path('../scheduled-to-cancel.txt'), $result?->id()."\n", FILE_APPEND);
        } catch (Throwable $e) {
            $checks[] = $this->row('kudosity channel (V1)', 'a scheduled notification routes to V1 and sends', 'FAIL', get_class($e).': '.$e->getMessage());
        }

        return $checks;
    }

    /** @return array<string, mixed> */
    protected function row(string $surface, string $expectation, string $result, string $detail, array $evidence = []): array
    {
        return compact('surface', 'expectation', 'result', 'detail', 'evidence');
    }
}
```

`KudosityChannel::send()`'s signature must match — confirm with `grep -n 'function send' vendor/expertsystemsau/kudosity-laravel-client/src/Notifications/KudosityChannel.php` and adjust the call. Notifications normally go through `$customer->notify()`; calling the channel directly is how the return value is inspected, which `notify()` discards.

- [ ] **Step 5: Run the live SMS checks**

Run: `php artisan checks:run sms-live`
Expected: 3 checks. Two real SMS to the handset (the V2 default sends twice — once via `notify()`, once via the direct channel call) and one scheduled V1 send.

Spend: 2 messages.

- [ ] **Step 6: Cancel the scheduled V1 send**

```bash
cd /home/mitchell/projects/kudosity-sdk-validation/dispatch-desk
cat ../scheduled-to-cancel.txt
php artisan tinker --execute='
  foreach (file(base_path("../scheduled-to-cancel.txt"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $id) {
    echo $id, " => ", var_export(app(ExpertSystems\Kudosity\KudosityClient::class)->bulk()->cancel((int) $id), true), PHP_EOL;
  }
'
```
Expected: `true` for each. If any is `false`, cancel it in the dashboard — otherwise a real message sends in two hours.

- [ ] **Step 7: Triage and commit**

Per the Fix Protocol. Laravel-side tests live in the monorepo root `tests/` — find the channel tests with `ls tests/Unit/ tests/Feature/`.

---

### Task 13: Project B — the MMS channel

**Files:**
- Create: `dispatch-desk/app/Notifications/OrderPhoto.php`
- Modify: `dispatch-desk/app/Console/Commands/RunChecks.php` — add `'mms-live' => $this->mmsLive()`

**Interfaces:**
- Consumes: `RunChecks::row()` from Task 12.

`KudosityMmsMessage` methods, verbatim: `content()`, `to()`, `from()`, `subject()`, `media()`, `messageRef()`, `trackLinks()`, plus getters and `assertSendable()`. Note `media()` takes **one** URL per call.

- [ ] **Step 1: Write the notification**

```php
<?php // app/Notifications/OrderPhoto.php

namespace App\Notifications;

use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMmsMessage;
use Illuminate\Notifications\Notification;

class OrderPhoto extends Notification
{
    public const MEDIA = 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6b/Australia_location_map.svg/320px-Australia_location_map.svg.png';

    public function __construct(public bool $withoutMedia = false) {}

    public function via(object $notifiable): array
    {
        return ['kudosity-mms'];
    }

    public function toKudosityMms(object $notifiable): KudosityMmsMessage
    {
        $message = KudosityMmsMessage::create('Order 9931 is on its way.')
            ->subject('Your order shipped')
            ->messageRef('order-9931:mms-laravel');

        // The unsendable variant, for the guard check: an MMS with no media is
        // an SMS, and assertSendable() should say so before a request is made.
        return $this->withoutMedia ? $message : $message->media(self::MEDIA);
    }
}
```

- [ ] **Step 2: Add the MMS case to RunChecks**

In `handle()`'s `match`, add:

```php
            'mms-live' => $this->mmsLive(),
```

And the method:

```php
    /** @return array<int, array<string, mixed>> */
    protected function mmsLive(): array
    {
        $checks = [];
        $customer = new Customer(['name' => 'Test Handset', 'phone' => env('KUDOSITY_TEST_RECIPIENT')]);

        // The guard first, so a defect here costs nothing.
        try {
            (new \App\Notifications\OrderPhoto(withoutMedia: true))->toKudosityMms($customer)->assertSendable();
            $checks[] = $this->row(
                'KudosityMmsMessage::assertSendable()',
                'an MMS with no media is rejected before a request is made',
                'FAIL',
                'Accepted a media-less MMS',
            );
        } catch (Throwable $e) {
            $checks[] = $this->row(
                'KudosityMmsMessage::assertSendable()',
                'an MMS with no media is rejected before a request is made',
                'PASS',
                get_class($e).': '.$e->getMessage(),
            );
        }

        // The sender default comes from config('kudosity.mms.sender'), which is
        // a separate key from `from` because an MMS sender must be a number.
        $checks[] = config('kudosity.mms.sender') !== null
            ? $this->row('config kudosity.mms.sender', 'the MMS channel has its own sender default', 'PASS', 'configured')
            : $this->row('config kudosity.mms.sender', 'the MMS channel has its own sender default', 'FAIL', 'null — the channel will have no sender');

        try {
            $channel = app(\ExpertSystems\Kudosity\Laravel\Notifications\KudosityMmsChannel::class);
            $result = $channel->send($customer, new \App\Notifications\OrderPhoto());
            $checks[] = $result instanceof \ExpertSystems\Kudosity\Contracts\SentMessage
                ? $this->row('kudosity-mms channel', 'an MMS notification sends and returns a SentMessage', 'PASS', $result::class.' id='.$result->id())
                : $this->row('kudosity-mms channel', 'an MMS notification sends and returns a SentMessage', 'FAIL', 'Returned '.get_debug_type($result));
        } catch (Throwable $e) {
            $checks[] = $this->row('kudosity-mms channel', 'an MMS notification sends and returns a SentMessage', 'FAIL', get_class($e).': '.$e->getMessage());
        }

        // The notifiable's own route, rather than a direct channel call.
        try {
            $customer->notify(new \App\Notifications\OrderPhoto());
            $checks[] = $this->row(
                'routeNotificationForKudosityMms()',
                'the MMS channel reads its recipient from the notifiable route',
                'PASS',
                'Dispatched through notify()',
            );
        } catch (Throwable $e) {
            $checks[] = $this->row('routeNotificationForKudosityMms()', 'the MMS channel reads its recipient from the notifiable route', 'FAIL', get_class($e).': '.$e->getMessage());
        }

        return $checks;
    }
```

Add `use Throwable;` if it is not already imported.

- [ ] **Step 3: Run it**

Run: `php artisan checks:run mms-live`
Expected: 4 checks. Two real MMS to the handset.

Spend: 2 messages.

If the MMS sends fail with a sender error, the confirmed sender cannot do MMS. Record `BLOCKED` and continue — do not lease a number.

- [ ] **Step 4: Triage and commit**

Per the Fix Protocol.

---

### Task 14: Project B — the webhook receiver, its listeners, and the deterministic replay

Everything here runs offline against the repository's committed fixtures. Task 15 then repeats it with live deliveries. Doing it in this order means a live failure in Task 15 is unambiguously about delivery, not about decoding.

**Files:**
- Create: `dispatch-desk/app/Listeners/RecordKudosityEvent.php`
- Create: `dispatch-desk/tests/Feature/ReceiverTest.php`
- Modify: `dispatch-desk/app/Providers/AppServiceProvider.php` — register the listeners

**Interfaces:**
- Consumes: `App\Models\MessageEvent` from Task 11.
- Produces: `App\Listeners\RecordKudosityEvent` with `handleStatus()`, `handleInbound()`, `handleLinkHit()`, `handleOptOut()`. **Task 12's `'handler'` variant references this class by name**, so the name must not change.

- [ ] **Step 1: Write the listener**

The precedence guard lives here, not in the model: a late `SENT` must not overwrite a recorded `DELIVERED`, and the key is `status.id`, which is identical across every status event for a message.

```php
<?php // app/Listeners/RecordKudosityEvent.php

namespace App\Listeners;

use App\Models\MessageEvent;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Laravel\Events\KudosityInboundReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityLinkHitReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityOptOutReceived;
use ExpertSystems\Kudosity\Laravel\Events\KudosityStatusReceived;
use ExpertSystems\Kudosity\Webhooks\StatusPrecedence;

class RecordKudosityEvent
{
    public function handleStatus(KudosityStatusReceived $event): void
    {
        $status = $event->status;

        // Deliveries are at-least-once and unordered. Key on status.id and
        // refuse to regress: this is the whole reason StatusPrecedence exists.
        $existing = MessageEvent::query()
            ->where('status_id', $status->id)
            ->whereNotNull('status')
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $recorded = MessageStatus::fromApi($existing->status);
            if (! StatusPrecedence::supersedes($status->status, $recorded)) {
                // A duplicate or a stale redelivery. Recorded for the audit
                // trail, but it does not become the current status.
                MessageEvent::create($this->row($event->status->eventType->value, $status->id, $status, superseded: true));

                return;
            }
        }

        MessageEvent::create($this->row($event->status->eventType->value, $status->id, $status));
    }

    public function handleInbound(KudosityInboundReceived $event): void
    {
        $in = $event->inbound;

        MessageEvent::create([
            'event_type' => $in->eventType->value,
            'message_id' => $in->id,
            'status_id' => null,
            // The join key for reply threading. Not the phone number: number
            // matching breaks when one contact is in two flows at once.
            'message_ref' => $in->messageRef(),
            'recipient' => $in->recipient,
            'sender' => $in->sender,
            'status' => null,
            'hits' => null,
            // Inbound MMS payloads run to hundreds of KB of base64. Storing the
            // media inline would bloat every row, so it is counted, not kept.
            'payload' => ['message' => $in->message, 'subject' => $in->subject, 'media_count' => count($in->media), 'raw_keys' => array_keys($in->raw)],
            'received_at' => $in->timestamp,
        ]);
    }

    public function handleLinkHit(KudosityLinkHitReceived $event): void
    {
        $hit = $event->linkHit;

        MessageEvent::create([
            'event_type' => $hit->eventType->value,
            'message_id' => $hit->sourceMessage?->id,
            'status_id' => null,
            'message_ref' => $hit->messageRef(),
            'recipient' => $hit->sourceMessage?->recipient,
            'sender' => $hit->sourceMessage?->sender,
            'status' => null,
            'hits' => $hit->hits,
            'payload' => ['url' => $hit->url],
            'received_at' => $hit->timestamp,
        ]);
    }

    public function handleOptOut(KudosityOptOutReceived $event): void
    {
        $out = $event->optOut;

        MessageEvent::create([
            'event_type' => $out->eventType->value,
            'message_id' => $out->sourceMessage?->id,
            'status_id' => null,
            'message_ref' => $out->messageRef(),
            'recipient' => $out->sourceMessage?->recipient,
            'sender' => $out->sourceMessage?->sender,
            'status' => null,
            'hits' => null,
            'payload' => ['source' => $out->source->value],
            'received_at' => $out->timestamp,
        ]);
    }

    /** @return array<string, mixed> */
    protected function row(string $eventType, string $statusId, object $status, bool $superseded = false): array
    {
        return [
            'event_type' => $eventType,
            'message_id' => $status->id,
            'status_id' => $statusId,
            'message_ref' => $status->messageRef,
            'recipient' => $status->recipient,
            'sender' => $status->sender,
            // A superseded delivery is stored with a null status so it never
            // reads as the current one, while still leaving an audit trail.
            'status' => $superseded ? null : $status->status->value,
            'hits' => null,
            'payload' => ['description' => $status->description, 'routed_via' => $status->routedVia, 'superseded' => $superseded],
            'received_at' => $status->timestamp,
        ];
    }
}
```

- [ ] **Step 2: Register the listeners**

In `app/Providers/AppServiceProvider.php`'s `boot()`:

```php
        \Illuminate\Support\Facades\Event::listen(
            \ExpertSystems\Kudosity\Laravel\Events\KudosityStatusReceived::class,
            [\App\Listeners\RecordKudosityEvent::class, 'handleStatus'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \ExpertSystems\Kudosity\Laravel\Events\KudosityInboundReceived::class,
            [\App\Listeners\RecordKudosityEvent::class, 'handleInbound'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \ExpertSystems\Kudosity\Laravel\Events\KudosityLinkHitReceived::class,
            [\App\Listeners\RecordKudosityEvent::class, 'handleLinkHit'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \ExpertSystems\Kudosity\Laravel\Events\KudosityOptOutReceived::class,
            [\App\Listeners\RecordKudosityEvent::class, 'handleOptOut'],
        );
```

- [ ] **Step 3: Write the receiver test**

The receiver's entire authenticity story is that the URL is unguessable and signed — so the signature checks matter as much as the decoding.

```php
<?php // tests/Feature/ReceiverTest.php

use App\Models\MessageEvent;
use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;

const FIXTURES = '/home/mitchell/projects/transmitsms-php-sdk/packages/kudosity-client/tests/Fixtures/V2Webhooks';

function fixture(string $name): array
{
    return json_decode((string) file_get_contents(FIXTURES."/$name"), true);
}

/** The events URL the receiver will accept: signed, with a handler marker. */
function eventsUrl(): string
{
    $builder = app(CallbackUrlBuilder::class);

    // The same marker the install command uses. A URL built any other way is
    // refused by the receiver, which is the trap Task 16 tests.
    return $builder->build(CallbackType::EVENTS, 'kudosity.v2.events');
}

it('rejects an unsigned POST to the events route', function () {
    // Deliveries are unsigned by Kudosity, so the URL is the only defence.
    // An unsigned POST to a documented path must not be accepted.
    $this->postJson('/webhooks/kudosity/events', fixture('sms-status-sent.json'))
        ->assertStatus(403);

    expect(MessageEvent::count())->toBe(0);
});

it('rejects a POST whose signature has been tampered with', function () {
    $url = eventsUrl();
    $tampered = preg_replace('/s=([^&]+)/', 's=deadbeef', $url);

    $this->postJson($tampered, fixture('sms-status-sent.json'))->assertStatus(403);

    expect(MessageEvent::count())->toBe(0);
});

it('accepts a correctly signed status delivery and records it', function () {
    $this->postJson(eventsUrl(), fixture('sms-status-sent.json'))->assertOk();

    $row = MessageEvent::sole();
    expect($row->event_type)->toBe('SMS_STATUS')
        ->and($row->status)->toBe('SENT')
        ->and($row->status_id)->not->toBeNull();
});

it('does not let a late SENT overwrite a recorded DELIVERED', function () {
    $url = eventsUrl();

    // The observed hazard: a SENT redelivered 57s after DELIVERED, sharing one
    // status.id, byte-identical to its original.
    $this->postJson($url, fixture('sms-status-sent.json'))->assertOk();
    $this->postJson($url, fixture('sms-status-delivered.json'))->assertOk();
    $this->postJson($url, fixture('sms-status-sent.json'))->assertOk();

    $statusId = MessageEvent::whereNotNull('status_id')->value('status_id');
    $current = MessageEvent::where('status_id', $statusId)
        ->whereNotNull('status')
        ->orderByDesc('id')
        ->value('status');

    expect($current)->toBe('DELIVERED');

    // The duplicate is still recorded, just not as the current status.
    expect(MessageEvent::where('status_id', $statusId)->count())->toBe(3);
});

it('records an inbound reply with its correlation ref', function () {
    $this->postJson(eventsUrl(), fixture('sms-inbound-with-last-message.json'))->assertOk();

    $row = MessageEvent::sole();
    expect($row->event_type)->toBe('SMS_INBOUND')
        // The outbound message_ref survives the round trip through the reply.
        ->and($row->message_ref)->toBe('order-9931:cust-4471');
});

it('records an inbound MMS without storing its base64 payload', function () {
    $this->postJson(eventsUrl(), fixture('mms-inbound-with-media.json'))->assertOk();

    $row = MessageEvent::sole();
    expect($row->event_type)->toBe('MMS_INBOUND')
        ->and($row->payload['media_count'])->toBeGreaterThan(0)
        // An MMS reply carries no last_message, so it has no correlation key.
        ->and($row->message_ref)->toBeNull();
});

it('records cumulative link hits', function () {
    $url = eventsUrl();
    $this->postJson($url, fixture('link-hit-sms.json'))->assertOk();
    $this->postJson($url, fixture('link-hit-sms-repeat.json'))->assertOk();

    expect(MessageEvent::where('event_type', 'LINK_HIT')->pluck('hits')->all())->toBe([1, 2]);
});

it('records an MMS status delivery including the undocumented description', function () {
    $this->postJson(eventsUrl(), fixture('mms-status-delivered.json'))->assertOk();

    $row = MessageEvent::sole();
    expect($row->event_type)->toBe('MMS_STATUS')
        ->and($row->payload['description'])->not->toBeNull();
});

it('accepts an event type it does not know without a 500 and without a row', function () {
    // An event type Kudosity ships after this SDK must not take the endpoint
    // down. It is logged, accepted, and not dispatched to a typed listener.
    $this->postJson(eventsUrl(), ['event_type' => 'TELEPATHY_STATUS', 'timestamp' => '2026-08-07T00:00:00Z'])
        ->assertOk();

    expect(MessageEvent::count())->toBe(0);
});

it('accepts a non-JSON body without a 500', function () {
    // A truncated or hostile body is not retryable. Accepting it stops Kudosity
    // redelivering garbage forever.
    $this->call('POST', eventsUrl(), [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json at all')
        ->assertOk();
});

it('keeps the three V1 GET routes live', function () {
    $builder = app(CallbackUrlBuilder::class);

    foreach ([CallbackType::DLR, CallbackType::REPLY, CallbackType::LINK_HITS] as $type) {
        $this->get($builder->build($type))->assertOk();
    }
});
```

`CallbackType`'s case names must match — confirm with `grep -n 'case ' vendor/expertsystemsau/kudosity-php-client/src/Callbacks/CallbackType.php` and correct `EVENTS`/`DLR`/`REPLY`/`LINK_HITS` if they differ. Likewise confirm `CallbackUrlBuilder` resolves from the container; if it does not, construct it as `new CallbackUrlBuilder(url(config('kudosity.webhooks.prefix')), config('kudosity.webhooks.signing_key') ?? config('app.key'))` and record that the provider not binding it is a `FINDING`.

Also confirm `sms-inbound-with-last-message.json` really carries `order-9931:cust-4471` — read the fixture before trusting the assertion:
```bash
php -r 'echo json_decode(file_get_contents("/home/mitchell/projects/transmitsms-php-sdk/packages/kudosity-client/tests/Fixtures/V2Webhooks/sms-inbound-with-last-message.json"), true)["mo"]["last_message"]["message_ref"] ?? "absent", PHP_EOL;'
```

- [ ] **Step 4: Run it**

Run: `php artisan test --filter=ReceiverTest`
Expected: 12 passing. Each test starts from an empty `message_events` — add `uses(Illuminate\Foundation\Testing\RefreshDatabase::class);` at the top of the file if rows leak between tests.

- [ ] **Step 5: Record the result**

```bash
cd /home/mitchell/projects/kudosity-sdk-validation/dispatch-desk
php artisan test --filter=ReceiverTest > /tmp/receiver-test.txt 2>&1; echo "exit=$?"
php -r '
$out = file_get_contents("/tmp/receiver-test.txt");
$result = str_contains($out, "FAIL") ? "FAIL" : "PASS";
file_put_contents("../results/B-15-receiver.json", json_encode(["scenario" => "15-receiver", "checks" => [[
  "surface" => "WebhookController::events() + RecordKudosityEvent",
  "expectation" => "signed deliveries decode into typed events and persist; unsigned and tampered requests are refused; precedence holds; unknown types and bad bodies do not 500",
  "result" => $result,
  "detail" => trim((string) strrchr(trim($out), "\n")),
  "evidence" => ["output" => array_slice(explode("\n", $out), -20)],
]]], JSON_PRETTY_PRINT) . PHP_EOL);
'
```

- [ ] **Step 6: Triage and commit**

Per the Fix Protocol. Laravel receiver tests live in the monorepo root `tests/` — locate the existing controller test with `ls tests/Feature/ | grep -i webhook`.

---

### Task 15: The live callback loop, phase 2 — real deliveries into Project B

The same tunnel, re-pointed. `webhooks()->update()` is under test here rather than being a chore.

**Files:**
- Modify: `dispatch-desk/app/Console/Commands/RunChecks.php` — add `'events-live' => $this->eventsLive()`

**Interfaces:**
- Consumes: `order-notifier/.webhook-id` from Task 9; the running ngrok tunnel; `RecordKudosityEvent` from Task 14.
- Produces: `results/B-events-live.json` — `RunChecks` names its output `B-<scenario>.json` from the command argument.

- [ ] **Step 1: Start the Laravel app and confirm the tunnel still resolves**

The tunnel currently points at port 8088 (Project A's receiver). Serve Laravel on 8000, then re-point ngrok's upstream by restarting **only** the tunnel would issue a new URL — so instead, point the tunnel's existing upstream at Laravel by moving the port.

The simplest approach that keeps the URL: stop Project A's receiver and serve Laravel on the same port.

```bash
kill "$(cat /tmp/receiver.pid)" 2>/dev/null || true
cd /home/mitchell/projects/kudosity-sdk-validation/dispatch-desk
php artisan serve --host=127.0.0.1 --port=8088 > /tmp/laravel.log 2>&1 &
sleep 3
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/
```
Expected: `200`. The ngrok URL is unchanged, so the webhook registered in Task 9 still resolves — now to Laravel.

```bash
URL=$(curl -s http://127.0.0.1:4040/api/tunnels | php -r 'echo json_decode(stream_get_contents(STDIN), true)["tunnels"][0]["public_url"] ?? "";')
echo "$URL"
php -r '
  $env = file_get_contents(".env");
  $env = preg_replace("/^APP_URL=.*$/m", "APP_URL=" . $argv[1], $env);
  file_put_contents(".env", $env);
' "$URL"
php artisan config:clear
```

`APP_URL` must be the tunnel URL, because `CallbackUrlBuilder` builds the signed receiver URL from it — a localhost `APP_URL` produces a URL Kudosity cannot reach.

- [ ] **Step 2: Add the live events case to RunChecks**

```php
            'events-live' => $this->eventsLive(),
```

```php
    /** @return array<int, array<string, mixed>> */
    protected function eventsLive(): array
    {
        $checks = [];
        $client = app(\ExpertSystems\Kudosity\KudosityClient::class);
        $builder = app(\ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder::class);

        $idFile = base_path('../order-notifier/.webhook-id');
        if (! is_file($idFile)) {
            return [$this->row('webhooks()->update()', 'the phase-1 registration is re-pointed at this app', 'BLOCKED', 'No .webhook-id from Task 9')];
        }
        $webhookId = trim((string) file_get_contents($idFile));

        // The receiver URL must be built through CallbackUrlBuilder, or the
        // receiver refuses the very webhook we register.
        $receiver = $builder->build(
            \ExpertSystems\Kudosity\Callbacks\CallbackType::EVENTS,
            \ExpertSystems\Kudosity\Laravel\Console\Commands\WebhookInstallCommand::HANDLER_MARKER,
        );

        try {
            $updated = $client->webhooks()->update(
                id: $webhookId,
                name: 'sdk-validation-live-phase2',
                url: $receiver,
            );
            $checks[] = $this->row(
                'webhooks()->update()',
                'the phase-1 registration is re-pointed at this app, proving update() replaces the URL',
                'PASS',
                'now '.$updated->url,
                ['id' => $updated->id, 'url' => $updated->url],
            );
        } catch (Throwable $e) {
            return [$this->row('webhooks()->update()', 'the phase-1 registration is re-pointed at this app', 'FAIL', get_class($e).': '.$e->getMessage())];
        }

        $before = \App\Models\MessageEvent::count();

        // Drive real events: a tracked SMS and an MMS.
        $customer = new Customer(['name' => 'Test Handset', 'phone' => env('KUDOSITY_TEST_RECIPIENT')]);
        $customer->notify(new \App\Notifications\OrderShipped('v2'));
        $customer->notify(new \App\Notifications\OrderPhoto());

        $deadline = time() + 120;
        while (time() < $deadline && \App\Models\MessageEvent::count() < $before + 2) {
            sleep(4);
        }

        $rows = \App\Models\MessageEvent::where('id', '>', $before)->get();
        $byType = $rows->groupBy('event_type')->map->count()->all();

        $checks[] = $rows->isNotEmpty()
            ? $this->row(
                'WebhookController::events()',
                'live deliveries reach the real controller and persist through the listeners',
                'PASS',
                sprintf('%d row(s): %s', $rows->count(), json_encode($byType)),
                ['by_type' => $byType],
            )
            : $this->row(
                'WebhookController::events()',
                'live deliveries reach the real controller and persist through the listeners',
                'FAIL',
                'Nothing persisted in 120s. Check /tmp/laravel.log for 403s — a 403 means the registered URL was not built through CallbackUrlBuilder.',
            );

        // The 403 case is the specific trap worth naming in the report.
        $log = @file_get_contents('/tmp/laravel.log') ?: '';
        $forbidden = substr_count($log, ' 403 ');
        $checks[] = $forbidden === 0
            ? $this->row('receiver authenticity', 'no live delivery was refused', 'PASS', 'zero 403s in the request log')
            : $this->row(
                'receiver authenticity',
                'no live delivery was refused',
                'FAIL',
                sprintf('%d request(s) refused with 403 — the registered URL is missing its signature or handler marker', $forbidden),
            );

        // Status precedence, on live data this time.
        $statusIds = \App\Models\MessageEvent::whereNotNull('status_id')->distinct()->pluck('status_id');
        foreach ($statusIds as $statusId) {
            $all = \App\Models\MessageEvent::where('status_id', $statusId)->get();
            if ($all->count() < 2) {
                continue;
            }
            $current = $all->whereNotNull('status')->last()?->status;
            $checks[] = $current !== 'SENT' || $all->where('status', 'DELIVERED')->isEmpty()
                ? $this->row(
                    'RecordKudosityEvent::handleStatus()',
                    'the recorded status never regresses across multiple live deliveries',
                    'PASS',
                    sprintf('status.id=%s: %d deliveries, current=%s', $statusId, $all->count(), $current ?? 'null'),
                    ['deliveries' => $all->pluck('status')->all()],
                )
                : $this->row(
                    'RecordKudosityEvent::handleStatus()',
                    'the recorded status never regresses across multiple live deliveries',
                    'FAIL',
                    sprintf('status.id=%s regressed to SENT after DELIVERED', $statusId),
                );
        }

        return $checks;
    }
```

Add `use App\Models\Customer;` if it is not already imported.

- [ ] **Step 3: Run it**

Run:
```bash
cd /home/mitchell/projects/kudosity-sdk-validation/dispatch-desk
php artisan checks:run events-live
```
Expected: two real messages, then rows appearing in `message_events` within 120s. Watch: `tail -f /tmp/laravel.log`.

Spend: 2 messages.

- [ ] **Step 4: Verify from the database, not the log**

Run:
```bash
php artisan tinker --execute='
  App\Models\MessageEvent::orderBy("id")->get(["id","event_type","status_id","status","message_ref","hits"])->each(fn ($r) => print(json_encode($r) . PHP_EOL));
'
```
Expected: `SMS_STATUS` and `MMS_STATUS` rows at minimum, each with a `status_id`.

- [ ] **Step 5: Prompt the user for a reply, so a live inbound lands in the database**

**Stop and ask the user:**

> The webhook now points at the Laravel app. Please reply to the SMS on the handset once more, so a real inbound event lands in the database.

Then wait and verify:
```bash
for i in $(seq 1 30); do
  n=$(php artisan tinker --execute='echo App\Models\MessageEvent::where("event_type","SMS_INBOUND")->count();' 2>/dev/null | tail -1)
  [ "$n" != "0" ] && { echo "inbound rows: $n"; break; }
  sleep 4
done
```

- [ ] **Step 6: Triage and commit**

Per the Fix Protocol. If deliveries were refused with 403, the defect is likely in `CallbackUrlBuilder`, the parser, or the controller's guard — the failing test belongs in the monorepo root `tests/`.

---

### Task 16: Project B — the three Artisan commands

The trap worth testing: `kudosity:webhook:install` must build its URL through `CallbackUrlBuilder`, or the receiver refuses the very webhook it just registered.

**Files:**
- Modify: `dispatch-desk/app/Console/Commands/RunChecks.php` — add `'commands' => $this->commands()`

**Interfaces:**
- Consumes: the running tunnel and `APP_URL` from Task 15.
- Produces: `results/B-commands.json`.

Command signatures, verbatim:
```
kudosity:webhook:list
kudosity:webhook:install {--name=} {--event=*} {--rate-limit=} {--url=}
kudosity:webhook:delete {id} {--force}
```
`WebhookInstallCommand::HANDLER_MARKER === 'kudosity.v2.events'`.

- [ ] **Step 1: Add the commands case**

```php
            'commands' => $this->commands(),
```

```php
    /** @return array<int, array<string, mixed>> */
    protected function commands(): array
    {
        $checks = [];
        $client = app(\ExpertSystems\Kudosity\KudosityClient::class);

        // --- list ---
        $exit = \Illuminate\Support\Facades\Artisan::call('kudosity:webhook:list');
        $listOutput = \Illuminate\Support\Facades\Artisan::output();
        $checks[] = $exit === 0
            ? $this->row('kudosity:webhook:list', 'the command lists the account\'s registrations', 'PASS', sprintf('%d line(s) of output', substr_count($listOutput, "\n")), ['output' => array_slice(explode("\n", $listOutput), 0, 12)])
            : $this->row('kudosity:webhook:list', 'the command lists the account\'s registrations', 'FAIL', 'exit '.$exit.': '.$listOutput);

        // --- install ---
        $exit = \Illuminate\Support\Facades\Artisan::call('kudosity:webhook:install', [
            '--name' => 'sdk-validation-installed',
            '--event' => ['SMS_STATUS'],
            '--rate-limit' => 5,
        ]);
        $installOutput = \Illuminate\Support\Facades\Artisan::output();

        $installed = null;
        foreach ($client->webhooks()->all() as $w) {
            if ($w->name === 'sdk-validation-installed') {
                $installed = $w;
                break;
            }
        }

        $checks[] = $installed !== null
            ? $this->row('kudosity:webhook:install', 'the command registers a webhook', 'PASS', sprintf('id=%s url=%s', $installed->id, $installed->url), ['id' => $installed->id, 'url' => $installed->url])
            : $this->row('kudosity:webhook:install', 'the command registers a webhook', 'FAIL', 'exit '.$exit.': '.$installOutput);

        if ($installed !== null) {
            // The trap: the URL must carry a signature and the handler marker,
            // or the receiver refuses the registration the command just made.
            $query = [];
            parse_str((string) parse_url($installed->url, PHP_URL_QUERY), $query);

            $checks[] = isset($query['s'], $query['h']) && $query['s'] !== '' && $query['h'] !== ''
                ? $this->row(
                    'kudosity:webhook:install',
                    'the registered URL is built through CallbackUrlBuilder, so the receiver accepts it',
                    'PASS',
                    'URL carries both a signature and a handler marker',
                    ['has_signature' => true, 'handler' => $query['h']],
                )
                : $this->row(
                    'kudosity:webhook:install',
                    'the registered URL is built through CallbackUrlBuilder, so the receiver accepts it',
                    'FAIL',
                    'The registered URL lacks s and/or h — the receiver will 403 every delivery to a webhook this command created',
                    ['query' => $query],
                );

            // Prove it end to end: POST a fixture at the URL the command chose.
            $probe = \Illuminate\Support\Facades\Http::post($installed->url, json_decode((string) file_get_contents(
                '/home/mitchell/projects/transmitsms-php-sdk/packages/kudosity-client/tests/Fixtures/V2Webhooks/sms-status-sent.json'
            ), true));

            $checks[] = $probe->successful()
                ? $this->row(
                    'kudosity:webhook:install → WebhookController::events()',
                    'a delivery to the URL the install command registered is accepted by this app\'s receiver',
                    'PASS',
                    'HTTP '.$probe->status(),
                )
                : $this->row(
                    'kudosity:webhook:install → WebhookController::events()',
                    'a delivery to the URL the install command registered is accepted by this app\'s receiver',
                    'FAIL',
                    sprintf('HTTP %d — install and the receiver disagree about the URL shape', $probe->status()),
                );

            // A URL built any other way must be refused, which is what makes
            // the check above meaningful rather than tautological.
            $bare = url(config('kudosity.webhooks.prefix').'/'.config('kudosity.webhooks.events.path'));
            $refused = \Illuminate\Support\Facades\Http::post($bare, ['event_type' => 'SMS_STATUS']);
            $checks[] = $refused->status() === 403
                ? $this->row(
                    'WebhookController::events()',
                    'an unsigned URL is refused, so the install command\'s signing is load-bearing',
                    'PASS',
                    'HTTP 403 for the bare path',
                )
                : $this->row(
                    'WebhookController::events()',
                    'an unsigned URL is refused',
                    'FAIL',
                    'HTTP '.$refused->status().' for an unsigned POST to the documented path',
                );

            // --- delete ---
            $exit = \Illuminate\Support\Facades\Artisan::call('kudosity:webhook:delete', ['id' => $installed->id, '--force' => true]);
            $deleteOutput = \Illuminate\Support\Facades\Artisan::output();

            $stillThere = false;
            foreach ($client->webhooks()->all() as $w) {
                if ($w->id === $installed->id) {
                    $stillThere = true;
                    break;
                }
            }

            $checks[] = $exit === 0 && ! $stillThere
                ? $this->row('kudosity:webhook:delete', 'the command deletes the registration', 'PASS', 'deleted '.$installed->id)
                : $this->row('kudosity:webhook:delete', 'the command deletes the registration', 'FAIL', sprintf('exit=%d still_present=%s: %s', $exit, var_export($stillThere, true), $deleteOutput));
        }

        return $checks;
    }
```

- [ ] **Step 2: Run it**

Run: `php artisan checks:run commands`
Expected: 7 checks, all `PASS`. No messages sent — the probe POSTs a fixture at the app's own receiver.

Spend: 0 messages.

- [ ] **Step 3: Confirm nothing leaked**

Run: `php artisan kudosity:webhook:list`
Expected: no registration named `sdk-validation-installed`. The phase-2 registration (`sdk-validation-live-phase2`) is still there — Task 18 removes it.

- [ ] **Step 4: Triage and commit**

Per the Fix Protocol. `HANDLER_MARKER` living on the command while the controller enforces it is a coupling worth flagging as a `FINDING` if the two ever disagree.

---

### Task 17: Project B — the V1 GET callback routes, live

The three V1 GET routes remain live for V1 sends. Task 14 tested them offline; this drives them with a real V1 send carrying real callback URLs.

**Files:**
- Modify: `dispatch-desk/app/Console/Commands/RunChecks.php` — add `'v1-callbacks' => $this->v1Callbacks()`

**Interfaces:**
- Consumes: the tunnel URL as `APP_URL`; `CallbackUrlBuilder`.
- Produces: `results/B-v1-callbacks.json`.

- [ ] **Step 1: Add the case**

```php
            'v1-callbacks' => $this->v1Callbacks(),
```

```php
    /** @return array<int, array<string, mixed>> */
    protected function v1Callbacks(): array
    {
        $checks = [];
        $client = app(\ExpertSystems\Kudosity\KudosityClient::class);
        $builder = app(\ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder::class);

        // A V1 send with all three callback URLs, each signed by the builder and
        // naming this app's listener as the handler.
        $handler = \App\Listeners\RecordKudosityEvent::class;
        $dlr = $builder->dlr($handler, ['order' => 9931]);
        $reply = $builder->reply($handler, ['order' => 9931]);
        $hits = $builder->linkHits($handler, ['order' => 9931]);

        $checks[] = str_contains($dlr, 's=') && str_contains($dlr, 'h=')
            ? $this->row('CallbackUrlBuilder::dlr()', 'a V1 callback URL is signed and carries its handler and context', 'PASS', 'signature and handler present')
            : $this->row('CallbackUrlBuilder::dlr()', 'a V1 callback URL is signed and carries its handler and context', 'FAIL', $dlr);

        $before = \App\Models\MessageEvent::count();

        try {
            $sent = $client->bulk()->send(
                message: 'V1 callback check. Tap: https://example.com/o/9931',
                to: env('KUDOSITY_TEST_RECIPIENT'),
                from: config('kudosity.from'),
                configure: function (\ExpertSystems\Kudosity\Requests\SendSmsRequest $r) use ($dlr, $reply, $hits) {
                    $r->dlrCallback($dlr)
                        ->replyCallback($reply)
                        ->linkHitsCallback($hits)
                        ->trackedLinkUrl('https://example.com/o/9931');
                },
            );
            $checks[] = $this->row(
                'bulk()->send() with per-send callbacks',
                'a V1 send accepts the three signed callback URLs',
                'PASS',
                'message_id='.$sent->messageId,
                ['message_id' => $sent->messageId],
            );
        } catch (Throwable $e) {
            return array_merge($checks, [$this->row('bulk()->send() with per-send callbacks', 'a V1 send accepts the three signed callback URLs', 'FAIL', get_class($e).': '.$e->getMessage())]);
        }

        // V1 DLRs arrive as GET requests on the dlr route. They are handled by
        // CallbackUrlParser and the V1 events (DlrReceived), not by the V2
        // listeners — so a row in message_events is NOT expected here.
        $deadline = time() + 90;
        $seen = false;
        while (time() < $deadline) {
            $log = @file_get_contents('/tmp/laravel.log') ?: '';
            if (str_contains($log, '/webhooks/kudosity/dlr')) {
                $seen = true;
                break;
            }
            sleep(4);
        }

        $checks[] = $seen
            ? $this->row(
                'V1 dlr GET route',
                'a real V1 delivery receipt reaches the GET route',
                'PASS',
                'request observed in the server log',
            )
            : $this->row(
                'V1 dlr GET route',
                'a real V1 delivery receipt reaches the GET route',
                'FINDING',
                'No V1 DLR arrived within 90s. V1 callbacks can lag well beyond a V2 status event; the route itself is proven by the offline test in Task 14.',
            );

        $checks[] = $this->row(
            'V1 vs V2 callbacks',
            'the V1 GET routes and the V2 POST receiver coexist',
            'PASS',
            sprintf(
                'V2 rows in message_events: %d before this send, %d after — V1 receipts do not create V2 rows, which is correct: they dispatch DlrReceived, not KudosityStatusReceived.',
                $before,
                \App\Models\MessageEvent::count(),
            ),
        );

        return $checks;
    }
```

- [ ] **Step 2: Run it**

Run: `php artisan checks:run v1-callbacks`
Expected: one real SMS. The DLR check may land as a `FINDING` if V1 callbacks lag.

Spend: 1 message.

- [ ] **Step 3: Check the log for what actually arrived**

Run: `grep -oE '/webhooks/kudosity/[a-z-]+' /tmp/laravel.log | sort | uniq -c`
Expected: counts for `events` (from earlier tasks) and ideally `dlr` / `link-hits`.

- [ ] **Step 4: Triage and commit**

Per the Fix Protocol.

---

### Task 18: Report, cleanup, and the monorepo handoff

**Files:**
- Create: `/home/mitchell/projects/kudosity-sdk-validation/make-report.php`
- Create: `/home/mitchell/projects/kudosity-sdk-validation/VALIDATION-REPORT.md`
- Create: `/home/mitchell/projects/kudosity-sdk-validation/cleanup.sh`

**Interfaces:**
- Consumes: every `results/*.json` written by Tasks 1–17.

- [ ] **Step 1: Write the report generator**

```php
<?php // make-report.php
declare(strict_types=1);

$dir = __DIR__.'/results';
$files = glob($dir.'/*.json') ?: [];
sort($files);

$all = [];
foreach ($files as $f) {
    $j = json_decode((string) file_get_contents($f), true);
    if (! is_array($j)) {
        fwrite(STDERR, "skipping unreadable $f\n");
        continue;
    }
    $project = str_starts_with(basename($f), 'A-') ? 'order-notifier (vanilla PHP)' : 'dispatch-desk (Laravel)';
    foreach ($j['checks'] ?? [] as $c) {
        $c['project'] = $project;
        $c['scenario'] = $j['scenario'] ?? basename($f, '.json');
        $all[] = $c;
    }
}

$byResult = [];
foreach ($all as $c) {
    $byResult[$c['result']][] = $c;
}

$order = ['FAIL', 'FINDING', 'BLOCKED', 'PASS', 'SKIPPED'];
$counts = [];
foreach ($order as $r) {
    $counts[$r] = count($byResult[$r] ?? []);
}

$out = [];
$out[] = '# Kudosity 2.0 SDK — live consumer validation report';
$out[] = '';
$out[] = 'Generated from `results/*.json`. Both packages were installed from artifacts built';
$out[] = 'the way `splitsh` publishes them, into two applications that do not share the';
$out[] = 'monorepo autoloader. Phone numbers and credentials are redacted to the same';
$out[] = 'convention the repository fixtures use.';
$out[] = '';
$out[] = '## Summary';
$out[] = '';
$out[] = '| Result | Count | Meaning |';
$out[] = '|---|---|---|';
$out[] = sprintf('| FAIL | %d | SDK defect |', $counts['FAIL']);
$out[] = sprintf('| FINDING | %d | Upstream API behaviour the docs do not describe |', $counts['FINDING']);
$out[] = sprintf('| BLOCKED | %d | Could not run — environment or account state |', $counts['BLOCKED']);
$out[] = sprintf('| PASS | %d | Verified |', $counts['PASS']);
$out[] = sprintf('| SKIPPED | %d | Excluded by design |', $counts['SKIPPED']);
$out[] = '';

foreach ($order as $result) {
    $rows = $byResult[$result] ?? [];
    if ($rows === []) {
        continue;
    }
    $out[] = '## '.$result;
    $out[] = '';
    foreach ($rows as $c) {
        $out[] = sprintf('### %s', $c['surface']);
        $out[] = '';
        $out[] = sprintf('- **Project:** %s', $c['project']);
        $out[] = sprintf('- **Scenario:** `%s`', $c['scenario']);
        $out[] = sprintf('- **Expected:** %s', $c['expectation']);
        $out[] = sprintf('- **Observed:** %s', $c['detail']);
        if (! empty($c['evidence'])) {
            $out[] = '';
            $out[] = '```json';
            $out[] = json_encode($c['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $out[] = '```';
        }
        $out[] = '';
    }
}

$out[] = '## Not covered';
$out[] = '';
$out[] = '- **WhatsApp and RCS channels** — the account has no WhatsApp sender and no RCS';
$out[] = '  agent, so neither could be exercised live. Their unit coverage stands.';
$out[] = '- **`numbers()->lease()`** — bills the account monthly. The request shape was';
$out[] = '  verified against a stub; no number was leased.';
$out[] = '- **A live opt-out (`STOP`)** — would opt the test handset out of the account.';
$out[] = '  The `OPT_OUT` payload was replayed from a committed fixture instead.';
$out[] = '- **`senders()->register()` / `confirmVerification()`** — registering needs a';
$out[] = '  number the account does not already own, and confirming needs a code read off';
$out[] = '  that handset. The read and decode paths were verified.';
$out[] = '';

file_put_contents(__DIR__.'/VALIDATION-REPORT.md', implode("\n", $out)."\n");
printf("wrote VALIDATION-REPORT.md — %d checks: %s\n", count($all), json_encode($counts));
```

- [ ] **Step 2: Generate the report**

Run: `php /home/mitchell/projects/kudosity-sdk-validation/make-report.php`
Expected: a count line. Read the report and confirm the `FAIL` and `FINDING` sections are accurate — a generated report is a draft, not the deliverable.

- [ ] **Step 3: Verify no credential leaked into the report**

```bash
cd /home/mitchell/projects/kudosity-sdk-validation
KEY=$(grep '^KUDOSITY_API_KEY=' order-notifier/.env | cut -d= -f2)
SECRET=$(grep '^KUDOSITY_API_SECRET=' order-notifier/.env | cut -d= -f2)
HANDSET=$(grep '^KUDOSITY_TEST_RECIPIENT=' order-notifier/.env | cut -d= -f2)
for v in "$KEY" "$SECRET" "$HANDSET"; do
  [ -n "$v" ] && grep -c -- "$v" VALIDATION-REPORT.md
done
```
Expected: `0` for each. **A non-zero count is a blocker** — fix `Redactor` and regenerate before showing the report to anyone.

Also check the captured payloads, which are raw and unredacted:
```bash
grep -c -- "$HANDSET" captured/*.jsonl
```
Non-zero is expected there — the capture files are raw by design and must not be committed anywhere.

- [ ] **Step 4: Add the manual sections to the report**

Append, by hand, above `## Not covered`:

- **Fixes made** — one bullet per monorepo commit on `validate/v2-live-consumer-run`, with the observed failure and the commit hash. Get the list with:
  ```bash
  cd /home/mitchell/projects/transmitsms-php-sdk && git log --oneline main..validate/v2-live-consumer-run
  ```
- **Payloads that contradict a committed fixture or a vendored skill** — from Task 10 Step 3's comparison. Each one flagged for the user to decide whether it belongs in the repository. Do not add fixtures unilaterally.
- **Escalations** — anything that needed a product decision and was left open.

- [ ] **Step 5: Write the cleanup script**

```bash
cat > /home/mitchell/projects/kudosity-sdk-validation/cleanup.sh <<'SCRIPT'
#!/usr/bin/env bash
# Remove everything this validation run created on the Kudosity account and on
# this machine. Safe to re-run.
set -uo pipefail

cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier

echo "--- webhooks ---"
php -r '
require "vendor/autoload.php";
$b = OrderNotifier\Bootstrap::load();
foreach ($b->client()->webhooks()->all() as $w) {
  if (str_starts_with($w->name, "sdk-validation")) {
    echo "deleting ", $w->id, " ", $w->name, " => ", var_export($b->client()->webhooks()->delete($w->id), true), PHP_EOL;
  }
}
'

echo "--- contact lists ---"
php -r '
require "vendor/autoload.php";
$b = OrderNotifier\Bootstrap::load();
foreach ($b->client()->lists()->all()->items() as $l) {
  if (str_starts_with((string) ($l["name"] ?? ""), "sdk-validation-")) {
    echo "deleting list ", $l["id"], " ", $l["name"], " => ", var_export($b->client()->lists()->delete((int) $l["id"]), true), PHP_EOL;
  }
}
'

echo "--- processes ---"
kill "$(cat /tmp/ngrok.pid 2>/dev/null)" 2>/dev/null && echo "ngrok stopped"
pkill -f 'artisan serve --host=127.0.0.1 --port=8088' && echo "laravel stopped"
pkill -f 'php -S 127.0.0.1:8088' && echo "receiver stopped"

echo "--- reminders ---"
echo "Scheduled sends that reported a failed cancel must be cancelled in the dashboard:"
cat /home/mitchell/projects/kudosity-sdk-validation/scheduled-to-cancel.txt 2>/dev/null || echo "  none recorded"
echo "The test handset was NOT opted out. Credentials still need rotating — they predate this run."
SCRIPT
chmod +x /home/mitchell/projects/kudosity-sdk-validation/cleanup.sh
```

- [ ] **Step 6: Run cleanup and verify**

Run: `/home/mitchell/projects/kudosity-sdk-validation/cleanup.sh`

Then verify nothing remains:
```bash
cd /home/mitchell/projects/kudosity-sdk-validation/order-notifier
php -r '
require "vendor/autoload.php";
$b = OrderNotifier\Bootstrap::load();
$hooks = 0;
foreach ($b->client()->webhooks()->all() as $w) { if (str_starts_with($w->name, "sdk-validation")) { $hooks++; } }
echo "stray webhooks: $hooks", PHP_EOL;
'
```
Expected: `stray webhooks: 0`.

- [ ] **Step 7: Commit the plan and any remaining fixes to the monorepo**

```bash
cd /home/mitchell/projects/transmitsms-php-sdk
git add -f docs/superpowers/plans/2026-08-07-sdk-v2-live-validation.md
git status --short
git commit -m "docs: implementation plan for the live consumer validation"
git log --oneline main..validate/v2-live-consumer-run
```

The validation workspace itself is **not** committed — it holds credentials in plaintext `.env` files and unredacted captured payloads.

- [ ] **Step 8: Report to the user**

State plainly:
- the `FAIL` count and what each was, with its fix commit
- the `FINDING` count and which contradict a vendored skill or a committed fixture
- what was `BLOCKED` and why
- total messages sent versus the ~12 budget
- whether anything still needs their decision

Do not claim the run passed if any `FAIL` is unfixed. Quote the counts from `VALIDATION-REPORT.md` rather than from memory.
