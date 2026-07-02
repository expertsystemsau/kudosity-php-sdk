<?php

declare(strict_types=1);

use ExpertSystems\TransmitSms\Data\SmsData;
use ExpertSystems\TransmitSms\Exceptions\TransmitSmsException;
use ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsChannel;
use ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsMessage;
use ExpertSystems\TransmitSms\Requests\SendSmsRequest;
use ExpertSystems\TransmitSms\Resources\SmsResource;
use ExpertSystems\TransmitSms\TransmitSmsClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

describe('TransmitSmsChannel', function () {
    beforeEach(function () {
        $this->client = Mockery::mock(TransmitSmsClient::class);
        $this->smsResource = Mockery::mock(SmsResource::class);
        $this->channel = new TransmitSmsChannel($this->client);

        $this->client->shouldReceive('sms')
            ->andReturn($this->smsResource);
    });

    describe('send', function () {
        it('sends SMS with TransmitSmsMessage object', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toTransmitSms($notifiable)
                {
                    return (new TransmitSmsMessage('Hello World'))
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
                public function toTransmitSms($notifiable)
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
                public function toTransmitSms($notifiable)
                {
                    return (new TransmitSmsMessage('Test'))
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
                public function toTransmitSms($notifiable)
                {
                    return (new TransmitSmsMessage('List blast'))
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
                public function toTransmitSms($notifiable)
                {
                    return (new TransmitSmsMessage('Test'))
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
                public function toTransmitSms($notifiable)
                {
                    return new TransmitSmsMessage('Test');
                }
            };

            $result = $this->channel->send($notifiable, $notification);

            expect($result)->toBeNull();
        });

        it('uses sender from config when not set on message', function () {
            Config::set('transmitsms.from', 'ConfigBrand');

            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toTransmitSms($notifiable)
                {
                    return new TransmitSmsMessage('Test'); // No from() set
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
                public function toTransmitSms($notifiable)
                {
                    return (new TransmitSmsMessage('Test'))
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
                public function toTransmitSms($notifiable)
                {
                    return (new TransmitSmsMessage('Test'))
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
        it('wraps ValidationException in TransmitSmsException', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toTransmitSms($notifiable)
                {
                    // Create a message with content that will trigger validation
                    // We need to trigger validation in the channel
                    return new TransmitSmsMessage(str_repeat('a', 613));
                }
            };

            expect(fn () => $this->channel->send($notifiable, $notification))
                ->toThrow(TransmitSmsException::class);
        });

        it('propagates TransmitSmsException from client', function () {
            $notifiable = new class
            {
                public function routeNotificationFor($channel, $notification)
                {
                    return '61400000000';
                }
            };

            $notification = new class extends Notification
            {
                public function toTransmitSms($notifiable)
                {
                    return new TransmitSmsMessage('Test');
                }
            };

            $this->smsResource->shouldReceive('sendRequest')
                ->once()
                ->andThrow(new TransmitSmsException('API Error', 400, null, 'INVALID_RECIPIENT'));

            expect(fn () => $this->channel->send($notifiable, $notification))
                ->toThrow(TransmitSmsException::class, 'API Error');
        });
    });
});
