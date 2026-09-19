<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Support\SmsLength;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The documented limits: 160/153 on GSM-7, 70/67 on Unicode.
 *
 * The boundaries are the point of this suite. 160 is one part and 161 is two —
 * and 161 is not 161/153 rounded up from a 160-char part, it is two parts of
 * 153, because a multipart message pays the UDH header on EVERY part including
 * the first. Getting that wrong under-reports cost by one part on every long
 * message, which is exactly the bug a "close enough" implementation ships.
 */
#[CoversClass(SmsLength::class)]
final class SmsLengthTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, int}>
     */
    public static function messages(): array
    {
        return [
            // message, encoding, billable length, parts
            'empty' => ['', SmsLength::ENCODING_GSM, 0, 0],
            'one char' => ['a', SmsLength::ENCODING_GSM, 1, 1],
            'gsm at the single limit' => [str_repeat('a', 160), SmsLength::ENCODING_GSM, 160, 1],
            'gsm one past it' => [str_repeat('a', 161), SmsLength::ENCODING_GSM, 161, 2],
            'gsm at two parts exactly' => [str_repeat('a', 306), SmsLength::ENCODING_GSM, 306, 2],
            'gsm one past two parts' => [str_repeat('a', 307), SmsLength::ENCODING_GSM, 307, 3],

            // One character off the alphabet re-encodes the WHOLE message.
            'single emoji' => ['👍', SmsLength::ENCODING_UNICODE, 1, 1],
            'emoji drags the rest to ucs-2' => [str_repeat('a', 100).'👍', SmsLength::ENCODING_UNICODE, 101, 2],
            'curly quote is not gsm' => ['it’s', SmsLength::ENCODING_UNICODE, 4, 1],
            'straight quote is gsm' => ["it's", SmsLength::ENCODING_GSM, 4, 1],
            'unicode at the single limit' => [str_repeat('ê', 70), SmsLength::ENCODING_UNICODE, 70, 1],
            'unicode one past it' => [str_repeat('ê', 71), SmsLength::ENCODING_UNICODE, 71, 2],

            // é IS in the GSM alphabet; ê is not. A near-miss worth pinning.
            'e-acute stays gsm' => ['café', SmsLength::ENCODING_GSM, 4, 1],
            'e-circumflex does not' => ['crêpe', SmsLength::ENCODING_UNICODE, 5, 1],

            // The nine extended characters cost two units each.
            'euro costs two' => ['€', SmsLength::ENCODING_GSM, 2, 1],
            'braces cost two each' => ['{}', SmsLength::ENCODING_GSM, 4, 1],
            'eighty backslashes fill one part' => [str_repeat('\\', 80), SmsLength::ENCODING_GSM, 160, 1],
            'eighty-one overflow it' => [str_repeat('\\', 81), SmsLength::ENCODING_GSM, 162, 2],
        ];
    }

    #[DataProvider('messages')]
    public function test_it_measures_encoding_length_and_parts(
        string $message,
        string $encoding,
        int $length,
        int $parts,
    ): void {
        $sms = SmsLength::for($message);

        $this->assertSame($encoding, $sms->encoding(), 'encoding');
        $this->assertSame($length, $sms->length(), 'billable length');
        $this->assertSame($parts, $sms->parts(), 'parts');
    }

    public function test_remaining_counts_down_to_the_part_boundary(): void
    {
        $this->assertSame(160, SmsLength::for('')->remaining());
        $this->assertSame(0, SmsLength::for(str_repeat('a', 160))->remaining());

        // 161 chars occupy two parts of 153 = 306 units of capacity.
        $this->assertSame(306 - 161, SmsLength::for(str_repeat('a', 161))->remaining());

        // An empty message has no encoding evidence yet, so it reports the GSM
        // headroom of 160 — not 70. The Unicode floor only applies once a
        // non-GSM character has actually forced the re-encode.
        $this->assertSame(SmsLength::ENCODING_GSM, SmsLength::for('')->encoding());

        // Once it IS Unicode, the headroom is measured against 70.
        $this->assertSame(64, SmsLength::for(str_repeat('ê', 6))->remaining());
    }

    public function test_is_multipart_flips_at_the_documented_boundary(): void
    {
        $this->assertFalse(SmsLength::for(str_repeat('a', 160))->isMultipart());
        $this->assertTrue(SmsLength::for(str_repeat('a', 161))->isMultipart());
        $this->assertFalse(SmsLength::for(str_repeat('ê', 70))->isMultipart());
        $this->assertTrue(SmsLength::for(str_repeat('ê', 71))->isMultipart());
    }

    public function test_it_does_not_reject_a_long_message(): void
    {
        // Measuring, not gatekeeping: a 10-part message is legal and billable.
        $sms = SmsLength::for(str_repeat('a', 1530));

        $this->assertSame(10, $sms->parts());
        $this->assertSame(SmsLength::ENCODING_GSM, $sms->encoding());
    }
}
