<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\OptOutSource;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Tests\Fixtures\Fixtures;
use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use ExpertSystems\Kudosity\Webhooks\InboundMedia;
use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use ExpertSystems\Kudosity\Webhooks\OptOutEvent;
use ExpertSystems\Kudosity\Webhooks\SourceMessage;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\UnknownEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the root Pest suite's tests/Unit/V2WebhookEventTest.php.
 *
 * Read Fixtures/V2Webhooks/README.md before changing anything here. Every
 * behaviour below traces to a live capture, not to the upstream documentation.
 *
 * Six root tests were not re-ported because WebhookPayloadTest.php's existing
 * equivalent already covers the same ground at least as strongly:
 *
 * - "dispatches each captured payload to its event class" (6-fixture dataset)
 *   — WebhookPayloadTest::capturedDeliveries() is the same dataset plus
 *   `mms-inbound-with-media`, a superset.
 * - "dispatches all ten documented event types to the right payload shape"
 *   — identical to WebhookPayloadTest::documentedEventTypes(), same 10 rows.
 * - "reads the correlation key through one accessor regardless of where it
 *   hides" — WebhookPayloadTest::correlationPaths() pins the *exact* ref per
 *   fixture; this one only asserted membership in a 2-value set, strictly
 *   weaker over the same three fixtures.
 * - "does not trim inbound message text" and "sniffs the bytes rather than
 *   trusting the filename extension" — byte-for-byte identical to
 *   WebhookPayloadTest's tests of the same name.
 * - "survives a payload whose section key is missing or the wrong type" —
 *   identical 3-case dataset to WebhookPayloadTest::hostileSections().
 *
 * Four more root tests are ported here because they are strictly *stronger*
 * than the WebhookPayloadTest test they overlap with, which made the older,
 * weaker test redundant — removed from WebhookPayloadTest.php in this same
 * commit:
 *
 * - "resolves an event type Kudosity has not published yet to an
 *   UnknownEvent" replaces test_an_unrecognised_event_type_is_returned_not_thrown
 *   (adds the eventType-enum and messageRef checks it lacked).
 * - "keeps the customer as sender and your own number as recipient on an
 *   inbound event" replaces test_the_customer_is_the_sender_on_an_inbound_event
 *   (adds the lastMessage-reversal checks it lacked).
 * - "counts hits cumulatively for the tracked link rather than per unique
 *   recipient" replaces test_a_link_hit_counts_cumulatively_and_is_not_evidence_of_a_human
 *   (adds the sourceMessage-id/url identity checks it lacked).
 * - "returns null bytes rather than throwing when inbound media will not
 *   decode" replaces test_undecodable_media_yields_null_bytes_rather_than_throwing
 *   (adds the raw content-preservation check it lacked).
 *
 * The inbound-MMS-media trio (exposes/decodes/infers) together supersede
 * test_inbound_mms_media_arrives_inline_rather_than_as_a_url; the
 * carries-three-fields test plus accepts-mms-status-delivered together
 * supersede test_an_mms_status_carries_the_carrier_description AND
 * test_the_two_undocumented_top_level_fields_are_exposed (same commonFields()
 * parse path, pinned here to exact values instead of not-null); the
 * cannot-correlate-an-inbound-mms test plus reports-a-null-message-for-a-
 * picture-only-inbound-mms test together supersede
 * test_an_inbound_mms_cannot_be_correlated. All of the superseded
 * WebhookPayloadTest.php tests were removed in this same commit. See the
 * task report for the full reconciliation.
 *
 * OptOutSource is named here too: the `optOutSources()` provider's 'absent'
 * row is the only place in the client suite that calls
 * `OptOutSource::fromApi(null)`, a different branch from the one
 * EnumToleranceTest's generic tolerance check drives (an unrecognised
 * non-empty string). Missing here during this port, it briefly cost the
 * union one statement of coverage — see the task report.
 */
