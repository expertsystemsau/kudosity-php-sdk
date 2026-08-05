<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Pagination\V2PagedPaginator;

/**
 * Marks a V2 request as using page-number pagination.
 *
 * `GET /v2/sms` takes `page` and `limit` and reports `total_records`.
 *
 * @see V2PagedPaginator
 */
interface PaginatesV2Pages extends PaginatesResults {}
