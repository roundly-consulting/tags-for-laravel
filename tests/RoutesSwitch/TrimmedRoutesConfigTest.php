<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Tags\Support\TagsConfig;

test('a_published_routes_array_without_the_switch_keeps_the_api_off', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'tags.'));

    expect($names)->toHaveCount(0)
        ->and(config('tags.routes.prefix'))->toBe('admin-api')
        ->and(TagsConfig::routesEnabled())->toBeFalse()
        ->and(RateLimiter::limiter('tags-api'))->toBeNull();

    $this->getJson('/admin-api/tag-types')->assertNotFound();
});
