<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('client package does not depend on Laravel')
    ->expect('ExpertSystems\Kudosity')
    ->not->toUse(['Illuminate', 'Laravel'])
    ->ignoring('ExpertSystems\Kudosity\Laravel');

arch('laravel package uses the client')
    ->expect('ExpertSystems\Kudosity\Laravel')
    ->toUse('ExpertSystems\Kudosity\TransmitSmsClient');
