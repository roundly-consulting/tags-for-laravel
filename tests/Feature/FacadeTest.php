<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Testing\TagsFake;
use RoundlyConsulting\Tags\Types\TagTypeBuilder;

/**
 * The public API contract — Actions → Manager → Facade (+ fake).
 */
it('pins the facade contract', function (): void {
    expect(Tags::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('resolves one manager from the container', function (): void {
    $manager = app(TagsManager::class);

    expect($manager)->toBe(app(TagsManager::class))
        ->and($manager->define('genre'))->toBeInstanceOf(TagTypeBuilder::class)
        ->and($manager->types()->keys()->all())->toBe(['genre', 'tag']);
});

test('fake_keeps_types_defined_before_it', function (): void {
    Tags::define('genre')->hierarchical();

    $fake = Tags::fake();

    expect($fake)->toBeInstanceOf(TagsFake::class)
        ->and(app(TagsManager::class))->toBe($fake)
        ->and(Tags::types()->keys()->all())->toBe(['genre', 'tag'])
        ->and(Tags::type('genre')->definition()->hierarchical)->toBeTrue();
});

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $alias = (string) array_key_first($aliases);
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe([class_basename(Tags::class) => Tags::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\'.$alias))->toBeFalse()
        ->and($coreAliases)->not->toContain(strtolower($alias));
});
