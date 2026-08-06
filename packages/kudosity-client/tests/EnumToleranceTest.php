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

    public function test_sender_status_verified_does_not_mean_ready_to_use(): void
    {
        // VERIFIED means *provisioning*. Only READY_TO_USE can send, and treating
        // VERIFIED as usable produces sends that fail at the API.
        $this->assertFalse(SenderStatus::Verified->isReadyToUse());
        $this->assertTrue(SenderStatus::ReadyToUse->isReadyToUse());
    }
}
