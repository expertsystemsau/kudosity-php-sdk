<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Contracts\WebhookFingerprintStore;
use ExpertSystems\Kudosity\Enums\EnsureAction;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use ExpertSystems\Kudosity\Webhooks\FileFingerprintStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

#[CoversClass(FileFingerprintStore::class)]
#[CoversClass(WebhooksResource::class)]
final class V2WebhookFingerprintTest extends TestCase
{
    private const URL = 'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=sig';

    private string $path;

    protected function setUp(): void
    {
        // Deterministic per test method, so a leftover file from a previous run
        // cannot make a test pass for the wrong reason.
        $this->path = sys_get_temp_dir().'/kudosity-fingerprint-'.$this->name().'.json';
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @return array<string, mixed> */
    private static function hookBody(): array
    {
        return [
            'id' => 'wh_1',
            'name' => 'Prod events',
            'url' => self::URL,
            'filter' => ['event_type' => ['SMS_STATUS']],
            'rate_limit' => 0,
            'is_sandbox' => false,
            'created_at' => '2026-08-10T09:00:00.000000Z',
            'updated_at' => '2026-08-10T09:00:00.000000Z',
        ];
    }

    private function resource(MockClient $mock): WebhooksResource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        return new WebhooksResource($connector);
    }

    public function test_a_file_store_round_trips_a_fingerprint(): void
    {
        $store = new FileFingerprintStore($this->path);

        $this->assertNull($store->get('https://app.example.com/webhooks/kudosity/events'));

        $store->put('https://app.example.com/webhooks/kudosity/events', 'abc123');

        $this->assertSame('abc123', $store->get('https://app.example.com/webhooks/kudosity/events'));
    }

    public function test_a_file_store_keeps_entries_for_different_identities_apart(): void
    {
        $store = new FileFingerprintStore($this->path);

        $store->put('https://a.example.com/h', 'fp-a');
        $store->put('https://b.example.com/h', 'fp-b');

        $this->assertSame('fp-a', $store->get('https://a.example.com/h'));
        $this->assertSame('fp-b', $store->get('https://b.example.com/h'));
    }

    public function test_a_corrupt_store_file_reads_as_empty_rather_than_throwing(): void
    {
        // A half-written file must degrade to one extra GET, not to a crash on boot.
        file_put_contents($this->path, '{not json');

        $this->assertNull((new FileFingerprintStore($this->path))->get('https://a.example.com/h'));
    }

    public function test_an_unwritable_path_throws_rather_than_silently_losing_the_optimisation(): void
    {
        // Loud, because a silent failure turns a once-per-deploy GET into a
        // per-call GET that nobody notices. The registration is already correct by
        // the time this fires, and re-running is idempotent, so failing costs
        // nothing but the operator's attention.
        $store = new FileFingerprintStore('/proc/definitely-not-writable/fingerprints.json');

        $this->expectException(KudosityException::class);

        $store->put('https://a.example.com/h', 'abc');
    }

    public function test_the_second_ensure_makes_no_http_request_at_all(): void
    {
        // The point of the store. An empty MockClient means ANY request throws, so
        // the assertion is that nothing was attempted.
        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
        ]);

        $store = new FileFingerprintStore($this->path);

        $first = $this->resource($mock)->ensure(
            'Prod events',
            self::URL,
            [WebhookEventType::SmsStatus],
            store: $store,
        );

        $this->assertSame(EnsureAction::Unchanged, $first->action);

        $second = $this->resource(new MockClient([]))->ensure(
            'Prod events',
            self::URL,
            [WebhookEventType::SmsStatus],
            store: $store,
        );

        $this->assertSame(EnsureAction::Skipped, $second->action);
        // Skipped is the only action with no DTO: nothing was read, so there is
        // nothing to return.
        $this->assertNull($second->webhook);
    }

    public function test_a_changed_url_re_fires_even_though_a_fingerprint_exists(): void
    {
        // A rotated signing key changes the URL but not the identity, so the stored
        // fingerprint is present and must not be trusted.
        $store = new FileFingerprintStore($this->path);

        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
        ]);

        $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store);

        $rotated = 'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=ROTATED';

        $repaired = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
            UpdateWebhookRequest::class => MockResponse::make(
                ['id' => 'wh_1'] + self::hookBody(),
                200,
            ),
        ]);

        $result = $this->resource($repaired)->ensure(
            'Prod events',
            $rotated,
            [WebhookEventType::SmsStatus],
            store: $store,
        );

        $this->assertSame(EnsureAction::Updated, $result->action);

        // The update path writes a fingerprint too, and this is what proves it:
        // a third call with an empty MockClient issues no request at all, so it
        // can only return Skipped if the update-path write actually happened.
        // Without this, deleting that write leaves the suite green.
        $skipped = $this->resource(new MockClient([]))->ensure(
            'Prod events',
            $rotated,
            [WebhookEventType::SmsStatus],
            store: $store,
        );

        $this->assertSame(EnsureAction::Skipped, $skipped->action);
        $this->assertNull($skipped->webhook);
    }

    public function test_a_changed_event_set_re_fires(): void
    {
        $store = new FileFingerprintStore($this->path);

        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
        ]);

        $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store);

        $repaired = new MockClient([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::hookBody()]], 200),
            UpdateWebhookRequest::class => MockResponse::make(self::hookBody(), 200),
        ]);

        $result = $this->resource($repaired)->ensure(
            'Prod events',
            self::URL,
            [WebhookEventType::SmsStatus, WebhookEventType::LinkHit],
            store: $store,
        );

        $this->assertSame(EnsureAction::Updated, $result->action);
    }

    public function test_a_store_is_written_after_a_create_so_the_next_call_skips(): void
    {
        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make([], 200),
            CreateWebhookRequest::class => MockResponse::make(self::hookBody(), 201),
        ]);

        $store = new FileFingerprintStore($this->path);

        $this->assertSame(
            EnsureAction::Created,
            $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store)->action,
        );

        $this->assertSame(
            EnsureAction::Skipped,
            $this->resource(new MockClient([]))->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store)->action,
        );
    }

    public function test_a_custom_store_implementation_is_enough_to_participate(): void
    {
        // The interface is two methods precisely so wrapping a PSR-16 cache, or
        // anything else, needs no adapter shipped here.
        $store = new class implements WebhookFingerprintStore
        {
            /** @var array<string, string> */
            public array $entries = [];

            public function get(string $key): ?string
            {
                return $this->entries[$key] ?? null;
            }

            public function put(string $key, string $fingerprint): void
            {
                $this->entries[$key] = $fingerprint;
            }
        };

        $mock = new MockClient([
            ListWebhooksRequest::class => MockResponse::make([], 200),
            CreateWebhookRequest::class => MockResponse::make(self::hookBody(), 201),
        ]);

        $this->resource($mock)->ensure('Prod events', self::URL, [WebhookEventType::SmsStatus], store: $store);

        $this->assertArrayHasKey('https://app.example.com/webhooks/kudosity/events', $store->entries);
    }
}
