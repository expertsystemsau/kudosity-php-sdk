<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Enums\OptOutSource;
use ExpertSystems\Kudosity\Enums\RcsCapabilityCode;
use ExpertSystems\Kudosity\Enums\SenderRegistrationType;
use ExpertSystems\Kudosity\Enums\SenderStatus;
use ExpertSystems\Kudosity\Enums\SenderVerificationMethod;
use ExpertSystems\Kudosity\Enums\WebhookEventType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every V2 enum is open rather than closed: the upstream docs say these
 * vocabularies will grow, so each resolves through `fromApi()` and lands on
 * `Unknown` for anything undocumented instead of throwing. A client reading
 * its own message history must not break because Kudosity added a value
 * after this release.
 *
 * Task 7b batch 7 ported `V2SendersResourceTest.php`, which duplicated this
 * file's `test_sender_status_verified_does_not_mean_ready_to_use` — the same
 * two facts (`Verified->isReadyToUse()` false, `ReadyToUse->isReadyToUse()`
 * true) fall out of the ported file's "treats READY_TO_USE as sendable and
 * every other state as not, as a full allow-list" test as a strict subset of
 * a full-case sweep, so the dominated original came out. The shared
 * `test_every_tolerant_enum_resolves_an_unknown_value_rather_than_throwing`
 * sweep below stays untouched: `V2RcsTest.php` and `V2SendersResourceTest.php`
 * each duplicate one of its rows in the one fact that a single unrecognised
 * value resolves to Unknown, but pulling a row out of this uniform per-enum
 * table for that would cost more than either fold is worth.
 */
#[CoversClass(MessageStatus::class)]
#[CoversClass(WebhookEventType::class)]
#[CoversClass(OptOutSource::class)]
#[CoversClass(RcsCapabilityCode::class)]
#[CoversClass(SenderStatus::class)]
#[CoversClass(SenderRegistrationType::class)]
#[CoversClass(SenderVerificationMethod::class)]
final class EnumToleranceTest extends TestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function tolerantEnums(): array
    {
        return [
            MessageStatus::class => [MessageStatus::class],
            WebhookEventType::class => [WebhookEventType::class],
            OptOutSource::class => [OptOutSource::class],
            RcsCapabilityCode::class => [RcsCapabilityCode::class],
            SenderStatus::class => [SenderStatus::class],
            SenderRegistrationType::class => [SenderRegistrationType::class],
            SenderVerificationMethod::class => [SenderVerificationMethod::class],
        ];
    }

    /** @param class-string $enum */
    #[DataProvider('tolerantEnums')]
    public function test_every_tolerant_enum_resolves_an_unknown_value_rather_than_throwing(string $enum): void
    {
        // A client reading its own message history must not break because
        // Kudosity added a value after this release.
        $this->assertSame($enum::Unknown, $enum::fromApi('SOMETHING_KUDOSITY_ADDED_LATER'));
    }
}
