<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Callbacks;

/**
 * Types of callbacks supported by TransmitSMS.
 */
enum CallbackType: string
{
    case DLR = 'dlr';
    case REPLY = 'reply';
    case LINK_HITS = 'link_hits';

    /**
     * Get the default path segment for this callback type.
     */
    public function path(): string
    {
        return match ($this) {
            self::DLR => 'dlr',
            self::REPLY => 'reply',
            self::LINK_HITS => 'link-hits',
        };
    }

    /**
     * Get a human-readable label for this callback type.
     */
    public function label(): string
    {
        return match ($this) {
            self::DLR => 'Delivery Receipt',
            self::REPLY => 'Reply',
            self::LINK_HITS => 'Link Hit',
        };
    }
}
