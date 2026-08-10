<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityV1Connector;
use ExpertSystems\Kudosity\Requests\AddEmailRequest;
use ExpertSystems\Kudosity\Requests\AddKeywordRequest;
use ExpertSystems\Kudosity\Requests\AddListRequest;
use ExpertSystems\Kudosity\Requests\GetBalanceRequest;
use ExpertSystems\Kudosity\Requests\LeaseNumberRequest;
use ExpertSystems\Kudosity\Resources\AccountResource;
use ExpertSystems\Kudosity\Resources\EmailSmsResource;
use ExpertSystems\Kudosity\Resources\KeywordsResource;
use ExpertSystems\Kudosity\Resources\ListsResource;
use ExpertSystems\Kudosity\Resources\NumbersResource;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * ReportingResourceTest.php found that ReportingResource's dtoOrFail() calls
 * wrapped a real KudosityException in Saloon's generic LogicException on any
 * failed V1 response — a caller's documented `catch (KudosityException $e)`
 * did not catch it. The team lead confirmed the identical pattern (a direct
 * `$this->connector->send($request)->dtoOrFail()` instead of
 * `Resource::sendAndDto()`) exists 18 more times across five other
 * resources, none of it previously exercised against a failing response —
 * each resource's existing tests only ever mock success. Fixing one
 * resource's exception contract and leaving five others silently different
 * would be worse than the original uniform bug: a consumer's
 * `catch (KudosityException $e)` would work for reporting and silently fail
 * for lists, which is harder to discover than a bug that behaves the same
 * way everywhere.
 *
 * The change is mechanical and identical at every site (dtoOrFail() ->
 * sendAndDto()), so one representative site per resource is enough to prove
 * the pattern and its fix without duplicating 18 near-identical tests.
 */
#[CoversClass(KeywordsResource::class)]
#[CoversClass(AccountResource::class)]
#[CoversClass(NumbersResource::class)]
#[CoversClass(ListsResource::class)]
#[CoversClass(EmailSmsResource::class)]
final class DtoOrFailRoutingTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string, 2: callable}>
     */
    public static function resourcesUsingDtoOrFail(): array
    {
        return [
            'KeywordsResource::add()' => [
                KeywordsResource::class,
                AddKeywordRequest::class,
                static fn (KeywordsResource $r) => $r->add('JOIN', '61491570006'),
            ],
            'AccountResource::getBalance()' => [
                AccountResource::class,
                GetBalanceRequest::class,
                static fn (AccountResource $r) => $r->getBalance(),
            ],
            'NumbersResource::lease()' => [
                NumbersResource::class,
                LeaseNumberRequest::class,
                static fn (NumbersResource $r) => $r->lease('61491570006'),
            ],
            'ListsResource::create()' => [
                ListsResource::class,
                AddListRequest::class,
                static fn (ListsResource $r) => $r->create('Test list'),
            ],
            'EmailSmsResource::add()' => [
                EmailSmsResource::class,
                AddEmailRequest::class,
                static fn (EmailSmsResource $r) => $r->add('test@example.com'),
            ],
        ];
    }

    /**
     * @param  class-string  $resourceClass
     * @param  class-string  $requestClass
     */
    #[DataProvider('resourcesUsingDtoOrFail')]
    public function test_throws_kudosity_exception_not_logic_exception_on_v1_error(
        string $resourceClass,
        string $requestClass,
        callable $invoke,
    ): void {
        $connector = new KudosityV1Connector('key', 'secret');
        $connector->withMockClient(new MockClient([
            $requestClass => MockResponse::make([
                'error' => ['code' => 'FIELD_INVALID', 'description' => 'Test error for routing verification'],
            ], 400),
        ]));

        $resource = new $resourceClass($connector);

        try {
            $invoke($resource);
            $this->fail("Expected a KudosityException from {$resourceClass}.");
        } catch (LogicException $e) {
            $this->fail("{$resourceClass} threw Saloon's LogicException instead of a KudosityException: ".$e->getMessage());
        } catch (KudosityException $e) {
            $this->assertSame('Test error for routing verification', $e->getMessage());
            $this->assertSame('FIELD_INVALID', $e->getErrorCode());
        }
    }
}
