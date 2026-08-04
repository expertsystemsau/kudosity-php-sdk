<?php

use ExpertSystems\Kudosity\TransmitSmsClient;

it('can instantiate the TransmitSms client', function () {
    $client = new TransmitSmsClient('api-key', 'api-secret');

    expect($client)->toBeInstanceOf(TransmitSmsClient::class);
});
