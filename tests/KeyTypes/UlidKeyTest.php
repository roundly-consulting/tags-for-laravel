<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Tags\Tests\Support\KeyTypeScenarios;

test('ulid_installation_works', function (): void {
    KeyTypeScenarios::catalog(static fn (mixed $key): bool => is_string($key) && Str::isUlid($key));
});
