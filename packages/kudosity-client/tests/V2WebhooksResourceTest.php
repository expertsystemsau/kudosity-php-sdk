<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Pages;
use ExpertSystems\Kudosity\Data\V2\WebhookData;
use ExpertSystems\Kudosity\Data\V2\WebhookFilter;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\CreateWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\DeleteWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\GetWebhookRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWebhooksRequest;
use ExpertSystems\Kudosity\Requests\V2\UpdateWebhookRequest;
use ExpertSystems\Kudosity\Resources\WebhooksResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/V2WebhooksResourceTest.php.
 *
 * Resource-level CRUD for WebhooksResource, plus WebhookData and
 * WebhookFilter. RequestShapeTest pins this family's endpoints, the HTTPS
 * guard and the PUT-is-a-replace body only.
 *
 * Two RequestShapeTest tests fold: `test_a_webhook_url_must_be_https_unless_explicitly_opted_out`
 * asserted only the exception class for one URL; this file's own
 * `test_rejects_a_non_https_webhook_url_which_is_stricter_than_the_platform`
 * below is strictly stronger — the same scenario is one of its four dataset
 * cases, plus a message assertion RequestShapeTest never made — so the
 * dominated original came out. `test_a_phone_number_in_a_path_segment_is_url_encoded`
 * is unrelated to webhooks (it drives `DeleteSenderPhoneNumberRequest`) and
 * folds in the senders port instead.
 *
 * `test_the_insecure_url_opt_in_is_explicit_and_works` and
 * `test_a_webhook_update_sends_the_whole_shape_because_put_is_a_replace` stay
 * in RequestShapeTest: the first drives the constructor via positional
 * arguments and checks `resolveEndpoint()`, where this file's equivalent
 * uses a named argument and checks only that construction did not throw —
 * different construction paths that could diverge if the parameter order
 * ever changed without a matching named-argument update. The second only
 * checks the request object's own body for key *presence*; this file's
 * `test_sends_name_and_url_in_full_on_an_update_because_put_replaces` checks
 * the full body sent through the resource, method included, via a mock —
 * a different assertion target, so both stay.
 */
