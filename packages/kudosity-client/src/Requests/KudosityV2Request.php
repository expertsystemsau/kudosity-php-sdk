<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use ExpertSystems\Kudosity\Concerns\UnwrapsData;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Abstract base for Kudosity V2 API requests.
 *
 * V2 differs from V1 on every axis of the transport: paths under `/v2/` with
 * no `.json` suffix and a key-only header credential. Endpoints are written
 * out in full by each request — there is no suffix helper to forget to call.
 *
 * Deliberately carries no body: every V2 reader is a GET, and a body on a GET
 * is stripped or rejected by a range of proxies and gateways. Requests that
 * send a body extend {@see KudosityV2BodyRequest} instead.
 *
 * Also carries {@see UnwrapsData} so that concrete requests (Phase 3) can
 * resolve `createDtoFromResponse()` against either V2 envelope shape without
 * reaching into `$response->json()` directly.
 */
abstract class KudosityV2Request extends Request
{
    use UnwrapsData;

    /**
     * Most V2 endpoints are POSTs; readers override this with Method::GET.
     */
    protected Method $method = Method::POST;

    abstract public function resolveEndpoint(): string;
}
