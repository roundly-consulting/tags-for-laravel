<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Tests\Fixtures\HttpTestCase;
use RoundlyConsulting\Tags\Tests\Fixtures\SwappedTagTestCase;
use RoundlyConsulting\Tags\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Tags\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Tags\Tests\TestCase;

// Explicit directories, never a blanket `->in(__DIR__)`: Pest binds one test case per directory.
uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'RealEngine');

// The JSON API: its routes load at boot, so the switch is on before the providers boot.
uses(HttpTestCase::class)->in('Http');

// The model-swap proofs need tags.models.tag pointed at a host subclass before boot.
uses(SwappedTagTestCase::class)->in('ModelSwap');

// Key-type legs: the migrations read the key types, so each leg sets them before boot.
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');

require_once __DIR__.'/Http/Helpers.php';