#[CoversClass(WebhooksResource::class)]
#[CoversClass(CreateWebhookRequest::class)]
#[CoversClass(UpdateWebhookRequest::class)]
#[CoversClass(GetWebhookRequest::class)]
#[CoversClass(DeleteWebhookRequest::class)]
#[CoversClass(ListWebhooksRequest::class)]
#[CoversClass(WebhookData::class)]
#[CoversClass(WebhookFilter::class)]
final class V2WebhooksResourceTest extends TestCase
{
    /**
     * The webhook resource shape, verbatim from a live `POST /v2/webhook` response.
     *
     * Note the nine-fractional-digit timestamps; a read response returns six.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function webhookBody(array $overrides = []): array
    {
        return array_merge([
            'id' => '8ab7060c-6c74-482b-baf6-8e7ef36cdf63',
            'name' => 'SDK 2.0 link-hit capture',
            'url' => 'https://example.com/webhooks/kudosity',
            'filter' => ['event_type' => ['LINK_HIT', 'SMS_STATUS']],
            'rate_limit' => 0,
            'is_sandbox' => false,
            'created_at' => '2026-08-05T15:23:11.730743151Z',
            'updated_at' => '2026-08-05T15:23:11.730743151Z',
        ], $overrides);
    }

    /** @param array<class-string, MockResponse> $responses */
    private static function webhooksResource(array $responses): WebhooksResource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return new WebhooksResource($connector);
    }

    // ---------------------------------------------------------------------------
    // create
    // ---------------------------------------------------------------------------

    public function test_creates_a_webhook_and_returns_a_typed_dto_built_from_the_201_body(): void
    {
        // Asserted on the DTO's contents rather than the status code: Saloon treats
        // any 2xx as success, so "it accepts a 201" passes trivially and proves
        // nothing. What matters is that the 201's body was parsed.
        $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(self::webhookBody(), 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $hook = (new WebhooksResource($connector))->create(
            'SDK 2.0 link-hit capture',
            'https://example.com/webhooks/kudosity',
            [WebhookEventType::LinkHit, WebhookEventType::SmsStatus],
        );

        $this->assertInstanceOf(WebhookData::class, $hook);
        $this->assertSame('8ab7060c-6c74-482b-baf6-8e7ef36cdf63', $hook->id);
        $this->assertSame('SDK 2.0 link-hit capture', $hook->name);
        $this->assertSame(['LINK_HIT', 'SMS_STATUS'], $hook->filter->eventType);
        $this->assertSame('POST', $mock->getLastPendingRequest()?->getMethod()->value);
    }

    public function test_sends_event_types_under_filter_event_type_and_never_as_a_top_level_event_type(): void
    {
        // The top-level field is deprecated upstream and silently ignored, so sending
        // it LOOKS like it worked — the registration is created, just not filtered.
        // Its absence is the assertion.
        $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(self::webhookBody(), 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->create('Events', 'https://e.com/h', [WebhookEventType::SmsStatus]);

        $body = $mock->getLastPendingRequest()?->body()?->all();

        $this->assertArrayHasKey('filter', (array) $body);
        $this->assertSame(['event_type' => ['SMS_STATUS']], $body['filter']);
        $this->assertArrayNotHasKey('event_type', (array) $body);
    }

    public function test_accepts_enum_cases_and_raw_strings_in_the_same_filter(): void
    {
        // Enums are the point of having them, but a filter read back from the API is
        // strings — refusing those would make round-tripping needlessly awkward.
        $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(self::webhookBody(), 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->create('Events', 'https://e.com/h', [
            WebhookEventType::SmsStatus,
            'MMS_STATUS',
        ]);

        $this->assertSame(
            ['event_type' => ['SMS_STATUS', 'MMS_STATUS']],
            $mock->getLastPendingRequest()?->body()?->all()['filter'],
        );
    }

    public function test_omits_the_filter_entirely_when_no_event_types_or_filters_are_given(): void
    {
        // An absent filter means every event type. Sending "filter": {} is a
        // different request, and "match nothing" is not what a caller who left the
        // parameter alone meant.
        $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(self::webhookBody(), 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->create('Everything', 'https://e.com/h');

        $this->assertSame([
            'name' => 'Everything',
            'url' => 'https://e.com/h',
        ], $mock->getLastPendingRequest()?->body()?->all());
    }

    public function test_sends_a_full_filter_with_statuses_senders_and_refs_dropping_the_empty_parts(): void
    {
        $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(self::webhookBody(), 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->create('Failures', 'https://e.com/h', filter: new WebhookFilter(
            eventType: [WebhookEventType::SmsStatus],
            status: [MessageStatus::Failed, MessageStatus::HardBounce],
            sender: ['61481074185'],
        ));

        $this->assertSame([
            'event_type' => ['SMS_STATUS'],
            'sender' => ['61481074185'],
            'status' => ['FAILED', 'HARD_BOUNCE'],
        ], $mock->getLastPendingRequest()?->body()?->all()['filter']);
    }

    public function test_lets_an_explicit_event_type_list_win_over_one_already_in_the_filter(): void
    {
        $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(self::webhookBody(), 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->create(
            'Events',
            'https://e.com/h',
            [WebhookEventType::OptOut],
            new WebhookFilter(eventType: [WebhookEventType::SmsStatus], sender: ['61481074185']),
        );

        $filter = $mock->getLastPendingRequest()?->body()?->all()['filter'];

        // The shortcut replaces event_type but must not discard the rest of the filter.
        $this->assertSame(['OPT_OUT'], $filter['event_type']);
        $this->assertSame(['61481074185'], $filter['sender']);
    }

    public function test_sends_rate_limit_only_when_given_since_0_means_the_system_default(): void
    {
        $mock = new MockClient([CreateWebhookRequest::class => MockResponse::make(self::webhookBody(), 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);
        $resource = new WebhooksResource($connector);

        $resource->create('Rate limited', 'https://e.com/h');
        $this->assertArrayNotHasKey('rate_limit', (array) $mock->getLastPendingRequest()?->body()?->all());

        $resource->create('Rate limited', 'https://e.com/h', rateLimit: 250);
        $this->assertSame(250, $mock->getLastPendingRequest()?->body()?->all()['rate_limit']);
    }

    // ---------------------------------------------------------------------------
    // Client-side guards
    // ---------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function nonHttpsUrls(): array
    {
        return [
            'plain http' => ['http://example.com/hook'],
            'uppercase scheme' => ['HTTP://example.com/hook'],
            'no scheme' => ['example.com/hook'],
            'ftp' => ['ftp://example.com/hook'],
        ];
    }

    #[DataProvider('nonHttpsUrls')]
    public function test_rejects_a_non_https_webhook_url_which_is_stricter_than_the_platform(string $url): void
    {
        // The docs require HTTPS; a live probe registering http://example.com/x
        // returned 201, so the API does NOT enforce it. Rejected here anyway:
        // deliveries carry message text and phone numbers, and they are unsigned, so
        // a plaintext endpoint is readable and forgeable by anyone on the path.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must use HTTPS');

        new CreateWebhookRequest('Events', $url);
    }

    public function test_accepts_a_plaintext_url_only_when_insecure_is_opted_into_explicitly(): void
    {
        // The escape hatch for local development. Opt-in, never sniffed from the URL:
        // this class cannot tell a laptop from production, so the caller decides.
        $this->assertInstanceOf(
            CreateWebhookRequest::class,
            new CreateWebhookRequest('Local', 'http://kudosity.test/hook', allowInsecureUrl: true),
        );
    }

    public function test_still_refuses_a_non_http_scheme_even_with_insecure_opted_in(): void
    {
        // The opt-in relaxes http:// specifically, not "any URL at all".
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must use HTTPS');

        new CreateWebhookRequest('Local', 'ftp://kudosity.test/hook', allowInsecureUrl: true);
    }

    public function test_accepts_an_uppercase_https_scheme(): void
    {
        // The scheme check is case-insensitive in both directions, or a legitimate
        // URL gets rejected for its capitalisation.
        $this->assertInstanceOf(
            CreateWebhookRequest::class,
            new CreateWebhookRequest('Events', 'HTTPS://example.com/hook'),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function outOfBoundsNames(): array
    {
        return [
            'one character' => ['x'],
            'empty' => [''],
            '101 characters' => [str_repeat('a', 101)],
        ];
    }

    #[DataProvider('outOfBoundsNames')]
    public function test_rejects_a_name_outside_the_documented_length_naming_the_bounds(string $name): void
    {
        // Each rule gets its own single-violation input: these URLs are valid HTTPS,
        // so only the name rule can fire.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be between 2 and 100');

        new CreateWebhookRequest($name, 'https://example.com/hook');
    }

    /** @return array<string, array{0: string}> */
    public static function boundaryNames(): array
    {
        return [
            'two characters' => ['ab'],
            '100 characters' => [str_repeat('a', 100)],
        ];
    }

    #[DataProvider('boundaryNames')]
    public function test_accepts_a_name_at_both_documented_bounds(string $name): void
    {
        $this->assertInstanceOf(CreateWebhookRequest::class, new CreateWebhookRequest($name, 'https://example.com/hook'));
    }

    /** @return array<string, array{0: int}> */
    public static function outOfRangeRateLimits(): array
    {
        return [
            'negative' => [-1],
            'above the ceiling' => [10_001],
        ];
    }

    #[DataProvider('outOfRangeRateLimits')]
    public function test_rejects_a_rate_limit_outside_the_documented_range(int $limit): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be between 0 and 10000');

        new CreateWebhookRequest('Events', 'https://example.com/hook', rateLimit: $limit);
    }

    /** @return array<string, array{0: int}> */
    public static function boundaryRateLimits(): array
    {
        return [
            'system default' => [0],
            'ceiling' => [10_000],
        ];
    }

    #[DataProvider('boundaryRateLimits')]
    public function test_accepts_0_as_the_system_default_and_the_documented_ceiling(int $limit): void
    {
        $this->assertInstanceOf(
            CreateWebhookRequest::class,
            new CreateWebhookRequest('Events', 'https://example.com/hook', rateLimit: $limit),
        );
    }

    public function test_applies_the_same_guards_to_an_update_as_to_a_create(): void
    {
        // Shared guards rather than a second copy, so the two cannot drift into
        // accepting different things.
        try {
            new UpdateWebhookRequest('id-1', 'Events', 'http://example.com/hook');
            $this->fail('Expected a ValidationException for a non-HTTPS URL.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must use HTTPS', $e->getMessage());
        }

        try {
            new UpdateWebhookRequest('id-1', 'x', 'https://example.com/hook');
            $this->fail('Expected a ValidationException for an out-of-bounds name.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be between 2 and 100', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------------
    // all()
    // ---------------------------------------------------------------------------

    public function test_reads_the_list_out_of_the_named_webhooks_key(): void
    {
        $hooks = self::webhooksResource([
            ListWebhooksRequest::class => MockResponse::make([
                // A decoy top-level id: a wrong read resolves this rather than null,
                // which is a failure that looks like success.
                'id' => 'decoy-not-a-webhook',
                'webhooks' => [self::webhookBody(), self::webhookBody(['id' => 'second', 'name' => 'Another'])],
            ], 200),
        ])->all();

        $this->assertCount(2, $hooks);
        $this->assertInstanceOf(WebhookData::class, $hooks[0]);
        $this->assertSame('8ab7060c-6c74-482b-baf6-8e7ef36cdf63', $hooks[0]->id);
        $this->assertSame('second', $hooks[1]->id);
    }

    public function test_returns_an_empty_array_when_the_account_has_no_webhooks_and_the_key_is_omitted(): void
    {
        // The live API answers `{}` — not `{"webhooks": []}` — when there are none.
        // Confirmed twice. A test using the empty-array form would not cover this.
        $this->assertSame(
            [],
            self::webhooksResource([ListWebhooksRequest::class => MockResponse::make([], 200)])->all(),
        );
    }

    public function test_returns_an_empty_array_for_an_explicitly_empty_collection_too(): void
    {
        $this->assertSame(
            [],
            self::webhooksResource([ListWebhooksRequest::class => MockResponse::make(['webhooks' => []], 200)])->all(),
        );
    }

    public function test_skips_a_malformed_entry_rather_than_failing_the_whole_list(): void
    {
        $hooks = self::webhooksResource([
            ListWebhooksRequest::class => MockResponse::make(['webhooks' => [self::webhookBody(), 'not-an-object', null]], 200),
        ])->all();

        $this->assertCount(1, $hooks);
        $this->assertSame('8ab7060c-6c74-482b-baf6-8e7ef36cdf63', $hooks[0]->id);
    }

    public function test_tolerates_a_webhooks_key_that_is_not_a_list_at_all(): void
    {
        $this->assertSame(
            [],
            self::webhooksResource([ListWebhooksRequest::class => MockResponse::make(['webhooks' => 'nope'], 200)])->all(),
        );
    }

    public function test_declares_no_pagination_because_neither_the_docs_nor_any_live_response_has_any(): void
    {
        // Asserted rather than assumed: a paginator contract here would mean guessing
        // a scheme, and Phase 3 already removed one speculative feature for that.
        $this->assertNotInstanceOf(PaginatesV2Pages::class, new ListWebhooksRequest);
        $this->assertNotInstanceOf(PaginatesV2Cursor::class, new ListWebhooksRequest);
    }

    // ---------------------------------------------------------------------------
    // get()
    // ---------------------------------------------------------------------------

    public function test_reads_one_registration_by_id_an_endpoint_the_skill_does_not_document(): void
    {
        // Confirmed by probing the live API: GET /v2/webhook/{id} answers 200 with
        // the same flat shape as the create response.
        $hook = self::webhooksResource([GetWebhookRequest::class => MockResponse::make(self::webhookBody(), 200)])
            ->get('8ab7060c-6c74-482b-baf6-8e7ef36cdf63');

        $this->assertSame('8ab7060c-6c74-482b-baf6-8e7ef36cdf63', $hook->id);
        $this->assertSame('https://example.com/webhooks/kudosity', $hook->url);
    }

    public function test_sends_no_body_on_the_get_reader(): void
    {
        // A body on a GET — even [] — is stripped or rejected by some gateways.
        $mock = new MockClient([GetWebhookRequest::class => MockResponse::make(self::webhookBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->get('id-1');

        $this->assertNull($mock->getLastPendingRequest()?->body());
    }

    public function test_turns_a_missing_registration_into_a_not_found_exception(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('webhook not found');

        self::webhooksResource([GetWebhookRequest::class => MockResponse::make(['error' => 'webhook not found'], 404)])
            ->get('nope');
    }

    // ---------------------------------------------------------------------------
    // update() — replace, not patch
    // ---------------------------------------------------------------------------

    public function test_sends_name_and_url_in_full_on_an_update_because_put_replaces(): void
    {
        // Confirmed live: a PUT carrying only `url` answers 400 with
        // {"error":"Validation Error: name: length must be between 2 and 100"} — the
        // name was not preserved, it was missing. This is the test that documents it.
        $mock = new MockClient([UpdateWebhookRequest::class => MockResponse::make(self::webhookBody(['url' => 'https://e.com/new']), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->update(
            'id-1',
            'SDK 2.0 link-hit capture',
            'https://e.com/new',
            [WebhookEventType::SmsStatus],
        );

        $request = $mock->getLastPendingRequest();

        $this->assertSame('PUT', $request?->getMethod()->value);
        $this->assertSame([
            'name' => 'SDK 2.0 link-hit capture',
            'url' => 'https://e.com/new',
            'filter' => ['event_type' => ['SMS_STATUS']],
        ], $request?->body()?->all());
    }

    public function test_targets_the_registration_id_in_the_path(): void
    {
        $this->assertSame('/v2/webhook/abc-123', (new UpdateWebhookRequest('abc-123', 'Events', 'https://e.com/h'))->resolveEndpoint());
        $this->assertSame('/v2/webhook/abc-123', (new GetWebhookRequest('abc-123'))->resolveEndpoint());
        $this->assertSame('/v2/webhook/abc-123', (new DeleteWebhookRequest('abc-123'))->resolveEndpoint());
        $this->assertSame('/v2/webhook', (new ListWebhooksRequest)->resolveEndpoint());
        $this->assertSame('/v2/webhook', (new CreateWebhookRequest('Events', 'https://e.com/h'))->resolveEndpoint());
    }

    // ---------------------------------------------------------------------------
    // delete()
    // ---------------------------------------------------------------------------

    public function test_reports_a_successful_delete_which_answers_200_rather_than_204(): void
    {
        $mock = new MockClient([DeleteWebhookRequest::class => MockResponse::make([], 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $this->assertTrue((new WebhooksResource($connector))->delete('id-1'));
        $this->assertSame('DELETE', $mock->getLastPendingRequest()?->getMethod()->value);
    }

    public function test_sends_no_body_on_the_delete(): void
    {
        $mock = new MockClient([DeleteWebhookRequest::class => MockResponse::make([], 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WebhooksResource($connector))->delete('id-1');

        $this->assertNull($mock->getLastPendingRequest()?->body());
    }

    public function test_throws_rather_than_returning_false_when_the_registration_does_not_exist(): void
    {
        $this->expectException(NotFoundException::class);

        self::webhooksResource([DeleteWebhookRequest::class => MockResponse::make(['error' => 'webhook not found'], 404)])
            ->delete('nope');
    }

    // ---------------------------------------------------------------------------
    // Error shape — this endpoint family is not RFC 9457
    // ---------------------------------------------------------------------------

    public function test_maps_the_plain_string_error_body_these_endpoints_return_not_rfc_9457(): void
    {
        // Verbatim from the live 400. The messaging endpoints answer RFC 9457 problem
        // details; this family answers {"error": "..."}, and fromV2Response() has a
        // branch for it that must stay reachable through these requests.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('length must be between 2 and 100');

        self::webhooksResource([
            CreateWebhookRequest::class => MockResponse::make(
                ['error' => 'Validation Error: name: length must be between 2 and 100'],
                400,
            ),
        ])->create('Events', 'https://example.com/hook');
    }

    // ---------------------------------------------------------------------------
    // WebhookData
    // ---------------------------------------------------------------------------

    public function test_carries_the_three_fields_the_documentation_never_mentions(): void
    {
        $hook = WebhookData::fromArray(self::webhookBody(['is_sandbox' => true]));

        $this->assertTrue($hook->isSandbox);
        $this->assertNotNull($hook->createdAt);
        $this->assertNotNull($hook->updatedAt);
    }

    public function test_parses_timestamps_at_either_fractional_precision_the_api_sends(): void
    {
        // Nine digits on a create response, six on a read. A fixed format would
        // parse one and drop the other.
        $this->assertNotNull(WebhookData::fromArray(self::webhookBody(['created_at' => '2026-08-05T15:23:11.730743151Z']))->createdAt);
        $this->assertNotNull(WebhookData::fromArray(self::webhookBody(['created_at' => '2026-08-05T15:48:45.072031Z']))->createdAt);
    }

    public function test_treats_rate_limit_0_as_the_system_default_rather_than_a_real_limit(): void
    {
        $this->assertFalse(WebhookData::fromArray(self::webhookBody(['rate_limit' => 0]))->hasRateLimit());
        $this->assertTrue(WebhookData::fromArray(self::webhookBody(['rate_limit' => 250]))->hasRateLimit());
        $this->assertSame(250, WebhookData::fromArray(self::webhookBody(['rate_limit' => 250]))->rateLimit);
    }

    public function test_reports_whether_a_registration_read_back_from_the_api_uses_tls(): void
    {
        // The platform accepts http:// even though the docs forbid it, so a plaintext
        // registration can exist — made by another tool, or before this SDK guarded it.
        $this->assertTrue(WebhookData::fromArray(self::webhookBody(['url' => 'https://e.com/h']))->isSecure());
        $this->assertFalse(WebhookData::fromArray(self::webhookBody(['url' => 'http://e.com/h']))->isSecure());
        $this->assertTrue(WebhookData::fromArray(self::webhookBody(['url' => 'HTTPS://e.com/h']))->isSecure());
    }

    public function test_survives_a_response_with_every_optional_field_missing(): void
    {
        $hook = WebhookData::fromArray(['id' => 'x']);

        $this->assertSame('x', $hook->id);
        $this->assertSame('', $hook->name);
        $this->assertTrue($hook->filter->isEmpty());
        $this->assertSame(0, $hook->rateLimit);
        $this->assertFalse($hook->isSandbox);
        $this->assertNull($hook->createdAt);
    }

    // ---------------------------------------------------------------------------
    // WebhookFilter
    // ---------------------------------------------------------------------------

    public function test_round_trips_a_filter_through_the_api_shape(): void
    {
        $filter = WebhookFilter::fromArray([
            'event_type' => ['SMS_STATUS', 'LINK_HIT'],
            'sender' => ['61481074185'],
            'status' => ['DELIVERED'],
            'message_ref' => ['order-1'],
            'campaign_id' => ['camp-1'],
        ]);

        $this->assertSame([
            'event_type' => ['SMS_STATUS', 'LINK_HIT'],
            'sender' => ['61481074185'],
            'status' => ['DELIVERED'],
            'message_ref' => ['order-1'],
            'campaign_id' => ['camp-1'],
        ], $filter->toArray());
    }

    public function test_drops_empty_values_and_non_strings_rather_than_sending_them(): void
    {
        $filter = new WebhookFilter(eventType: ['SMS_STATUS', '', 'LINK_HIT'], sender: []);

        $this->assertSame(['event_type' => ['SMS_STATUS', 'LINK_HIT']], $filter->toArray());
    }

    public function test_serialises_event_types_as_a_json_array_even_after_dropping_an_entry(): void
    {
        // A gapped numeric array encodes as a JSON object, which the API would
        // reject — the trap TemplateContent's parameters had to be reindexed for.
        $filter = new WebhookFilter(eventType: ['', 'SMS_STATUS', '', 'LINK_HIT']);

        $this->assertSame('{"event_type":["SMS_STATUS","LINK_HIT"]}', json_encode($filter->toArray()));
    }

    public function test_reports_an_entirely_empty_filter_as_empty(): void
    {
        $this->assertTrue((new WebhookFilter)->isEmpty());
        $this->assertFalse((new WebhookFilter(eventType: ['SMS_STATUS']))->isEmpty());
    }
}