#[CoversClass(WebhookEvent::class)]
#[CoversClass(StatusEvent::class)]
#[CoversClass(InboundEvent::class)]
#[CoversClass(LinkHitEvent::class)]
#[CoversClass(OptOutEvent::class)]
#[CoversClass(UnknownEvent::class)]
#[CoversClass(InboundMedia::class)]
#[CoversClass(SourceMessage::class)]
#[CoversClass(OptOutSource::class)]
final class V2WebhookEventTest extends TestCase
{
    /**
     * A documented payload shape with no fixture yet — built from the
     * skill's own example. Used only for OPT_OUT, which is deliberately
     * never captured (triggering it means replying STOP, which opts the
     * only test handset out), and for MMS_INBOUND cases that need a shape
     * the captured fixture doesn't carry (e.g. content_urls).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function documentedWebhook(string $eventType, array $payload): array
    {
        return array_merge([
            'event_type' => $eventType,
            'timestamp' => '2026-08-05T12:00:00Z',
            'webhook_id' => '8aa2842d-8931-4f6f-b55a-826a6698699a',
            'webhook_name' => 'documented example',
        ], $payload);
    }

    // -------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------

    public function test_resolves_an_unrecognised_event_type_to_an_unknown_event_not_an_exception(): void
    {
        // A receiver does not choose what it is sent, and a 500 here reads as
        // a dead endpoint from Kudosity's side — which triggers a retry into
        // the same 500.
        $payload = Fixtures::webhook('sms-status-sent');
        $payload['event_type'] = 'TELEPATHY_STATUS';

        $event = WebhookEvent::fromArray($payload);

        $this->assertInstanceOf(UnknownEvent::class, $event);
        $this->assertSame(WebhookEventType::Unknown, $event->eventType);
        $this->assertNull($event->messageRef());
        $this->assertSame('TELEPATHY_STATUS', $event->raw['event_type']);
    }

    public function test_keeps_the_raw_payload_verbatim_so_an_undocumented_field_is_never_lost(): void
    {
        // Three undocumented fields have already turned up in live payloads.
        // raw() is the escape hatch for the fourth.
        $payload = Fixtures::webhook('mms-status-delivered');
        $payload['some_field_shipped_next_quarter'] = ['nested' => true];

        $this->assertSame(
            ['nested' => true],
            WebhookEvent::fromArray($payload)->raw['some_field_shipped_next_quarter'],
        );
    }

    // -------------------------------------------------------------------
    // The uniform correlation accessor — the whole point of the base class
    // -------------------------------------------------------------------

    public function test_reads_the_opt_out_correlation_key_from_opt_out_source_message(): void
    {
        $event = WebhookEvent::fromArray(self::documentedWebhook('OPT_OUT', [
            'opt_out' => [
                'source' => 'SMS_INBOUND',
                'source_message' => ['id' => 'a51ebe4e', 'message_ref' => 'ncc5009d', 'recipient' => '61435790000', 'sender' => '61481074190'],
            ],
        ]));

        $this->assertSame('ncc5009d', $event->messageRef());
    }

    public function test_does_not_read_the_inbound_ref_off_mo_which_has_no_message_ref_of_its_own(): void
    {
        // The trap this accessor exists for: mo carries the customer's
        // message, last_message carries yours. Only the latter has the ref,
        // so a class reading mo.message_ref returns null and the reply goes
        // unrouted.
        $payload = Fixtures::webhook('sms-inbound-with-last-message');

        $this->assertArrayNotHasKey('message_ref', $payload['mo']);
        $this->assertSame('order-9931:cust-4471', WebhookEvent::fromArray($payload)->messageRef());
    }

    // -------------------------------------------------------------------
    // StatusEvent
    // -------------------------------------------------------------------

    public function test_carries_the_three_payload_fields_the_documentation_never_mentions(): void
    {
        $status = WebhookEvent::fromArray(Fixtures::webhook('mms-status-delivered'));

        $this->assertSame('8aa2842d-8931-4f6f-b55a-826a6698699a', $status->webhookId);
        $this->assertSame('SDK 2.0 phase 4 recon', $status->webhookName);
        $this->assertSame('Sent to Vodafone (response status details: Success)', $status->description);
    }

    public function test_leaves_description_null_on_the_channels_that_do_not_send_it(): void
    {
        $this->assertNull(WebhookEvent::fromArray(Fixtures::webhook('sms-status-delivered'))->description);
    }

    public function test_resolves_an_uppercase_webhook_status_through_the_same_enum_as_a_lowercase_send_response(): void
    {
        // Webhooks send DELIVERED; POST /v2/sms answers "delivered". One
        // enum, case-insensitive, which is load-bearing rather than
        // defensive.
        $this->assertSame(
            MessageStatus::Delivered,
            WebhookEvent::fromArray(Fixtures::webhook('sms-status-delivered'))->status,
        );
        $this->assertSame(MessageStatus::Delivered, MessageStatus::fromApi('delivered'));
    }

    public function test_accepts_an_mms_status_of_delivered_which_the_documentation_says_cannot_happen(): void
    {
        // The skill says MMS carries "internal statuses only — SENT, FAILED".
        // The captured fixture is DELIVERED. The fixture wins, so nothing
        // constrains MMS status to a subset.
        $this->assertSame(
            MessageStatus::Delivered,
            WebhookEvent::fromArray(Fixtures::webhook('mms-status-delivered'))->status,
        );
    }

    public function test_keeps_one_stable_status_id_across_every_status_event_for_a_message(): void
    {
        // The key the idempotency guard rests on. Both fixtures are the same
        // message, two events, four seconds apart.
        $sent = WebhookEvent::fromArray(Fixtures::webhook('sms-status-sent'));
        $delivered = WebhookEvent::fromArray(Fixtures::webhook('sms-status-delivered'));

        $this->assertSame($delivered->id, $sent->id);
        $this->assertSame(MessageStatus::Sent, $sent->status);
        $this->assertSame(MessageStatus::Delivered, $delivered->status);
    }

    public function test_normalises_an_empty_routed_via_to_null_rather_than_reporting_a_shared_number(): void
    {
        // The live API sends "" when no shared number was involved. "" is
        // not a number, and a truthiness check on it would report shared
        // routing.
        $payload = Fixtures::webhook('sms-status-delivered');
        $payload['status']['routed_via'] = '';

        $this->assertNull(WebhookEvent::fromArray($payload)->routedVia);
    }

    public function test_exposes_routed_via_when_a_shared_number_delivered_the_message(): void
    {
        $payload = Fixtures::webhook('sms-status-delivered');
        $payload['status']['routed_via'] = '447507333300';

        $this->assertSame('447507333300', WebhookEvent::fromArray($payload)->routedVia);
    }

    // -------------------------------------------------------------------
    // InboundEvent
    // -------------------------------------------------------------------

    public function test_exposes_the_attached_outbound_text_verbatim_too_not_just_the_inbound(): void
    {
        // SourceMessage is a separate parse path from InboundEvent's own
        // message, so it needs its own assertion — a mutation that trimmed
        // only this one went unnoticed until it had one. The outbound text
        // is what YOU sent, and code reconciling it against a template needs
        // it unmodified.
        $payload = Fixtures::webhook('sms-inbound-with-last-message');
        $payload['mo']['last_message']['message'] = "  Order #12345 shipped.\n";

        $this->assertSame(
            "  Order #12345 shipped.\n",
            WebhookEvent::fromArray($payload)->lastMessage?->message,
        );
    }

    public function test_keeps_the_customer_as_sender_and_your_own_number_as_recipient_on_an_inbound_event(): void
    {
        // The reverse of an outbound, and the source of the filter
        // surprise: the webhook filter's `sender` key matches mo.recipient
        // for inbound events, i.e. it filters by YOUR number.
        $inbound = WebhookEvent::fromArray(Fixtures::webhook('sms-inbound-with-last-message'));

        $this->assertSame('61400000000', $inbound->sender);
        $this->assertSame('61481074185', $inbound->recipient);
        $this->assertSame('61481074185', $inbound->lastMessage?->sender);
        $this->assertSame('61400000000', $inbound->lastMessage?->recipient);
    }

    public function test_reports_an_inbound_with_last_message_as_correlated_and_carries_the_outbound_text(): void
    {
        $inbound = WebhookEvent::fromArray(Fixtures::webhook('sms-inbound-with-last-message'));

        $this->assertTrue($inbound->isCorrelated());
        $this->assertStringContainsString('Please reply YES to this message', (string) $inbound->lastMessage?->message);
    }

    public function test_leaves_last_message_null_when_kudosity_found_no_recent_outbound_to_attach(): void
    {
        // Best-effort by documentation. An unsolicited inbound can be
        // neither correlated nor authenticated, so null is a valid state
        // rather than a parse failure — and isCorrelated() is how a
        // consumer branches on it.
        $payload = Fixtures::webhook('sms-inbound-with-last-message');
        unset($payload['mo']['last_message']);

        $inbound = WebhookEvent::fromArray($payload);

        $this->assertNull($inbound->lastMessage);
        $this->assertNull($inbound->messageRef());
        $this->assertFalse($inbound->isCorrelated());
        $this->assertSame('YES ', $inbound->message);
    }

    public function test_models_an_inbound_mms_subject_and_content_urls(): void
    {
        // No MMS_INBOUND fixture existed at the time this test was written
        // root-side — kept against the documented example rather than
        // rewritten against the later live capture, since the point here is
        // the non-string filtering, which the live capture doesn't exercise.
        $inbound = WebhookEvent::fromArray(self::documentedWebhook('MMS_INBOUND', [
            'mo' => [
                'type' => 'MMS',
                'id' => 'mo-1',
                'message' => 'here you go',
                'recipient' => '61481074190',
                'sender' => '447507222200',
                'subject' => 'My picture',
                'content_urls' => ['https://example.com/a.jpg', 42, 'https://example.com/b.jpg'],
            ],
        ]));

        $this->assertSame('My picture', $inbound->subject);
        // The non-string is dropped rather than cast: a URL that is not a
        // string is not a URL, and "42" would be a broken link.
        $this->assertSame(['https://example.com/a.jpg', 'https://example.com/b.jpg'], $inbound->contentUrls);
    }

    // -------------------------------------------------------------------
    // LinkHitEvent
    // -------------------------------------------------------------------

    public function test_reads_the_original_destination_from_url_and_the_shortened_link_from_the_message(): void
    {
        // The pairing that catches people: link_hit.url is where the
        // recipient was going, source_message.message is what was actually
        // sent — carrying the SHORTENED link. Code looking for the original
        // URL in the text finds the short one.
        $hit = WebhookEvent::fromArray(Fixtures::webhook('link-hit-sms'));

        $this->assertSame('https://www.example.com/abc', $hit->url);
        $this->assertStringContainsString('https://tapth.at/', (string) $hit->sourceMessage?->message);
        $this->assertStringNotContainsString('www.example.com', (string) $hit->sourceMessage?->message);
    }

    public function test_counts_hits_cumulatively_for_the_tracked_link_rather_than_per_unique_recipient(): void
    {
        // Two real deliveries for ONE send, sixteen seconds apart. The first
        // was a machine prefetch — it arrived in the same second as
        // DELIVERED, about two seconds after the send — and the second was
        // the human tap. So hits is not an engagement count, and
        // isFirstHit() is named for the fetch, not a click.
        $first = WebhookEvent::fromArray(Fixtures::webhook('link-hit-sms'));
        $second = WebhookEvent::fromArray(Fixtures::webhook('link-hit-sms-repeat'));

        $this->assertSame(1, $first->hits);
        $this->assertTrue($first->isFirstHit());
        $this->assertSame(2, $second->hits);
        $this->assertFalse($second->isFirstHit());
        // Same message, same link, same correlation key: the repeat is not a
        // second send.
        $this->assertSame($first->sourceMessage?->id, $second->sourceMessage?->id);
        $this->assertSame($first->url, $second->url);
    }

    public function test_casts_a_string_hits_count_to_int_since_v2_sends_numerics_as_strings_elsewhere(): void
    {
        $payload = Fixtures::webhook('link-hit-sms');
        $payload['link_hit']['hits'] = '7';

        $this->assertSame(7, WebhookEvent::fromArray($payload)->hits);
    }

    public function test_models_an_mms_link_hit_whose_source_message_carries_subject_and_content_urls(): void
    {
        $hit = WebhookEvent::fromArray(self::documentedWebhook('LINK_HIT', [
            'link_hit' => [
                'hits' => 1,
                'url' => 'https://www.example.com/abc',
                'source_message' => [
                    'type' => 'MMS',
                    'id' => 'faf68308',
                    'message' => 'Check this out http://clckme.info/KYhSsuIH',
                    'message_ref' => 'D301',
                    'recipient' => '61435795809',
                    'sender' => '61481074185',
                    'subject' => 'Sale',
                    'content_urls' => ['https://example.com/promo.png'],
                ],
            ],
        ]));

        $this->assertSame('Sale', $hit->sourceMessage?->subject);
        $this->assertSame(['https://example.com/promo.png'], $hit->sourceMessage?->contentUrls);
        $this->assertSame('MMS', $hit->sourceMessage?->type);
    }

    // -------------------------------------------------------------------
    // OptOutEvent
    // -------------------------------------------------------------------

    /** @return array<string, array{0: ?string, 1: OptOutSource}> */
    public static function optOutSources(): array
    {
        return [
            'STOP reply' => ['SMS_INBOUND', OptOutSource::SmsInbound],
            'opt-out link' => ['LINK_HIT', OptOutSource::LinkHit],
            'undocumented' => ['CARRIER_BLOCKLIST', OptOutSource::Unknown],
            'absent' => [null, OptOutSource::Unknown],
        ];
    }

