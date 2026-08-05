<?php

use ExpertSystems\Kudosity\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Shared test doubles, loaded once regardless of which spec file(s) run —
// see tests/Fixtures/ for why these aren't declared in one spec and
// require_once'd by the others.
require_once __DIR__.'/Fixtures/StubV2SendRequest.php';
