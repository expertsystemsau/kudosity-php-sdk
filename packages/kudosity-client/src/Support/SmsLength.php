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

    /**
     * Would this message fit in `$maxParts` or fewer?
     *
     * ⚠ THE API SETS NO MAXIMUM. `POST /v2/sms` has no `maxLength` on `message`
     * and documents no part cap — a 20-part message sends, and bills 20 times.
     * So the ceiling is a BUSINESS decision (cost, or a carrier's own limit),
     * never a protocol one, which is why this takes the number instead of
     * hiding one inside the SDK.
     */
    public function fitsWithin(int $maxParts): bool
    {
        if ($maxParts < 1) {
            throw new \InvalidArgumentException('maxParts must be at least 1, got '.$maxParts);
        }

        return $this->parts <= $maxParts;
    }

    /**
     * How many billable units fit in `$parts` parts at this encoding.
     *
     * Note the discontinuity: one part holds 160, but two hold 306 — not 320.
     * The moment a message needs splitting EVERY part pays the UDH header,
     * including the first, so the second part costs you 7 characters off the
     * first as well as its own.
     */
    public static function capacityFor(int $parts, string $encoding = self::ENCODING_GSM): int
    {
        if ($parts < 1) {
            throw new \InvalidArgumentException('parts must be at least 1, got '.$parts);
        }

        $gsm = $encoding === self::ENCODING_GSM;

        if ($parts === 1) {
            return $gsm ? self::GSM_SINGLE : self::UNICODE_SINGLE;
        }

        return $parts * ($gsm ? self::GSM_MULTIPART : self::UNICODE_MULTIPART);
    }

    /**
     * The longest prefix of `$message` that still fits in `$maxParts`.
     *
     * Cuts on CHARACTER boundaries and counts in billable units, so it never
     * splits a multi-byte character and never leaves a trailing escape whose
     * partner was cut — a naive `mb_substr()` to a character count does both.
     *
     * ⚠ Conservative by one edge case, deliberately: capacity is measured at
     * the ORIGINAL message's encoding. If the only non-GSM character sits past
     * the cut, the result is pure GSM and could have held more. Recomputing to
     * exploit that would mean re-encoding on every character, and the failure
     * direction matters more than the last few characters — this way the
     * result ALWAYS fits.
     */
    public static function truncateToParts(string $message, int $maxParts): string
    {
        $capacity = self::capacityFor($maxParts, self::for($message)->encoding());

        $kept = '';
        $used = 0;

        foreach (preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $cost = mb_strpos(self::GSM_EXTENDED, $character) !== false ? 2 : 1;

            if ($used + $cost > $capacity) {
                break;
            }

            $kept .= $character;
            $used += $cost;
        }

        return $kept;
    }

    private function perPart(): int
    {
        if ($this->parts === 1) {
            return $this->encoding === self::ENCODING_GSM ? self::GSM_SINGLE : self::UNICODE_SINGLE;
        }

        return $this->encoding === self::ENCODING_GSM ? self::GSM_MULTIPART : self::UNICODE_MULTIPART;
    }
}
