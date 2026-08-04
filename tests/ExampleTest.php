<?php

use ExpertSystems\Kudosity\KudosityClient;

it('can instantiate the Kudosity client', function () {
    $client = new KudosityClient('api-key', 'api-secret');

    expect($client)->toBeInstanceOf(KudosityClient::class);
});
