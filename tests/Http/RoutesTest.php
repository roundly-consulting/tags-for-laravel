<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('registers exactly the documented routes', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'tags.'))
        ->map(fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri().' '.$route->getName())
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe([
        'DELETE api/tags/{type}/{tag} tags.destroy',
        'GET api/tag-types tags.types.index',
        'GET api/tag-types/{type} tags.types.show',
        'GET api/tags/{type} tags.index',
        'GET api/tags/{type}/popular tags.popular',
        'GET api/tags/{type}/suggest tags.suggest',
        'GET api/tags/{type}/{tag} tags.show',
        'PATCH api/tags/{type}/{tag} tags.update',
        'POST api/tags/{type} tags.store',
        'POST api/tags/{type}/reorder tags.reorder',
        'POST api/tags/{type}/{tag}/move tags.move',
        'POST api/tags/{type}/{tag}/restore tags.restore',
    ]);
});

it('guards every route with the configured middleware', function (): void {
    $this->getJson('/api/tag-types')->assertUnauthorized();
});
