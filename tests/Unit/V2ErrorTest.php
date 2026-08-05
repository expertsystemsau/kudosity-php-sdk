<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Exceptions\AccessDeniedException;
use ExpertSystems\Kudosity\Exceptions\AuthenticationException;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\RateLimitException;
use ExpertSystems\Kudosity\Exceptions\ServerException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// StubV2SendRequest is a shared fixture, loaded once by tests/Pest.php —
// see tests/Fixtures/StubV2SendRequest.php.

/**
 * The RFC 9457 shape the V2 messaging endpoints return, as documented in
 * .agents/skills/kudosity-rcs/SKILL.md.
 */
function problemBody(int $status, array $issues = []): array
{
    return ['error' => array_filter([
        'type' => 'https://developers.kudosity.com/reference/errors#input-validation',
        'title' => 'Invalid Request',
        'detail' => 'Request validation failed',
        'status' => $status,
        'issues' => $issues,
    ])];
}

function v2Exception(int $status, array $body): KudosityException
{
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make($body, $status)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $response = $connector->send(new StubV2SendRequest('hi'));

    return KudosityException::fromV2Response($response);
}

it('maps every documented status to its exception class', function (int $status, string $class) {
    expect(v2Exception($status, problemBody($status)))->toBeInstanceOf($class);
})->with([
    'validation (registry says 422)' => [422, ValidationException::class],
    'validation (endpoint docs say 400)' => [400, ValidationException::class],
    'unauthorized' => [401, AuthenticationException::class],
    'forbidden' => [403, AccessDeniedException::class],
    'not found' => [404, NotFoundException::class],
    'rate limited' => [429, RateLimitException::class],
    'server error' => [500, ServerException::class],
    'bad gateway' => [502, ServerException::class],
]);

it('extracts every failed field from issues[] at once', function () {
    $e = v2Exception(400, problemBody(400, [
        ['name' => 'sender', 'message' => 'sender is required'],
        ['name' => 'recipient', 'message' => 'recipient must be E.164'],
    ]));

    $issues = $e->getIssues();

    expect($issues)->toHaveCount(2)
        ->and($issues[0]->name)->toBe('sender')
        ->and($issues[0]->message)->toBe('sender is required')
        ->and($issues[1]->name)->toBe('recipient')
        ->and($e->getMessage())->toContain('sender is required')
        ->and($e->getMessage())->toContain('recipient must be E.164');
});

it('exposes the problem type URI and the HTTP status as the code', function () {
    $e = v2Exception(422, problemBody(422));

    expect($e->getProblemType())->toBe('https://developers.kudosity.com/reference/errors#input-validation')
        ->and($e->getCode())->toBe(422);
});

it('falls back to detail then title when there are no issues', function () {
    expect(v2Exception(500, ['error' => ['detail' => 'boom', 'status' => 500]])->getMessage())->toBe('boom')
        ->and(v2Exception(500, ['error' => ['title' => 'Server Error', 'status' => 500]])->getMessage())
        ->toBe('Server Error');
});

it('handles the plain-string error shape the webhook endpoints use', function () {
    $e = v2Exception(404, ['error' => 'SMS not found']);

    expect($e)->toBeInstanceOf(NotFoundException::class)
        ->and($e->getMessage())->toBe('SMS not found')
        ->and($e->getIssues())->toBe([]);
});

it('produces a useful message when the body carries no error at all', function () {
    $e = v2Exception(503, []);

    expect($e)->toBeInstanceOf(ServerException::class)
        ->and($e->getMessage())->toContain('503');
});

it('reports no issues for a V1 exception', function () {
    $mock = new MockClient([
        StubV2SendRequest::class => MockResponse::make(
            ['error' => ['code' => 'FIELD_EMPTY', 'description' => 'Required field is empty.']],
            400
        ),
    ]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    expect(KudosityException::fromV1Response($connector->send(new StubV2SendRequest('hi')))->getIssues())
        ->toBe([]);
});

it('treats a 201 as success, not a failure', function () {
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(['id' => 'abc'], 201)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $response = $connector->send(new StubV2SendRequest('hi'));

    expect($response->failed())->toBeFalse()
        ->and($connector->hasRequestFailed($response))->not->toBeTrue();
});

it('throws the mapped exception through Saloon throw()', function () {
    $mock = new MockClient([StubV2SendRequest::class => MockResponse::make(problemBody(404), 404)]);
    $connector = new KudosityV2Connector('key');
    $connector->withMockClient($mock);

    $connector->send(new StubV2SendRequest('hi'))->throw();
})->throws(NotFoundException::class);
