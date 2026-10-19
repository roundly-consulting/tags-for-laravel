<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Tags\Tests\Support\KeyTypeScenarios;

test('uuid_installation_works', function (): void {
    KeyTypeScenarios::catalog(static fn (mixed $key): bool => is_string($key) && Str::isUuid($key));
});

it('serves the API on uuid keys', function (): void {
    KeyTypeScenarios::api($this);
});
