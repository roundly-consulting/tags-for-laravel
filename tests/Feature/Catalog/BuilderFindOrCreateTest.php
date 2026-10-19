<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Actions\Catalog\FindOrCreateTagAction;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\EventTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

beforeEach(function (): void {
    Tags::define(EventTagType::Category)->hierarchical()->single();
});

/**
 * A category seeder, run as often as a host likes.
 *
 * @return array<string, int|string>
 */
function seedCategories(): array
{
    $categories = Tags::type(EventTagType::Category);
    $music = $categories->make('Music')->color('#f24e29')->findOrCreate();
    $jazz = $categories->make('Jazz')->under($music)->icon('sax')->findOrCreate();
    $bebop = $categories->make(['en' => 'Bebop', 'sk' => 'Bebop'])->under($jazz)->description('Fast.')->findOrCreate();

    return ['music' => $music->getKey(), 'jazz' => $jazz->getKey(), 'bebop' => $bebop->getKey()];
}

test('builder_find_or_create_is_idempotent', function (): void {
    $fake = Tags::fake();
    $first = seedCategories();

    $fake->assertTagCreated(fn (Tag $tag): bool => $tag->slug === 'bebop');

    $again = Tags::fake();
    $second = seedCategories();

    expect($second)->toBe($first)
        ->and(Tag::query()->count())->toBe(3);

    $again->assertNoTagCreated();
});

test('builder_find_or_create_never_moves_an_existing_tag', function (): void {
    $categories = Tags::type(EventTagType::Category);
    $music = $categories->make('Music')->create();
    $film = $categories->make('Film')->create();
    $jazz = $categories->make('Jazz')->under($music)->color('#000000')->create();

    $found = $categories->make('Jazz')->under($film)->color('#ffffff')->icon('sax')->position(0)->findOrCreate();

    expect($found->is($jazz))->toBeTrue()
        ->and($found->parent_id)->toBe($music->getKey())
        ->and($found->color)->toBe('#000000')
        ->and($found->icon)->toBeNull()
        ->and($found->wasRecentlyCreated)->toBeFalse();
});

test('builder_find_or_create_creates_under_the_parent', function (): void {
    $categories = Tags::type(EventTagType::Category);
    $music = $categories->make('Music')->create();
    $categories->make('Swing')->under($music)->create();

    $jazz = $categories->make('Jazz')->under($music)->color('#123456')->icon('sax')->description('Improvised.')->position(0)->findOrCreate();

    expect($jazz->wasRecentlyCreated)->toBeTrue()
        ->and($jazz->parent_id)->toBe($music->getKey())
        ->and($jazz->depth)->toBe(2)
        ->and($jazz->position)->toBe(0)
        ->and($jazz->color)->toBe('#123456')
        ->and($jazz->icon)->toBe('sax')
        ->and($jazz->description)->toBe('Improvised.')
        ->and($categories->findOrFail('swing')->position)->toBe(1);

    // The same scope rules as create(): a scoped handle creates in its scope, a deleted parent
    // is refused.
    $team = Team::query()->create(['name' => 'Acme']);
    $ours = Tags::type(EventTagType::Category)->within($team);
    $local = $ours->make('Local')->findOrCreate();
    $stale = $ours->make('Gone')->create();
    $ours->delete($ours->findOrFail('gone'));

    expect($local->scope?->is($team))->toBeTrue()
        ->and(fn () => $ours->make('Child')->under($stale)->findOrCreate())->toThrow(InvalidTagHierarchyException::class);
});

test('builder_find_or_create_matches_the_dto_path', function (): void {
    $categories = Tags::type(EventTagType::Category);
    $music = $categories->make('Music')->create();

    $viaBuilder = $categories->make('Jazz')->under($music)->color('#123456')->findOrCreate();
    $viaHandle = $categories->findOrCreate(new TagData(name: 'Jazz', parent: $music));
    $viaAction = app(FindOrCreateTagAction::class)->execute(EventTagType::Category, new TagData(name: 'jazz', parent: $music));
    $rock = app(FindOrCreateTagAction::class)->execute(EventTagType::Category, new TagData(name: 'Rock', color: '#abcdef'));

    expect($viaHandle->is($viaBuilder))->toBeTrue()
        ->and($viaAction->is($viaBuilder))->toBeTrue()
        ->and($rock->wasRecentlyCreated)->toBeTrue()
        ->and($rock->color)->toBe('#abcdef')
        ->and($rock->isRoot())->toBeTrue();
});
