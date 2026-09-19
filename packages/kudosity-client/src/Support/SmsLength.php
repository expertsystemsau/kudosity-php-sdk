<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Support;

/**
 * How many characters a message costs, and how many parts it will be sent as.
 *
 * MEASURES, IT DOES NOT REJECT. A long message is not an error — the network
 * splits it and the account is billed per part. Refusing one here would break
 * a legitimate send; silently truncating would be worse. Callers that care
 * about cost (a bulk run, a template editor showing "2 parts") ask this class
 * and decide for themselves.
 *
 * The limits, per Kudosity's own documentation:
 *
 * | encoding      | single | per part when multipart |
 * |---------------|-------:|------------------------:|
 * | GSM-7         |    160 |                     153 |
 * | Unicode/UCS-2 |     70 |                      67 |
 *
 * A multipart message loses those characters to the UDH segmentation header
 * that each part carries, which is why 153 and 67 are not 160 and 70.
 *
 * ⚠ ENCODING IS ALL-OR-NOTHING. One character outside the GSM-7 set — a curly
 * quote pasted from Word, an emoji, an accented name — moves the WHOLE message
 * to UCS-2 and takes the limit from 160 to 70. That is the single most
 * surprising thing about SMS billing, and the reason this class reports
 * {@see encoding()} rather than only a number.
 *
 * ⚠ NINE GSM-7 CHARACTERS COST TWO. `^ { } \ [ ~ ] |` and `€` are not in the
 * basic alphabet; they are sent as an escape byte plus the character, so a
 * message of 160 backslashes is two parts, not one. {@see length()} returns
 * that billable count, not `mb_strlen()`.
 *
 * @see https://developers.kudosity.com/reference/post_v2-sms
 */
final class SmsLength
{
    public const GSM_SINGLE = 160;

    public const GSM_MULTIPART = 153;

    public const UNICODE_SINGLE = 70;

    public const UNICODE_MULTIPART = 67;

    public const ENCODING_GSM = 'GSM-7';

    public const ENCODING_UNICODE = 'UCS-2';

    /**
     * The GSM 03.38 basic alphabet. Every character here costs one unit.
     *
     * Written as an explicit list rather than a regex range: the set is not
     * contiguous in Unicode (it interleaves Latin, Greek and currency), so a
     * range would either admit characters the network cannot carry or exclude
     * ones it can.
     */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡"
        .'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /**
     * The GSM 03.38 extended set. Each of these costs TWO units — an escape
     * byte (0x1B) plus the character itself.
     */
    private const GSM_EXTENDED = "\f^{}\\[~]|€";

    private function __construct(
        private readonly string $encoding,
        private readonly int $length,
        private readonly int $parts,
    ) {}

    public static function for(string $message): self
    {
        $encoding = self::ENCODING_GSM;
        $length = 0;

        // preg_split with /u, not str_split: the message is UTF-8 and a byte
        // walk would cut a multi-byte character in half and misreport both the
        // encoding and the count.
        $characters = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $character) {
            if (mb_strpos(self::GSM_BASIC, $character) !== false) {
                $length++;

                continue;
            }

            if (mb_strpos(self::GSM_EXTENDED, $character) !== false) {
                $length += 2;

                continue;
            }

            // One character off the alphabet re-encodes the whole message, so
            // the GSM tally computed so far is meaningless — stop and recount.
            $encoding = self::ENCODING_UNICODE;
            $length = count($characters);

            break;
        }

        return new self($encoding, $length, self::countParts($encoding, $length));
    }

    private static function countParts(string $encoding, int $length): int
    {
        if ($length === 0) {
            return 0;
        }

        $single = $encoding === self::ENCODING_GSM ? self::GSM_SINGLE : self::UNICODE_SINGLE;

        if ($length <= $single) {
            return 1;
        }

        $perPart = $encoding === self::ENCODING_GSM ? self::GSM_MULTIPART : self::UNICODE_MULTIPART;

        return (int) ceil($length / $perPart);
    }

    /**
     * `GSM-7` or `UCS-2` — what the network will use for this message.
     */
    public function encoding(): string
    {
        return $this->encoding;
    }

    /**
     * Billable character units. NOT `mb_strlen()`: the nine extended GSM
     * characters each count two.
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * How many SMS this will be sent, and billed, as. `0` for an empty message.
     */
    public function parts(): int
    {
        return $this->parts;
    }

    /**
     * Units still available before this message needs another part. `0` means
     * the next character adds one.
     */
    public function remaining(): int
    {
        if ($this->parts === 0) {
            return $this->encoding === self::ENCODING_GSM ? self::GSM_SINGLE : self::UNICODE_SINGLE;
        }

        return ($this->parts * $this->perPart()) - $this->length;
    }

    /**
     * True when the message will be split. Worth surfacing in a UI: the cost
     * of the 161st character is a whole extra message.
     */
    public function isMultipart(): bool
    {
        return $this->parts > 1;
    }

    private function perPart(): int
    {
        if ($this->parts === 1) {
            return $this->encoding === self::ENCODING_GSM ? self::GSM_SINGLE : self::UNICODE_SINGLE;
        }

        return $this->encoding === self::ENCODING_GSM ? self::GSM_MULTIPART : self::UNICODE_MULTIPART;
    }
}
