<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Laravel\Notifications;

/**
 * Which Kudosity API a notification will be sent over.
 *
 * An enum rather than a bool or a string so the SMS channel's routing decision
 * reads as a decision at the call site — `apiVersion() === ApiVersion::V1` says
 * something a `true` cannot.
 */
enum ApiVersion: string
{
    case V1 = 'v1';
    case V2 = 'v2';
}
