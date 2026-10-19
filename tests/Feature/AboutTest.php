<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Tags\Facades\Tags;

/**
 * The `php artisan about` section: shape only (key types, the default type, how many types and
 * their names, the API switch). It reads type names without resolving them, so a broken type
 * definition never breaks `about`.
 */
it('renders its about section', function (): void {
    expect('tags')->toLeakNoSecrets(secrets: [], mustRender: ['Key types', 'Default type', 'Types', 'Routes', 'bigint (tags)', 'OFF']);
});

it('lists the defined types, truncated after five', function (): void {
    foreach (['a1', 'b1', 'c1', 'd1', 'e1', 'f1'] as $name) {
        Tags::define($name);
    }

    Artisan::call('about', ['--only' => 'tags']);

    expect(Artisan::output())->toMatch('/Types\W+7 \(a1, b1, c1, d1, e1, …\)/');
});

it('says when there is no default type and the API is on', function (): void {
    config()->set('tags.default_type', '');
    config()->set('tags.routes.enabled', 'on');

    Artisan::call('about', ['--only' => 'tags']);

    expect(Artisan::output())->toMatch('/Default type\W+NONE/')->toMatch('/Routes\W+ON/')->toMatch('/Types\W+0\b/');
});

it('never resolves a type, so a broken one does not break about', function (): void {
    Tags::define('broken')->max(0);

    Artisan::call('about', ['--only' => 'tags']);

    expect(Artisan::output())->toContain('broken');
});
