<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\GuardsMessageRef;
use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\GetMmsRequest;
use ExpertSystems\Kudosity\Requests\V2\SendMmsRequest;
use ExpertSystems\Kudosity\Resources\MmsResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/V2MmsTest.php.
 *
 * Resource-level behaviour for single-recipient MMS. `RequestShapeTest`
 * already pins this class's endpoint, method and the subject-length guard
 * (`test_it_rejects_an_mms_subject_over_twenty_characters`); the root
 * suite's identical "rejects a subject longer than 20 characters" test is
 * not re-ported here for that reason — same class, same guard, same 21-char
 * boundary. Everything else in this file is root-only: content_urls
 * validation (empty, more-than-one, relative), the ASCII-only subject rule,
 * the message-length guard, this class's own message_ref guard (a different
 * call site to the same shared trait `RequestShapeTest` only exercises via
 * `SendSmsV2Request`), the flat-envelope resolution, the pending-is-not-a-
 * failure status mapping, and `MmsMessageData`'s casts and timestamp parsing.
 */
#[CoversClass(MmsResource::class)]
#[CoversClass(SendMmsRequest::class)]
#[CoversClass(GetMmsRequest::class)]
#[CoversClass(MmsMessageData::class)]
#[CoversTrait(GuardsMessageRef::class)]
final class V2MmsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function mmsSendBody(array $overrides = []): array
    {
        // Verbatim from .agents/skills/kudosity-mms/SKILL.md — note the FLAT envelope.
        return array_merge([
            'id' => '6fdae71c-dad7-4c36-9734-a69693ec2318',
            'recipient' => '61491570019',
            'sender' => '61491570017',
            'country' => 'AU',
            'subject' => 'USS Enterprise',
            'message' => 'Check out this amazing specimen.',
            'message_ref' => 'ncc1701d',
            'content_urls' => ['https://example.com/product.jpg'],
            'status' => 'pending',
            'track_links' => true,
            'created_at' => '2022-03-29T04:42:01.631708761Z',
            'updated_at' => '2022-03-29T04:42:01.631708761Z',
        ], $overrides);
    }

    /** @param array<class-string, MockResponse> $responses */
    private static function mmsResource(array $responses): MmsResource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return new MmsResource($connector);
    }

    public function test_sends_a_single_recipient_mms_and_returns_a_typed_dto(): void
    {
        $mock = new MockClient([SendMmsRequest::class => MockResponse::make(self::mmsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $mms = (new MmsResource($connector))->send('61491570019', '61491570017', ['https://example.com/product.jpg']);

        $this->assertInstanceOf(MmsMessageData::class, $mms);
        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ec2318', $mms->id);
        $this->assertSame('AU', $mms->country);
        $this->assertSame(['https://example.com/product.jpg'], $mms->contentUrls);

        $this->assertSame([
            'sender' => '61491570017',
            'recipient' => '61491570019',
            'content_urls' => ['https://example.com/product.jpg'],
        ], $mock->getLastPendingRequest()->body()->all());
    }

    public function test_resolves_the_flat_envelope_through_payload(): void
    {
        // MMS is flat like SMS (unlike WhatsApp/RCS), but still routes through
        // payload() so all four V2 channels stay identical regardless of shape.
        $mms = self::mmsResource([SendMmsRequest::class => MockResponse::make(self::mmsSendBody(), 200)])
            ->send('61491570019', '61491570017', ['https://example.com/product.jpg']);

        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ec2318', $mms->id);
    }

    public function test_maps_status_pending_on_the_immediate_response_without_treating_it_as_a_failure(): void
    {
        // The skill is explicit: submission-pending is not an error. A pending
        // MMS response must resolve successfully to MessageStatus::Pending, not
        // throw.
        $mms = self::mmsResource([SendMmsRequest::class => MockResponse::make(self::mmsSendBody(['status' => 'pending']), 200)])
            ->send('61491570019', '61491570017', ['https://example.com/product.jpg']);

        $this->assertSame(MessageStatus::Pending, $mms->status);
    }

    public function test_sends_optional_fields_when_given(): void
    {
        $mock = new MockClient([SendMmsRequest::class => MockResponse::make(self::mmsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new MmsResource($connector))->send(
            '61491570019',
            '61491570017',
            ['https://example.com/product.jpg'],
            subject: 'New Arrival',
            message: 'Check out our latest product!',
            messageRef: 'order-1',
            trackLinks: true,
        );

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('New Arrival', $body['subject']);
        $this->assertSame('Check out our latest product!', $body['message']);
        $this->assertSame('order-1', $body['message_ref']);
        $this->assertTrue($body['track_links']);
    }

    public function test_omits_optional_fields_from_the_body_rather_than_sending_nulls(): void
    {
        $mock = new MockClient([SendMmsRequest::class => MockResponse::make(self::mmsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new MmsResource($connector))->send('61491570019', '61491570017', ['https://example.com/product.jpg']);

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertArrayNotHasKey('subject', $body);
        $this->assertArrayNotHasKey('message', $body);
        $this->assertArrayNotHasKey('message_ref', $body);
        $this->assertArrayNotHasKey('track_links', $body);
    }

    public function test_rejects_an_empty_content_urls_array(): void
    {
        $this->expectException(ValidationException::class);

        new SendMmsRequest('61491570019', '61491570017', []);
    }

    public function test_rejects_more_than_one_content_url_naming_the_one_file_limit(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/one/');

        new SendMmsRequest('61491570019', '61491570017', [
            'https://example.com/product.jpg',
            'https://example.com/other.jpg',
        ]);
    }

    public function test_rejects_a_relative_content_url(): void
    {
        $this->expectException(ValidationException::class);

        new SendMmsRequest('61491570019', '61491570017', ['/relative/path.jpg']);
    }

    // A "rejects a subject longer than 20 characters" test is deliberately
    // not ported here — see the class docblock.

    public function test_rejects_a_non_ascii_subject_even_within_the_20_character_limit(): void
    {
        // The one a caller will not expect: length alone is not enough, the API
        // documents subject as ASCII-only.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/ASCII/');

        new SendMmsRequest(
            '61491570019',
            '61491570017',
            ['https://example.com/product.jpg'],
            subject: 'Café Launch',
        );
    }

    public function test_rejects_a_message_longer_than_1000_characters(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/1000/');

        new SendMmsRequest(
            '61491570019',
            '61491570017',
            ['https://example.com/product.jpg'],
            message: str_repeat('a', 1001),
        );
    }

    public function test_rejects_a_message_ref_longer_than_the_documented_500_characters(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/500/');

        new SendMmsRequest(
            '61491570019',
            '61491570017',
            ['https://example.com/product.jpg'],
            messageRef: str_repeat('a', 501),
        );
    }

    public function test_does_not_reject_a_non_au_recipient_client_side_because_mms_is_au_only_is_the_apis_judgement_to_make_not_the_sdks(): void
    {
        $mock = new MockClient([SendMmsRequest::class => MockResponse::make(
            self::mmsSendBody(['recipient' => '14155552671', 'country' => 'US']),
            200,
        )]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $mms = (new MmsResource($connector))->send('14155552671', '61491570017', ['https://example.com/product.jpg']);

        $this->assertInstanceOf(MmsMessageData::class, $mms);
        $this->assertSame('14155552671', $mms->recipient);
    }

    public function test_reads_one_mms_by_id(): void
    {
        $mock = new MockClient([GetMmsRequest::class => MockResponse::make(self::mmsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $mms = (new MmsResource($connector))->get('6fdae71c-dad7-4c36-9734-a69693ec2318');

        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ec2318', $mms->id);
        $this->assertSame(
            'https://api.transmitmessage.com/v2/mms/6fdae71c-dad7-4c36-9734-a69693ec2318',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_sends_no_body_on_the_get_reader(): void
    {
        // Same split as SMS: readers extend KudosityV2Request (no body) so a V2
        // GET never carries one, which some gateways strip or reject.
        $mock = new MockClient([GetMmsRequest::class => MockResponse::make(self::mmsSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new MmsResource($connector))->get('abc');

        $pending = $mock->getLastPendingRequest();

        $this->assertNull($pending->headers()->get('Content-Type'));
        $this->assertSame('', (string) $pending->body());
    }

    public function test_turns_a_404_into_a_not_found_exception(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('MMS not found');

        self::mmsResource([GetMmsRequest::class => MockResponse::make(['error' => 'MMS not found'], 404)])->get('nope');
    }

    public function test_casts_track_links_to_a_bool(): void
    {
        $mms = MmsMessageData::fromArray(self::mmsSendBody(['track_links' => true]));

        $this->assertTrue($mms->trackLinks);
        $this->assertIsBool($mms->trackLinks);
    }

    public function test_parses_the_nine_fractional_digit_created_at_timestamp_that_rfc3339_extended_rejects(): void
    {
        $mms = MmsMessageData::fromArray(self::mmsSendBody(['created_at' => '2022-03-29T04:42:01.631708761Z']));

        $this->assertInstanceOf(DateTimeImmutable::class, $mms->createdAt);
        $this->assertNotNull($mms->createdAt);
    }

    public function test_returns_null_for_a_malformed_created_at_rather_than_throwing(): void
    {
        $mms = MmsMessageData::fromArray(self::mmsSendBody(['created_at' => 'not-a-date']));

        $this->assertNull($mms->createdAt);
    }
}
