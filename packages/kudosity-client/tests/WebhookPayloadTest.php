<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use ExpertSystems\Kudosity\Tests\Fixtures\Fixtures;
use ExpertSystems\Kudosity\Webhooks\InboundEvent;
use ExpertSystems\Kudosity\Webhooks\InboundMedia;
use ExpertSystems\Kudosity\Webhooks\LinkHitEvent;
use ExpertSystems\Kudosity\Webhooks\OptOutEvent;
use ExpertSystems\Kudosity\Webhooks\StatusEvent;
use ExpertSystems\Kudosity\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The webhook payload surface, against the captured deliveries.
 *
 * Read `Fixtures/V2Webhooks/README.md` before changing anything here. Every
 * behaviour below was established by a live capture, and several of them
 * contradict the upstream documentation.
 *
 * Eight tests moved out in the Task 7b batch 5 port, superseded by a
 * strictly stronger ported equivalent in V2WebhookEventTest.php (which
 * covers UnknownEvent now, so it is no longer named here): the
 * unrecognised-event-type test, the inbound sender/recipient test, the
 * link-hit-cumulative test, the inbound-MMS-media trio
 * (arrives-inline/decodes/infers), the undecodable-media test, the
 * cannot-correlate-an-inbound-mms test, and the two MMS-status/undocumented-
 * fields tests (folded into one exact-value test there). See the task
 * report for the full reconciliation.
 */
