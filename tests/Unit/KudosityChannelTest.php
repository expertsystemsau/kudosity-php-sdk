<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Data\V2\SmsMessageData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use ExpertSystems\Kudosity\Resources\BulkSmsResource;
use ExpertSystems\Kudosity\Resources\SmsV2Resource;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

describe('KudosityChannel', function () {
    beforeEach(function () {
        $this->client = Mockery::mock(KudosityClient::class);
        $this->bulkResource = Mockery::mock(BulkSmsResource::class);
        $this->channel = new KudosityChannel($this->client);

        $this->smsResource = Mockery::mock(SmsV2Resource::class);

        $this->client->shouldReceive('bulk')
            ->andReturn($this->bulkResource);

        $this->client->shouldReceive('sms')
            ->andReturn($this->smsResource);
    });

    describe('send', function () {
        it('sends SMS with KudosityMessage object', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Hello World'))
                        ->from('MyBrand')
                        // Pinned to V1: this test covers V1 send mechanics, not the
                        // routing default, which is now V2.
                        ->forceV1();
                }
            };

            $smsData = new SmsData(
                messageId: 123,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->withArgs(function ($request) {
                    return $request instanceof SendSmsRequest;
                })
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });

        it('sends SMS with string message', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return 'Hello from string';
                }
            };

            // A bare string sets no V1-only option, so it routes to V2 — the
            // default since 2.0. This test's point is that a string is accepted
            // at all; the routing assertion is what changed.
            $sent = SmsMessageData::fromArray([
                'id' => '953b88be-5b6f-4b6d-8fcb-3436ec21c0be',
                'recipient' => '61491570006',
                'sender' => 'MyBrand',
                'message' => 'Hello from string',
                'status' => 'queued',
            ]);

            $this->smsResource->shouldReceive('send')
                ->once()
                ->withArgs(fn (string $message, string $to) => $message === 'Hello from string' && $to === '61491570006')
                ->andReturn($sent);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($sent)
                ->and($result->id())->toBe('953b88be-5b6f-4b6d-8fcb-3436ec21c0be')
                ->and($result->recipientCount())->toBe(1);
        });

        it('uses recipient from message if set', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570007'; // Should NOT be used
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))
                        ->to('61491570008') // Should be used
                        ->forceV1();
                }
            };

            $smsData = new SmsData(
                messageId: 789,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });

        it('sends to a list via toList() without a resolved recipient', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return null; // no individual recipient
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('List blast'))
                        ->toList(999);
                }
            };

            $smsData = new SmsData(
                messageId: 555,
                sendAt: '2025-12-06 10:00:00',
                recipients: 10,
                cost: 1.00,
                sms: 10
            );

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->withArgs(function ($request) {
                    $body = $request->body()->all();

                    return $request instanceof SendSmsRequest
                        && ($body['list_id'] ?? null) === 999
                        && ! array_key_exists('to', $body);
                })
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });

        it('applies local number formatting when formatNumbers() is set', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '0491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))
                        ->countryCode('AU')
                        ->formatNumbers()
                        ->forceV1();
                }
            };

            $smsData = new SmsData(
                messageId: 444,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->withArgs(function ($request) {
                    $body = $request->body()->all();

                    // formatNumbers => local formatting applied, countrycode omitted
                    return $request instanceof SendSmsRequest
                        && ($body['to'] ?? null) === '61491570006'
                        && ! array_key_exists('countrycode', $body);
                })
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });

        it('returns null when no recipient available', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return null;
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))->forceV1();
                }
            };

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBeNull();
        });

        it('uses sender from config when not set on message', function () {
            Config::set('kudosity.from', 'ConfigBrand');

            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))->forceV1(); // No from() set
                }
            };

            $smsData = new SmsData(
                messageId: 111,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });

        it('applies scheduled send time', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))
                        ->sendAt('2025-12-25 00:00:00');
                }
            };

            $smsData = new SmsData(
                messageId: 222,
                sendAt: '2025-12-25 00:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });

        it('applies message options', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))
                        ->validity(60)
                        ->countryCode('AU')
                        ->repliesToEmail('test@example.com');
                }
            };

            $smsData = new SmsData(
                messageId: 333,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });
    });

    describe('error handling', function () {
        it('wraps ValidationException in KudosityException', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    // Create a message with content that will trigger validation
                    // We need to trigger validation in the channel
                    return (new KudosityMessage(str_repeat('a', 613)))->forceV1();
                }
            };

            expect(fn () => $this->channel->send($notifiable, $notification))
                ->toThrow(KudosityException::class);
        });

        it('propagates KudosityException from client', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61491570006';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))->forceV1();
                }
            };

            $this->bulkResource->shouldReceive('sendRequest')
                ->once()
                ->andThrow(new KudosityException('API Error', 400, null, 'INVALID_RECIPIENT'));

            expect(fn () => $this->channel->send($notifiable, $notification))
                ->toThrow(KudosityException::class, 'API Error');
        });
    });
});
