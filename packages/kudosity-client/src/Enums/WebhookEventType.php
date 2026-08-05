<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * The ten event types a V2 webhook can deliver.
 *
 * Two things here catch people out, and both are documented rather than
 * inferred:
 *
 * - **`SMS_STATUS` does not report WhatsApp or RCS.** Each channel has its own
 *   status event. Subscribing to `SMS_STATUS` and sending WhatsApp gets you
 *   silence, not errors.
 * - **`READ` is RCS-only.** It arrives as a {@see MessageStatus} on an
 *   `RCS_STATUS` event; no other channel reports it.
 *
 * Resolution goes through {@see self::fromApi()} and lands on
 * {@see self::Unknown} for anything undocumented — the same reasoning as
 * {@see MessageStatus} and {@see RcsCapabilityCode}. A receiver that 500s
 * because Kudosity shipped a new event type is worse than one that logs an
 * unrecognised payload, and a webhook endpoint cannot choose what it is sent.
 */
enum WebhookEventType: string
{
    case SmsStatus = 'SMS_STATUS';
    case SmsInbound = 'SMS_INBOUND';
    case MmsStatus = 'MMS_STATUS';
    case MmsInbound = 'MMS_INBOUND';
    case WhatsAppStatus = 'WHATSAPP_STATUS';
    case WhatsAppInbound = 'WHATSAPP_INBOUND';
    case RcsStatus = 'RCS_STATUS';
    case RcsInbound = 'RCS_INBOUND';
    case LinkHit = 'LINK_HIT';
    case OptOut = 'OPT_OUT';
    case Unknown = 'UNKNOWN';

    /**
     * Resolve an event type from a delivered payload, tolerating case and novelty.
     */
    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }

    /**
     * Whether this event carries a delivery status, under a `status` key.
     */
    public function isStatus(): bool
    {
        return match ($this) {
            self::SmsStatus, self::MmsStatus, self::WhatsAppStatus, self::RcsStatus => true,
            default => false,
        };
    }

    /**
     * Whether this event carries an inbound message, under an `mo` key.
     */
    public function isInbound(): bool
    {
        return match ($this) {
            self::SmsInbound, self::MmsInbound, self::WhatsAppInbound, self::RcsInbound => true,
            default => false,
        };
    }

    /**
     * The channel this event belongs to, or null for the channel-agnostic events.
     *
     * `LINK_HIT` and `OPT_OUT` return null: both carry a `source_message` whose
     * own `type` names the channel, so the event type does not need to.
     */
    public function channel(): ?string
    {
        return match ($this) {
            self::SmsStatus, self::SmsInbound => 'SMS',
            self::MmsStatus, self::MmsInbound => 'MMS',
            self::WhatsAppStatus, self::WhatsAppInbound => 'WHATSAPP',
            self::RcsStatus, self::RcsInbound => 'RCS',
            default => null,
        };
    }
}
