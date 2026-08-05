<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * How a recipient opted out, from `opt_out.source`.
 *
 * **Both are binding.** The docs are explicit that a link opt-out is not weaker
 * consent withdrawal than a STOP reply, so nothing in this SDK treats
 * {@see self::LinkHit} as provisional. The distinction is for reporting and
 * audit, not for deciding whether to keep sending.
 *
 * Open like every other V2 enum: an undocumented source lands on
 * {@see self::Unknown}, and an unknown source is still an opt-out.
 */
enum OptOutSource: string
{
    case SmsInbound = 'SMS_INBOUND';
    case LinkHit = 'LINK_HIT';
    case Unknown = 'UNKNOWN';

    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }
}
