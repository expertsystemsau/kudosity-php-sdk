<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Requests\KudosityV2Request;

/**
 * A stand-in for the real V2 requests that arrive in Phase 3.
 *
 * Shared by V2ConnectorTest.php, V2ErrorTest.php and KudosityClientTest.php.
 * Lives here rather than being declared in one spec and `require_once`'d by
 * the others, which is a pattern that only gets worse as Phase 3 adds SMS,
 * MMS, WhatsApp and RCS specs wanting the same stub — this file is loaded
 * once, up front, by tests/Pest.php.
 */
class StubV2SendRequest extends KudosityV2Request
{
    public function __construct(protected string $message) {}

    public function resolveEndpoint(): string
    {
        return '/v2/sms';
    }

    protected function defaultBody(): array
    {
        return ['message' => $this->message];
    }
}
