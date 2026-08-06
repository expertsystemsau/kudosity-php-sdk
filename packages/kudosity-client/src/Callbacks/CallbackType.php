<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Callbacks;

/**
 * Types of callbacks supported by Kudosity.
 */
enum CallbackType: string
{
    case DLR = 'dlr';
    case REPLY = 'reply';
    case LINK_HITS = 'link_hits';

    /**
     * The V2 account-level events receiver.
     *
     * Not a per-send callback like the other three — V2 has no per-send callback
     * URL. This exists so the V2 receiver's URL is signed by the same machinery,
     * rather than assembled by hand at the call site.
     */
    case EVENTS = 'events';

    /**
     * Get the default path segment for this callback type.
     */
    public function path(): string
    {
        return match ($this) {
            self::DLR => 'dlr',
            self::REPLY => 'reply',
            self::LINK_HITS => 'link-hits',
            self::EVENTS => 'events',
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
            self::EVENTS => 'V2 Events',
        };
    }
}
