<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\BalanceData;
use ExpertSystems\Kudosity\Data\ContactData;
use ExpertSystems\Kudosity\Data\ListData;
use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Data\SmsStatsData;

describe('DTO transformations', function () {
    describe('BalanceData', function () {
        it('creates from API response', function () {
            $data = [
                'balance' => 150.50,
                'currency' => 'AUD',
            ];

            $dto = BalanceData::fromResponse($data);

            expect($dto->balance)->toBe(150.50);
            expect($dto->currency)->toBe('AUD');
        });

        it('accepts different currencies', function () {
            $data = ['balance' => 100.00, 'currency' => 'USD'];

            $dto = BalanceData::fromResponse($data);

            expect($dto->balance)->toBe(100.00);
            expect($dto->currency)->toBe('USD');
        });

        it('coerces string balance to float', function () {
            $data = ['balance' => '75.25', 'currency' => 'USD'];

            $dto = BalanceData::fromResponse($data);

            expect($dto->balance)->toBe(75.25);
        });
    });

    describe('SmsData', function () {
        it('creates from send-sms response', function () {
            $data = [
                'message_id' => 12345,
                'send_at' => '2024-01-15 10:30:00',
                'recipients' => 5,
                'cost' => 0.25,
                'sms' => 1,
            ];

            $dto = SmsData::fromResponse($data);

            expect($dto->messageId)->toBe(12345);
            expect($dto->sendAt)->toBe('2024-01-15 10:30:00');
            expect($dto->recipients)->toBe(5);
            expect($dto->cost)->toBe(0.25);
            expect($dto->sms)->toBe(1);
            expect($dto->list)->toBeNull();
        });

        it('includes list data when present', function () {
            $data = [
                'message_id' => 12345,
                'send_at' => '2024-01-15 10:30:00',
                'recipients' => 100,
                'cost' => 5.00,
                'sms' => 1,
                'list' => [
                    'id' => 999,
                    'name' => 'Test List',
                ],
            ];

            $dto = SmsData::fromResponse($data);

            expect($dto->list)->not->toBeNull();
            expect($dto->list->id)->toBe(999);
            expect($dto->list->name)->toBe('Test List');
        });
    });

    describe('ContactData', function () {
        it('creates from contact response', function () {
            $data = [
                'contact' => [
                    'mobile' => '61400000000',
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                    'status' => 'active',
                    'date_added' => '2024-01-01 00:00:00',
                ],
            ];

            $dto = ContactData::fromResponse($data);

            expect($dto->mobile)->toBe('61400000000');
            expect($dto->firstName)->toBe('John');
            expect($dto->lastName)->toBe('Doe');
            expect($dto->status)->toBe('active');
            expect($dto->isActive())->toBeTrue();
            expect($dto->isOptedOut())->toBeFalse();
        });

        it('extracts custom fields', function () {
            $data = [
                'mobile' => '61400000000',
                'firstname' => '',
                'lastname' => '',
                'status' => 'active',
                'field_1' => 'Company ABC',
                'field_2' => 'Manager',
                'field_3' => '',
            ];

            $dto = ContactData::fromResponse($data);

            expect($dto->customFields)->toHaveKey('field_1');
            expect($dto->customFields['field_1'])->toBe('Company ABC');
            expect($dto->customFields)->toHaveKey('field_2');
            expect($dto->customFields)->not->toHaveKey('field_3'); // Empty values excluded
        });

        it('handles msisdn field alias', function () {
            $data = [
                'msisdn' => '61400000000',
                'firstname' => 'Jane',
                'lastname' => 'Smith',
                'status' => 'optout',
            ];

            $dto = ContactData::fromResponse($data);

            expect($dto->mobile)->toBe('61400000000');
            expect($dto->isOptedOut())->toBeTrue();
        });

        it('throws exception when mobile is missing', function () {
            $data = [
                'firstname' => 'John',
                'lastname' => 'Doe',
                'status' => 'active',
            ];

            expect(fn () => ContactData::fromResponse($data))
                ->toThrow(InvalidArgumentException::class, 'valid mobile number');
        });

        it('throws exception when mobile is empty', function () {
            $data = [
                'mobile' => '',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'status' => 'active',
            ];

            expect(fn () => ContactData::fromResponse($data))
                ->toThrow(InvalidArgumentException::class, 'valid mobile number');
        });
    });

    describe('ListData', function () {
        it('creates from list response', function () {
            $data = [
                'list' => [
                    'id' => 123,
                    'name' => 'My Contacts',
                    'members' => 500,
                    'field_1' => 'Company',
                    'field_2' => 'Department',
                ],
            ];

            $dto = ListData::fromResponse($data);

            expect($dto->id)->toBe(123);
            expect($dto->name)->toBe('My Contacts');
            expect($dto->members)->toBe(500);
            expect($dto->fields)->toHaveKey('field_1');
            expect($dto->fields['field_1'])->toBe('Company');
        });

        it('handles list_id alias', function () {
            $data = [
                'list_id' => 456,
                'name' => 'Test',
                'members' => 0,
            ];

            $dto = ListData::fromResponse($data);

            expect($dto->id)->toBe(456);
        });
    });

    describe('SmsStatsData', function () {
        it('creates from stats response', function () {
            $data = [
                'stats' => [
                    'sent' => 100,
                    'delivered' => 95,
                    'pending' => 3,
                    'bounced' => 2,
                    'responses' => 10,
                    'optouts' => 1,
                ],
            ];

            $dto = SmsStatsData::fromResponse($data);

            expect($dto->sent)->toBe(100);
            expect($dto->delivered)->toBe(95);
            expect($dto->pending)->toBe(3);
            expect($dto->bounced)->toBe(2);
            expect($dto->responses)->toBe(10);
            expect($dto->optouts)->toBe(1);
        });

        it('calculates delivery rate', function () {
            $data = [
                'sent' => 100,
                'delivered' => 80,
                'pending' => 10,
                'bounced' => 10,
                'responses' => 5,
                'optouts' => 0,
            ];

            $dto = SmsStatsData::fromResponse($data);

            expect($dto->getDeliveryRate())->toBe(80.0);
            expect($dto->getBounceRate())->toBe(10.0);
            expect($dto->getResponseRate())->toBe(5.0);
        });

        it('handles zero sent for rate calculations', function () {
            $data = [
                'sent' => 0,
                'delivered' => 0,
                'pending' => 0,
                'bounced' => 0,
                'responses' => 0,
                'optouts' => 0,
            ];

            $dto = SmsStatsData::fromResponse($data);

            expect($dto->getDeliveryRate())->toBe(0.0);
            expect($dto->getBounceRate())->toBe(0.0);
        });
    });
});
