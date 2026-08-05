<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Abstract base for V2 requests that send a JSON body.
 *
 * Split from {@see KudosityV2Request} so a GET reader never inherits a body:
 * every V2 list and read endpoint is a GET, and a JSON-typed body on a GET —
 * even an empty `[]` — is the kind of thing a proxy or gateway can strip or
 * reject. Endpoints that write (sends, webhook registration, ...) extend
 * this class instead of the base.
 */
abstract class KudosityV2BodyRequest extends KudosityV2Request implements HasBody
{
    use HasJsonBody;
}
