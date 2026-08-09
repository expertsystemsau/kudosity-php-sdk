<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Resources;

use ExpertSystems\Kudosity\Data\KeywordData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Pagination\V1PagedPaginator;
use ExpertSystems\Kudosity\Requests\AddKeywordRequest;
use ExpertSystems\Kudosity\Requests\EditKeywordRequest;
use ExpertSystems\Kudosity\Requests\GetKeywordsRequest;

/**
 * Keywords resource for managing keyword campaigns.
 *
 * @see https://developers.kudosity.com
 */
class KeywordsResource extends Resource
{
    /**
     * Add a keyword to a virtual number.
     *
     * @param  string  $keyword  The keyword (e.g., "JOIN")
     * @param  string  $number  The virtual number
     *
     * @throws KudosityException
     */
    public function add(string $keyword, string $number): KeywordData
    {
        $request = new AddKeywordRequest($keyword, $number);

        /** @var KeywordData */
        return $this->sendAndDto($request);
    }

    /**
     * Add a keyword using a custom request.
     *
     * Use this to set additional options.
     *
     * @throws KudosityException
     */
    public function addRequest(AddKeywordRequest $request): KeywordData
    {
        /** @var KeywordData */
        return $this->sendAndDto($request);
    }

    /**
     * Get all keywords (paginated).
     */
    public function all(): V1PagedPaginator
    {
        return $this->connector->paginate(new GetKeywordsRequest);
    }

    /**
     * Get keywords for a specific number (paginated).
     *
     * @param  string  $number  The virtual number to filter by
     */
    public function forNumber(string $number): V1PagedPaginator
    {
        $request = (new GetKeywordsRequest)->number($number);

        return $this->connector->paginate($request);
    }

    /**
     * Get all keywords using a custom request.
     */
    public function allRequest(GetKeywordsRequest $request): V1PagedPaginator
    {
        return $this->connector->paginate($request);
    }

    /**
     * Edit a keyword.
     *
     * Returns a fluent request builder for setting options.
     *
     * @param  string  $keyword  The keyword to edit
     * @param  string  $number  The virtual number
     */
    public function edit(string $keyword, string $number): EditKeywordRequest
    {
        return new EditKeywordRequest($keyword, $number);
    }

    /**
     * Edit a keyword using a custom request.
     *
     * @throws KudosityException
     */
    public function editRequest(EditKeywordRequest $request): bool
    {
        $response = $this->connector->send($request);
        $data = $response->json();

        return ($data['error']['code'] ?? '') === 'SUCCESS';
    }

    /**
     * Activate a keyword.
     *
     * @param  string  $keyword  The keyword
     * @param  string  $number  The virtual number
     *
     * @throws KudosityException
     */
    public function activate(string $keyword, string $number): bool
    {
        $request = (new EditKeywordRequest($keyword, $number))->status('active');

        return $this->editRequest($request);
    }

    /**
     * Deactivate a keyword.
     *
     * @param  string  $keyword  The keyword
     * @param  string  $number  The virtual number
     *
     * @throws KudosityException
     */
    public function deactivate(string $keyword, string $number): bool
    {
        $request = (new EditKeywordRequest($keyword, $number))->status('inactive');

        return $this->editRequest($request);
    }
}