    #[DataProvider('optOutSources')]
    public function test_resolves_both_documented_opt_out_sources_and_treats_an_unknown_one_as_an_opt_out(
        ?string $source,
        OptOutSource $expected,
    ): void {
        // No OPT_OUT fixture and deliberately none: triggering it means
        // replying STOP, which opts the only test handset out of receiving
        // messages.
        $event = WebhookEvent::fromArray(self::documentedWebhook('OPT_OUT', [
            'opt_out' => [
                'source' => $source,
                'source_message' => ['id' => 'a51ebe4e', 'recipient' => '61435790000', 'sender' => '61481074190'],
            ],
        ]));

        $this->assertInstanceOf(OptOutEvent::class, $event);
        $this->assertSame($expected, $event->source);
    }

    public function test_reports_the_number_that_opted_out_as_the_source_message_recipient_not_its_sender(): void
    {
        // On an opt-out the source_message is the outbound they reacted to,
        // so the person is its recipient. Reading `sender` here returns
        // your own number and would opt YOU out of your own list.
        $event = WebhookEvent::fromArray(self::documentedWebhook('OPT_OUT', [
            'opt_out' => [
                'source' => 'SMS_INBOUND',
                'source_message' => ['id' => 'a51ebe4e', 'recipient' => '61435790000', 'sender' => '61481074190'],
            ],
        ]));

        $this->assertInstanceOf(OptOutEvent::class, $event);
        $this->assertSame('61435790000', $event->optedOutNumber());
    }

