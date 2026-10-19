<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;

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
