<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Enums;

/**
 * The result code `POST /v2/rcs/capabilities` returns per phone number.
 *
 * Not a boolean: a number can fail the check for reasons that have nothing to
 * do with whether it can receive RCS (`REQUEST_FAILED`, `PROCESSING_ERROR`),
 * as well as reasons that mean it plainly cannot (`REJECTED_NETWORK`,
 * `INVALID_DESTINATION_ADDRESS`). See {@see self::isReachable()} for the
 * one place that collapses this back to a yes/no.
 *
 * Resolution goes through {@see self::fromApi()} rather than a plain
 * `from()`, landing on {@see self::Unknown} for anything undocumented — the
 * same reasoning as {@see MessageStatus}: a client checking capability must
 * not break because Kudosity added a code.
 */
enum RcsCapabilityCode: string
{
    case Enabled = 'ENABLED';
    case Unreachable = 'UNREACHABLE';
    case RejectedNetwork = 'REJECTED_NETWORK';
    case RejectedRouteNotAvailable = 'REJECTED_ROUTE_NOT_AVAILABLE';
    case RequestFailed = 'REQUEST_FAILED';
    case ProcessingError = 'PROCESSING_ERROR';
    case InvalidDestinationAddress = 'INVALID_DESTINATION_ADDRESS';
    case Unknown = 'UNKNOWN';

    /**
     * Resolve a code from the API, tolerating case and novelty.
     */
    public static function fromApi(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($value)) ?? self::Unknown;
    }

    /**
     * Whether it is worth sending RCS to this number from this agent.
     *
     * `Unknown` counts as reachable, deliberately. The skill is explicit that
     * capability checks are best-effort and go stale: an unrecognised or
     * ambiguous result should not become a hard gate that blocks a send the
     * platform would otherwise have accepted. Treat it as reachable, send
     * anyway, and let `sms_fallback` carry whatever does not land — the
     * fallback is what gives a hard delivery guarantee, not this check.
     */
    public function isReachable(): bool
    {
        return $this === self::Enabled || $this === self::Unknown;
    }
}
