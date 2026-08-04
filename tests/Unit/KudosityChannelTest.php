<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Data\SmsData;
use ExpertSystems\Kudosity\Exceptions\KudosityException;
use ExpertSystems\Kudosity\KudosityClient;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityChannel;
use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;
use ExpertSystems\Kudosity\Requests\SendSmsRequest;
use ExpertSystems\Kudosity\Resources\SmsResource;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

describe('KudosityChannel', function () {
    beforeEach(function () {
        $this->client = Mockery::mock(KudosityClient::class);
        $this->smsResource = Mockery::mock(SmsResource::class);
        $this->channel = new KudosityChannel($this->client);

        $this->client->shouldReceive('sms')
            ->andReturn($this->smsResource);
    });

    describe('send', function () {
        it('sends SMS with KudosityMessage object', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Hello World'))
                        ->from('MyBrand');
                }
            };

            $smsData = new SmsData(
                messageId: 123,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->smsResource->shouldReceive('sendRequest')
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
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return 'Hello from string';
                }
            };

            $smsData = new SmsData(
                messageId: 456,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->smsResource->shouldReceive('sendRequest')
                ->once()
                ->andReturn($smsData);

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBe($smsData);
        });

        it('uses recipient from message if set', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61400000001'; // Should NOT be used
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))
                        ->to('61400000002'); // Should be used
                }
            };

            $smsData = new SmsData(
                messageId: 789,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->smsResource->shouldReceive('sendRequest')
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

            $this->smsResource->shouldReceive('sendRequest')
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
                    return '0400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return (new KudosityMessage('Test'))
                        ->countryCode('AU')
                        ->formatNumbers();
                }
            };

            $smsData = new SmsData(
                messageId: 444,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->smsResource->shouldReceive('sendRequest')
                ->once()
                ->withArgs(function ($request) {
                    $body = $request->body()->all();

                    // formatNumbers => local formatting applied, countrycode omitted
                    return $request instanceof SendSmsRequest
                        && ($body['to'] ?? null) === '61400000000'
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
                    return new KudosityMessage('Test');
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
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return new KudosityMessage('Test'); // No from() set
                }
            };

            $smsData = new SmsData(
                messageId: 111,
                sendAt: '2025-12-06 10:00:00',
                recipients: 1,
                cost: 0.10,
                sms: 1
            );

            $this->smsResource->shouldReceive('sendRequest')
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
                    return '61400000000';
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

            $this->smsResource->shouldReceive('sendRequest')
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
                    return '61400000000';
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

            $this->smsResource->shouldReceive('sendRequest')
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
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    // Create a message with content that will trigger validation
                    // We need to trigger validation in the channel
                    return new KudosityMessage(str_repeat('a', 613));
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
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toKudosity($notifiable)
                {
                    return new KudosityMessage('Test');
                }
            };

            $this->smsResource->shouldReceive('sendRequest')
                ->once()
                ->andThrow(new KudosityException('API Error', 400, null, 'INVALID_RECIPIENT'));

            expect(fn () => $this->channel->send($notifiable, $notification))
                ->toThrow(KudosityException::class, 'API Error');
        });
    });
});
