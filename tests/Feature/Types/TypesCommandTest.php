<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Tests\Fixtures\FixtureTypesServiceProvider;
use RoundlyConsulting\Tags\Tests\Fixtures\HostExtendServiceProvider;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

test('types_command_lists_sources_and_overrides', function (): void {
    config()->set('tags.hierarchy.max_depth', 2);
    config()->set('tags.types', ['skill' => ['global_only' => true]]);
    config()->set('tags.overrides', ['fixture:label' => ['private' => true]]);
    $this->app->register(FixtureTypesServiceProvider::class);
    $this->app->register(HostExtendServiceProvider::class);

    Artisan::call('tags:types');
    $output = Artisan::output();

    expect($output)
        ->toContain('fixture:category')
        ->toContain('tree, depth 2 (clamped from 3)')
        ->toContain('FixtureTypesServiceProvider:')
        ->toContain('extend #1, config overrides')
        ->toContain('config/tags.php')
        ->toContain('global only')
        ->toContain('private')
        ->toContain('stable')
        ->toContain('curated')
        ->toContain('free-form')
        ->toContain(Article::class)
        ->toContain('default');
});

it('prints the types as JSON', function (): void {
    Tags::define('genre')->hierarchical()->single();

    Artisan::call('tags:types', ['--json' => true]);

    /** @var list<array<string, mixed>> $types */
    $types = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($types, 'name'))->toBe(['genre', 'tag'])
        ->and($types[0]['max'])->toBe('1')
        ->and($types[0]['hierarchy'])->toBe('tree, depth 5')
        ->and($types[0]['models'])->toBe([])
        ->and($types[1]['source'])->toBe('default')
        ->and($types[1]['label'])->toBe('Tag');
});

it('says when no type is defined', function (): void {
    config()->set('tags.default_type', '');

    $this->artisan('tags:types')->expectsOutputToContain('No tag types are defined.')->assertSuccessful();
});
