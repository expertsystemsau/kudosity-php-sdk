<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Exceptions\AccessDeniedException;
use ExpertSystems\Kudosity\Exceptions\AuthenticationException;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ProblemIssue;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\ServerException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use StubV2SendRequest;

/**
 * Ported from the root Pest suite's tests/Unit/V2ErrorTest.php.
 *
 * V2TransportTest already carries four tests that overlap in *concept* with
 * ones here (a status-mapping table, an issues-extraction test, the
 * plain-string error shape, and a non-JSON/HTML body) — but each exercises a
 * genuinely different path: V2TransportTest drives them through a real
 * SmsV2Resource call (`->get()`/`->send()`), often against a simplified body
 * that isn't RFC 9457-shaped at all (e.g. `['title' => 'nope']`, no `error`
 * key), while every test here calls `KudosityException::fromV2Response()`
 * directly against the documented Problem Details shape from
 * `.agents/skills/kudosity-rcs/SKILL.md`. Different code path, different
 * body shape, and (bar the shared 400/401/403/404/422/429/500 status
 * literals) mostly different literal values — not byte-for-byte duplicates,
 * so both stay per the batch brief rather than folding one away silently.
 *
 * ProblemIssue and RateLimitException are named here too: the rate-limited
 * (429) row of the status-mapping dataset is the only place in the client
 * suite that drives `RateLimitException::fromResponseWithMetadata()` (and
 * its header-parsing helpers) rather than its constructor directly, and
 * "extracts every failed field from issues[] at once" is the only place
 * that drives `ProblemIssue::fromArray()`. Confirmed by a union-coverage
 * regression during this task — see the task report.
 */
#[CoversClass(KudosityException::class)]
#[CoversClass(KudosityV2Connector::class)]
#[CoversClass(RateLimitException::class)]
#[CoversClass(ProblemIssue::class)]
final class V2ErrorTest extends TestCase
{
    /**
     * The RFC 9457 shape the V2 messaging endpoints return, as documented in
     * .agents/skills/kudosity-rcs/SKILL.md.
     *
     * @param  array<int, array{name: string, message: string}>  $issues
     * @return array<string, mixed>
     */
    private static function problemBody(int $status, array $issues = []): array
    {
        return ['error' => array_filter([
            'type' => 'https://developers.kudosity.com/reference/errors#input-validation',
            'title' => 'Invalid Request',
            'detail' => 'Request validation failed',
            'status' => $status,
            'issues' => $issues,
        ])];
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    private static function v2Exception(int $status, array|string $body): KudosityException
    {
        $mock = new MockClient([StubV2SendRequest::class => MockResponse::make($body, $status)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $response = $connector->send(new StubV2SendRequest('hi'));

        return KudosityException::fromV2Response($response);
    }

    /** @return array<string, array{0: int, 1: class-string<KudosityException>}> */
    public static function documentedStatuses(): array
    {
        return [
            'validation (registry says 422)' => [422, ValidationException::class],
            'validation (endpoint docs say 400)' => [400, ValidationException::class],
            'unauthorized' => [401, AuthenticationException::class],
            'forbidden' => [403, AccessDeniedException::class],
            'not found' => [404, NotFoundException::class],
            'rate limited' => [429, RateLimitException::class],
            'server error' => [500, ServerException::class],
            'bad gateway' => [502, ServerException::class],
        ];
    }

    /** @param class-string<KudosityException> $class */
    #[DataProvider('documentedStatuses')]
    public function test_maps_every_documented_status_to_its_exception_class(int $status, string $class): void
    {
        $this->assertInstanceOf($class, self::v2Exception($status, self::problemBody($status)));
    }

    public function test_extracts_every_failed_field_from_issues_at_once(): void
    {
        $e = self::v2Exception(400, self::problemBody(400, [
            ['name' => 'sender', 'message' => 'sender is required'],
            ['name' => 'recipient', 'message' => 'recipient must be E.164'],
        ]));

        $issues = $e->getIssues();

        $this->assertCount(2, $issues);
        $this->assertSame('sender', $issues[0]->name);
        $this->assertSame('sender is required', $issues[0]->message);
        $this->assertSame('recipient', $issues[1]->name);
        $this->assertStringContainsString('sender is required', $e->getMessage());
        $this->assertStringContainsString('recipient must be E.164', $e->getMessage());
    }

    public function test_exposes_the_problem_type_uri_and_the_http_status_as_the_code(): void
    {
        $e = self::v2Exception(422, self::problemBody(422));

        $this->assertSame(
            'https://developers.kudosity.com/reference/errors#input-validation',
            $e->getProblemType(),
        );
        $this->assertSame(422, $e->getCode());
    }

    public function test_falls_back_to_detail_then_title_when_there_are_no_issues(): void
    {
        $this->assertSame(
            'boom',
            self::v2Exception(500, ['error' => ['detail' => 'boom', 'status' => 500]])->getMessage(),
        );
        $this->assertSame(
            'Server Error',
            self::v2Exception(500, ['error' => ['title' => 'Server Error', 'status' => 500]])->getMessage(),
        );
    }

    public function test_handles_the_plain_string_error_shape_the_webhook_endpoints_use(): void
    {
        $e = self::v2Exception(404, ['error' => 'SMS not found']);

        $this->assertInstanceOf(NotFoundException::class, $e);
        $this->assertSame('SMS not found', $e->getMessage());
        $this->assertSame([], $e->getIssues());
    }

    public function test_produces_a_useful_message_when_the_body_carries_no_error_at_all(): void
    {
        $e = self::v2Exception(503, []);

        $this->assertInstanceOf(ServerException::class, $e);
        $this->assertStringContainsString('503', $e->getMessage());
    }

    public function test_maps_a_non_json_html_error_body_to_server_exception_instead_of_crashing(): void
    {
        // What a proxy or load balancer actually returns for a 502 — never
        // JSON. Response::json() decodes with JSON_THROW_ON_ERROR, so
        // without a guard this throws JsonException instead of building the
        // typed exception.
        $e = self::v2Exception(502, '<html>502 Bad Gateway</html>');

        $this->assertInstanceOf(ServerException::class, $e);
    }

    public function test_produces_a_useful_message_when_a_body_decodes_to_a_literal_null(): void
    {
        // Saloon assigns json()'s result into a non-nullable array property,
        // so a literal `null` body throws TypeError rather than
        // JsonException.
        $this->assertStringContainsString('500', self::v2Exception(500, 'null')->getMessage());
    }

    public function test_reports_no_issues_for_a_v1_exception(): void
    {
        $mock = new MockClient([
            StubV2SendRequest::class => MockResponse::make(
                ['error' => ['code' => 'FIELD_EMPTY', 'description' => 'Required field is empty.']],
                400,
            ),
        ]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $this->assertSame(
            [],
            KudosityException::fromV1Response($connector->send(new StubV2SendRequest('hi')))->getIssues(),
        );
    }

    public function test_treats_a_201_as_success_not_a_failure(): void
    {
        $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'abc'], 201)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $response = $connector->send(new StubV2SendRequest('hi'));

        $this->assertFalse($response->failed());
        $this->assertNotTrue($connector->hasRequestFailed($response));
    }

    public function test_throws_the_mapped_exception_through_saloon_throw(): void
    {
        $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(self::problemBody(404), 404)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $this->expectException(NotFoundException::class);

        $connector->send(new StubV2SendRequest('hi'))->throw();
    }
}
