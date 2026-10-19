<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\TagsServiceProvider;

test('routes_are_off_by_default', function (): void {
    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'tags.')))->toHaveCount(0)
        ->and(config('tags.routes.enabled'))->toBe('off');

    $this->getJson('/api/tag-types')->assertNotFound();
});

test('blank_env_keeps_routes_off', function (): void {
    Env::getRepository()->set('TAGS_ROUTES', '');

    try {
        $config = require __DIR__.'/../../../config/tags.php';

        expect($config['routes']['enabled'])->toBe('off');

        Env::getRepository()->set('TAGS_ROUTES', 'on');
        $config = require __DIR__.'/../../../config/tags.php';

        expect($config['routes']['enabled'])->toBe('on');
    } finally {
        Env::getRepository()->clear('TAGS_ROUTES');
    }
});

/**
 * Boot the provider again over the current config, as a host app would, and list the API's
 * routes it registered.
 *
 * @return list<string>
 */
function tagsRoutesAfterBoot(): array
{
    $provider = new TagsServiceProvider(app());
    $provider->register();
    $provider->boot();

    return collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'tags.'))->values()->all();
}

test('a_switch_that_is_not_set_keeps_the_api_off', function (string $shape): void {
    match ($shape) {
        'a routes array without the switch' => config()->set('tags.routes', ['prefix' => 'api']),
        'null' => config()->set('tags.routes.enabled', null),
        'a blank env value' => config()->set('tags.routes.enabled', ''),
        'a bare env() with the variable unset' => config()->set('tags.routes.enabled', env('TAGS_ROUTES_NEVER_SET_IN_TESTS')),
    };

    expect(tagsRoutesAfterBoot())->toBe([])
        ->and(TagsConfig::routesEnabled())->toBeFalse()
        ->and(RateLimiter::limiter('tags-api'))->toBeNull();
})->with(['a routes array without the switch', 'null', 'a blank env value', 'a bare env() with the variable unset']);

test('an_explicit_switch_still_turns_the_api_on', function (): void {
    config()->set('tags.routes.enabled', 'on');

    expect(tagsRoutesAfterBoot())->toContain('tags.types.index')
        ->and(TagsConfig::routesEnabled())->toBeTrue()
        ->and(RateLimiter::limiter('tags-api'))->not->toBeNull();
});
