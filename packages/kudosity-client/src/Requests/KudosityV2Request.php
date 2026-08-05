<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Concerns\UnwrapsData;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Abstract base for Kudosity V2 API requests.
 *
 * V2 differs from V1 on every axis of the transport: a JSON body rather than
 * form-encoded, paths under `/v2/` with no `.json` suffix, and a key-only
 * header credential. Endpoints are written out in full by each request —
 * there is no suffix helper to forget to call.
 *
 * Also carries {@see UnwrapsData} so that concrete requests (Phase 3) can
 * resolve `createDtoFromResponse()` against either V2 envelope shape without
 * reaching into `$response->json()` directly.
 */
abstract class KudosityV2Request extends Request implements HasBody
{
    use HasJsonBody;
    use UnwrapsData;

    /**
     * Most V2 endpoints that carry a body are POSTs; readers override this.
     */
    protected Method $method = Method::POST;

    abstract public function resolveEndpoint(): string;
}
