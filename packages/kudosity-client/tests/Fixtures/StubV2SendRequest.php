<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Requests\KudosityV2BodyRequest;

/**
 * A stand-in for the real V2 requests that arrive in Phase 3.
 *
 * Shared by V2ConnectorTest.php, V2ErrorTest.php and KudosityClientTest.php.
 * Deliberately declared in the **global namespace** rather than
 * `ExpertSystems\Kudosity\Tests` — that's what lets a single class serve both
 * the root Pest suite (also global-namespace) and this suite unchanged, and
 * why it is loaded via the `classmap` entry in composer.json's
 * `autoload-dev` rather than the ordinary PSR-4 rule that reaches
 * {@see \ExpertSystems\Kudosity\Tests\Fixtures\Fixtures} — PSR-4 has no rule
 * that can map a namespace-less class name.
 *
 * Ported from the root Pest suite's tests/Fixtures/StubV2SendRequest.php in
 * Task 7b batch 3, moved once the last root spec depending on it (V2ErrorTest)
 * was itself ported.
 *
 * Extends {@see KudosityV2BodyRequest}, not the plain base, because it's a
 * POST that sends a body — the shape every real V2 write request will use.
 */
class StubV2SendRequest extends KudosityV2BodyRequest
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