    // -------------------------------------------------------------------
    // Timestamps and shared parsing
    // -------------------------------------------------------------------

    public function test_parses_the_delivery_timestamp(): void
    {
        $this->assertSame(
            '2026-08-05T11:57:59+00:00',
            WebhookEvent::fromArray(Fixtures::webhook('sms-status-sent'))->timestamp?->format('c'),
        );
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedTimestamps(): array
    {
        return [
            'malformed' => ['not-a-date'],
            'empty' => [''],
            'null' => [null],
            'non-string' => [12345],
        ];
    }

    #[DataProvider('malformedTimestamps')]
    public function test_returns_null_for_an_absent_or_malformed_timestamp_rather_than_throwing(mixed $value): void
    {
        $payload = Fixtures::webhook('sms-status-sent');
        $payload['timestamp'] = $value;

        $this->assertNull(WebhookEvent::fromArray($payload)->timestamp);
    }

    public function test_parses_a_nine_fractional_digit_timestamp_which_rfc3339_extended_rejects(): void
    {
        // The webhook resource's created_at/updated_at use this format too.
        $payload = Fixtures::webhook('sms-status-sent');
        $payload['timestamp'] = '2026-08-05T11:57:59.730743151Z';

        $this->assertNotNull(WebhookEvent::fromArray($payload)->timestamp);
    }

    // -------------------------------------------------------------------
    // Inbound MMS media
    //
    // Captured 2026-08-06, from the first inbound MMS this account could
    // receive — Kudosity replaced the virtual number precisely because the
    // previous one could not. The payload does not resemble the outbound
    // one it was modelled on.
    // -------------------------------------------------------------------

    public function test_exposes_inbound_mms_media_which_arrives_inline_as_base64_rather_than_as_a_url(): void
    {
        // The whole reason this test exists: `content_urls` was written
        // from the OUTBOUND documentation, and a real inbound MMS carries
        // no such key. Media comes back under `mo.media[]` with the bytes
        // themselves, so a receiver reading contentUrls got an empty array
        // and dropped the picture.
        $inbound = WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'));

        $this->assertSame([], $inbound->contentUrls);
        $this->assertCount(1, $inbound->media);
        $this->assertInstanceOf(InboundMedia::class, $inbound->media[0]);
        $this->assertSame('image000000.jpg', $inbound->media[0]->name);
    }

    public function test_decodes_inbound_media_to_the_original_bytes(): void
    {
        $media = WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'))->media[0];

        // The fixture's base64 is a real JPEG, shrunk from the 204KB one
        // that actually arrived. Assert on the magic bytes rather than a
        // length, so the test says "this is a decodable JPEG" rather than
        // "this is 705 bytes".
        $this->assertStringStartsWith("\xFF\xD8\xFF", (string) $media->bytes());
        $this->assertSame(strlen((string) $media->bytes()), $media->sizeInBytes());
    }

    public function test_infers_a_media_type_because_the_payload_carries_no_content_type_field_at_all(): void
    {
        $media = WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'))->media[0];

        $this->assertSame('image/jpeg', $media->mimeType());
    }

    public function test_returns_null_bytes_rather_than_throwing_when_inbound_media_will_not_decode(): void
    {
        // A receiver does not choose what it is sent. Same reasoning as
        // UnknownEvent: degrade, do not throw inside a public endpoint.
        $payload = Fixtures::webhook('mms-inbound-with-media');
        $payload['mo']['media'][0]['content'] = '!!!! not base64 !!!!';

        $media = WebhookEvent::fromArray($payload)->media[0];

        $this->assertNull($media->bytes());
        $this->assertSame(0, $media->sizeInBytes());
        $this->assertNull($media->mimeType());
        $this->assertSame('!!!! not base64 !!!!', $media->content);
    }

    public function test_has_no_media_and_no_correlation_for_an_inbound_event_that_carries_neither(): void
    {
        $inbound = WebhookEvent::fromArray(Fixtures::webhook('sms-inbound-with-last-message'));

        $this->assertSame([], $inbound->media);
    }

    public function test_cannot_correlate_an_inbound_mms_which_arrives_with_no_last_message(): void
    {
        // SMS_INBOUND carried `last_message` and correlated fine.
        // MMS_INBOUND did not — so messageRef() is null and routing an MMS
        // reply has to fall back to something else. Recorded because the
        // asymmetry is invisible from the event class, which is shared
        // between the two.
        $inbound = WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'));

        $this->assertFalse($inbound->isCorrelated());
        $this->assertNull($inbound->messageRef());
        $this->assertNull($inbound->lastMessage);
    }

    public function test_reports_a_null_message_for_a_picture_only_inbound_mms_whose_payload_omits_the_key(): void
    {
        $this->assertNull(WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'))->message);
    }

    public function test_exposes_the_inbound_sender_verbatim_leading_plus_included(): void
    {
        // MMS_INBOUND delivers `+61…` while `mo.recipient` in the SAME
        // payload has no plus, and SMS_INBOUND had none either. Normalising
        // here would hide an inconsistency the consumer needs to know
        // about when matching numbers.
        $inbound = WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'));

        $this->assertSame('+61400000000', $inbound->sender);
        $this->assertSame('61481074185', $inbound->recipient);
    }

    public function test_ignores_media_entries_that_are_not_shaped_like_media(): void
    {
        $payload = Fixtures::webhook('mms-inbound-with-media');
        $payload['mo']['media'][] = 'nope';
        $payload['mo']['media'][] = ['name' => 'no-content.jpg'];

        $this->assertCount(1, WebhookEvent::fromArray($payload)->media);
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonListMedia(): array
    {
        return [
            'a string' => ['nope'],
            'null' => [null],
            'a scalar' => [7],
        ];
    }

    #[DataProvider('nonListMedia')]
    public function test_survives_a_media_key_that_is_not_a_list_at_all(mixed $media): void
    {
        $payload = Fixtures::webhook('mms-inbound-with-media');
        $payload['mo']['media'] = $media;

        $this->assertSame([], WebhookEvent::fromArray($payload)->media);
    }

    public function test_reports_an_unknown_media_type_as_null_rather_than_guessing_octet_stream(): void
    {
        // Decodable bytes, no recognised signature, no recognised
        // extension. A guessed `application/octet-stream` reads as a fact
        // the payload never stated — the same reason V1 sends report a
        // null status instead of an invented Pending.
        $payload = Fixtures::webhook('mms-inbound-with-media');
        $payload['mo']['media'][0]['content'] = base64_encode('plainly not a known format');
        $payload['mo']['media'][0]['name'] = 'attachment.unheardof';

        $media = WebhookEvent::fromArray($payload)->media[0];

        $this->assertSame('plainly not a known format', $media->bytes());
        $this->assertNull($media->mimeType());
    }

    public function test_falls_back_to_the_extension_only_when_the_bytes_match_no_signature(): void
    {
        // WebP has no signature entry, so this is the fallback's real job:
        // it is reached, and it is reached second.
        $payload = Fixtures::webhook('mms-inbound-with-media');
        $payload['mo']['media'][0]['content'] = base64_encode('no signature here');
        $payload['mo']['media'][0]['name'] = 'sticker.WEBP';

        $this->assertSame('image/webp', WebhookEvent::fromArray($payload)->media[0]->mimeType());
    }
}
