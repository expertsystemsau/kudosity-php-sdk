<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Contracts\SentMessage;
use ExpertSystems\Kudosity\Data\V2\MmsMessageData;
use ExpertSystems\Kudosity\Data\V2\RcsMessageData;
use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * All four V2 message DTOs implement {@see SentMessage} as of 2.2.0.
 *
 * Before that only SmsMessageData did, because the contract existed solely for
 * the Laravel SMS channel's V1/V2 routing decision. The consequence was that a
 * consumer could not write one function handling a send across channels. The
 * contract is cheap for the other three — every V2 send endpoint takes exactly
 * one recipient, and all four responses carry an id and a status.
 */
#[CoversClass(MmsMessageData::class)]
#[CoversClass(RcsMessageData::class)]
#[CoversClass(SmsMessageData::class)]
#[CoversClass(WhatsAppMessageData::class)]
final class SentMessageContractTest extends TestCase
{
    /** @return array<string, array{SentMessage, string, MessageStatus}> */
    public static function sends(): array
    {
        return [
            'sms' => [
                SmsMessageData::fromArray([
                    'id' => 'sms-1', 'recipient' => '61400000000', 'sender' => '61437130145',
                    'message' => 'hi', 'status' => 'QUEUED', 'sms_count' => '1',
                    'created_at' => '2022-03-28T06:12:52.450674000Z',
                ]),
                'sms-1',
                MessageStatus::Queued,
            ],
            'mms' => [
                MmsMessageData::fromArray([
                    'id' => 'mms-1', 'recipient' => '61400000000', 'sender' => '61437130145',
                    'country' => 'AU', 'subject' => 's', 'message' => 'm', 'status' => 'PENDING',
                    'content_urls' => ['https://e.com/a.jpg'],
                    'created_at' => '2022-03-28T06:12:52.450674000Z',
                ]),
                'mms-1',
                MessageStatus::Pending,
            ],
            'whatsapp' => [
                WhatsAppMessageData::fromArray([
                    'id' => 'wa-1', 'recipient' => '61400000000', 'sender' => '61437130145',
                    'content_type' => 'text', 'content' => ['message' => 'hi'],
                    'status' => 'queued', 'created_at' => '2022-03-28T06:12:52.450674000Z',
                ]),
                'wa-1',
                MessageStatus::Queued,
            ],
            'rcs' => [
                RcsMessageData::fromArray([
                    'id' => 'rcs-1', 'recipient' => '61400000000', 'sender' => 'DemoAgent',
                    'content_type' => 'text', 'content' => ['message' => 'hi'],
                    'status' => 'queued', 'created_at' => '2022-03-28T06:12:52.450674000Z',
                ]),
                'rcs-1',
                MessageStatus::Queued,
            ],
        ];
    }

    #[DataProvider('sends')]
    public function test_every_v2_send_dto_satisfies_the_contract(
        SentMessage $sent,
        string $expectedId,
        MessageStatus $expectedStatus,
    ): void {
        $this->assertSame($expectedId, $sent->id());
        $this->assertSame($expectedStatus, $sent->status());
        $this->assertSame(1, $sent->recipientCount(), 'every V2 send endpoint takes exactly one recipient');
    }

    /**
     * The point of the contract: one function, all four channels.
     *
     * Written as a real polymorphic consumer rather than four instanceof
     * assertions, because that is the capability that did not exist before and
     * a type-only check would still pass if the accessors returned nonsense.
     */
    public function test_a_single_consumer_can_handle_every_channel(): void
    {
        $describe = static fn (SentMessage $m): string => sprintf(
            '%s/%d/%s',
            $m->id(),
            $m->recipientCount(),
            $m->status()?->value ?? 'none',
        );

        $lines = array_map(
            static fn (array $case): string => $describe($case[0]),
            self::sends(),
        );

        $this->assertSame(
            [
                'sms' => 'sms-1/1/QUEUED',
                'mms' => 'mms-1/1/PENDING',
                'whatsapp' => 'wa-1/1/QUEUED',
                'rcs' => 'rcs-1/1/QUEUED',
            ],
            $lines,
        );
    }

    public function test_whatsapp_and_rcs_report_a_null_status_rather_than_inventing_one(): void
    {
        // Their status is nullable because the API omits it on some reads.
        // MMS and SMS narrow the return type to non-null; these two must not.
        $whatsapp = WhatsAppMessageData::fromArray([
            'id' => 'wa-2', 'recipient' => '61400000000',
            'content_type' => 'text', 'content' => ['message' => 'hi'],
        ]);

        $this->assertNull($whatsapp->status());
        $this->assertSame(1, $whatsapp->recipientCount());
    }
}