#[CoversClass(WebhookEvent::class)]
#[CoversClass(StatusEvent::class)]
#[CoversClass(InboundEvent::class)]
#[CoversClass(LinkHitEvent::class)]
#[CoversClass(OptOutEvent::class)]
#[CoversClass(InboundMedia::class)]
final class WebhookPayloadTest extends TestCase
{
    /** @return array<string, array{0: string, 1: class-string}> */
    public static function capturedDeliveries(): array
    {
        return [
            'sms sent' => ['sms-status-sent', StatusEvent::class],
            'sms delivered' => ['sms-status-delivered', StatusEvent::class],
            'mms delivered' => ['mms-status-delivered', StatusEvent::class],
            'sms inbound' => ['sms-inbound-with-last-message', InboundEvent::class],
            'mms inbound' => ['mms-inbound-with-media', InboundEvent::class],
            'link hit' => ['link-hit-sms', LinkHitEvent::class],
            'link hit repeat' => ['link-hit-sms-repeat', LinkHitEvent::class],
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('capturedDeliveries')]
    public function test_each_captured_delivery_resolves_to_its_payload_class(
        string $fixture,
        string $expected,
    ): void {
        $this->assertInstanceOf($expected, WebhookEvent::fromArray(Fixtures::webhook($fixture)));
    }

    public function test_every_captured_fixture_appears_in_the_dispatch_table(): void
    {
        // Without this, adding a fixture and forgetting its row leaves the
        // suite green while the new capture is never parsed by anything.
        $onDisk = array_map(
            static fn (string $f): string => basename($f, '.json'),
            (array) glob(Fixtures::path('V2Webhooks/*.json')),
        );

        $covered = array_column(self::capturedDeliveries(), 0);

        sort($onDisk);
        $missing = array_values(array_diff($onDisk, $covered));

        $this->assertSame([], $missing, 'Captured fixtures with no dispatch-table row.');
    }

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function documentedEventTypes(): array
    {
        return [
            'SMS_STATUS' => ['SMS_STATUS', StatusEvent::class],
            'MMS_STATUS' => ['MMS_STATUS', StatusEvent::class],
            'WHATSAPP_STATUS' => ['WHATSAPP_STATUS', StatusEvent::class],
            'RCS_STATUS' => ['RCS_STATUS', StatusEvent::class],
            'SMS_INBOUND' => ['SMS_INBOUND', InboundEvent::class],
            'MMS_INBOUND' => ['MMS_INBOUND', InboundEvent::class],
            'WHATSAPP_INBOUND' => ['WHATSAPP_INBOUND', InboundEvent::class],
            'RCS_INBOUND' => ['RCS_INBOUND', InboundEvent::class],
            'LINK_HIT' => ['LINK_HIT', LinkHitEvent::class],
            'OPT_OUT' => ['OPT_OUT', OptOutEvent::class],
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('documentedEventTypes')]
    public function test_all_ten_documented_types_map_to_the_right_shape(
        string $eventType,
        string $expected,
    ): void {
        // Ten types, four shapes. The mapping is what a consumer cannot infer
        // from the event name — WHATSAPP_STATUS and RCS_STATUS share
        // StatusEvent with SMS.
        $bodies = [
            'status' => ['status' => ['id' => 'x', 'status' => 'SENT', 'recipient' => '614', 'sender' => '614']],
            'mo' => ['mo' => ['id' => 'x', 'message' => 'hi', 'recipient' => '614', 'sender' => '614']],
            'link_hit' => ['link_hit' => ['hits' => 1, 'url' => 'https://e.com']],
            'opt_out' => ['opt_out' => ['source' => 'SMS_INBOUND']],
        ];

        $key = match (true) {
            str_ends_with($eventType, '_STATUS') => 'status',
            str_ends_with($eventType, '_INBOUND') => 'mo',
            $eventType === 'LINK_HIT' => 'link_hit',
            default => 'opt_out',
        };

        $payload = array_merge(['event_type' => $eventType], $bodies[$key]);

        $this->assertInstanceOf($expected, WebhookEvent::fromArray($payload));
    }

    public function test_the_ten_documented_types_are_exactly_the_enum_minus_unknown(): void
    {
        // Pins the table above to the enum, so a type added to one and not the
        // other is a failure rather than a silent coverage gap.
        $enumCases = array_values(array_filter(
            array_map(static fn (WebhookEventType $t): string => $t->value, WebhookEventType::cases()),
            static fn (string $v): bool => $v !== 'UNKNOWN',
        ));

        $tabled = array_column(self::documentedEventTypes(), 0);

        sort($enumCases);
        sort($tabled);

        $this->assertSame($enumCases, $tabled);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function correlationPaths(): array
    {
        // The same accessor over four different payload paths. That is the
        // whole reason messageRef() exists.
        return [
            'status' => ['sms-status-delivered', 'order-9931:cust-4471'],
            'inbound' => ['sms-inbound-with-last-message', 'order-9931:cust-4471'],
            // A different ref, because it was a different captured send — and
            // still composite, which is why a signed ref must be split on the
            // LAST colon rather than the first.
            'link hit' => ['link-hit-sms', 'linkhit-8842:cust-4471'],
        ];
    }

    #[DataProvider('correlationPaths')]
    public function test_message_ref_reads_a_different_path_per_event_type(
        string $fixture,
        string $expected,
    ): void {
        $this->assertSame($expected, WebhookEvent::fromArray(Fixtures::webhook($fixture))->messageRef());
    }

    public function test_it_does_not_trim_inbound_message_text(): void
    {
        // Real inbound text is untidy: the captured reply is "YES " with the
        // trailing space. Trimming here hides it from the consumer, who is the
        // one who gets to decide what "equal" means.
        $this->assertSame(
            'YES ',
            WebhookEvent::fromArray(Fixtures::webhook('sms-inbound-with-last-message'))->message,
        );
    }

    public function test_inbound_media_type_is_sniffed_not_read_from_the_filename(): void
    {
        // The name arrives from whoever sent the message. Saving it to disk on
        // the strength of its extension is how a receiver hosts something else.
        $payload = Fixtures::webhook('mms-inbound-with-media');
        $payload['mo']['media'][0]['name'] = 'not-really.png';

        $this->assertSame('image/jpeg', WebhookEvent::fromArray($payload)->media[0]->mimeType());
    }

    public function test_a_carrier_mmsc_id_survives_intact(): void
    {
        // Not a UUID. Anything validating V2 ids as UUIDs rejects a real
        // inbound MMS.
        $this->assertSame(
            'yruOoxEwEjiQqGR4j9Vurs@mmsc.telstra.com',
            WebhookEvent::fromArray(Fixtures::webhook('mms-inbound-with-media'))->id,
        );
    }

    /** @return array<string, array{0: mixed}> */
    public static function hostileSections(): array
    {
        return ['missing' => [null], 'a string' => ['nope'], 'a list' => [[1, 2, 3]]];
    }

    #[DataProvider('hostileSections')]
    public function test_it_survives_a_section_that_is_missing_or_the_wrong_type(mixed $section): void
    {
        // A truncated or hostile POST body must not fatal a public endpoint.
        $event = WebhookEvent::fromArray(['event_type' => 'SMS_STATUS', 'status' => $section]);

        $this->assertInstanceOf(StatusEvent::class, $event);
        $this->assertSame('', $event->id);
        $this->assertSame(MessageStatus::Unknown, $event->status);
        $this->assertNull($event->messageRef());
    }
}
