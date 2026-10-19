<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\TagLock;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

test('scope_key_tracks_the_scope_morph', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);

    $global = Tag::factory()->create();
    $scoped = Tag::factory()->within($team)->create();
    $handWritten = TagModel::make()->forceFill([
        'type' => 'tag',
        'name' => ['en' => 'Hand'],
        'scope_type' => $team->getMorphClass(),
        'scope_id' => $team->getKey(),
        'scope_key' => 'tampered',
    ]);
    $handWritten->save();

    expect($global->scope_key)->toBe('')
        ->and($scoped->scope_key)->toBe($team->getMorphClass().'#'.$team->getKey())
        ->and($handWritten->scope_key)->toBe($scoped->scope_key)
        ->and($scoped->scope?->is($team))->toBeTrue()
        ->and($scoped->toArray())->not->toHaveKeys(['scope_type', 'scope_key']);
});

test('type_and_scope_are_immutable', function (string $attribute, Closure $value): void {
    $tag = Tag::factory()->create();

    $tag->setAttribute($attribute, $value());

    expect(fn () => $tag->save())->toThrow(InvalidTagException::class, $attribute);
})->with([
    'type' => ['type', fn () => 'other'],
    'scope type' => ['scope_type', fn () => 'team'],
    'scope id' => ['scope_id', fn () => 5],
]);

test('slug_options_tolerate_a_blank_model', function (): void {
    Tags::define('genre')->hierarchical()->stableSlugs();

    $blank = new Tag;
    $gone = new Tag(['type' => 'gone']);
    $stable = new Tag(['type' => 'genre']);

    expect($blank->slugOptions()->get('slug'))->not->toBeNull()
        ->and($blank->slugDefinition()->onUpdate)->toBe(UpdatePolicy::WhenSourceChanges)
        ->and($gone->slugDefinition()->onUpdate)->toBe(UpdatePolicy::WhenSourceChanges)
        ->and($stable->slugDefinition()->onUpdate)->toBe(UpdatePolicy::IfEmpty)
        ->and(Tag::query()->whereSlug('rock')->get())->toHaveCount(0)
        ->and(SlugIndexes::specsFor(Tag::class))->toHaveCount(1)
        ->and(SlugIndexes::specsFor(Tag::class)[0]->scope)->toBe(['type', 'scope_key']);
});

test('global_uniqueness_is_index_enforced', function (): void {
    $row = static fn (): array => [
        'type' => 'tag',
        'scope_key' => '',
        'path' => '/',
        'depth' => 1,
        'position' => 0,
        'usage_count' => 0,
        'name' => '{"en":"Rock"}',
        'slug' => '{"en":"rock"}',
    ];

    TagModel::query()->toBase()->insert($row());

    // A second global "rock" of the same type, inserted past sluggable: the engine refuses it,
    // although scope_type and scope_id are NULL (scope_key binds them).
    expect(fn () => TagModel::query()->toBase()->insert($row()))->toThrow(UniqueConstraintViolationException::class);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'raw rows need a key on uuid installs');

it('builds trees through the factory states', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $music = Tag::factory()->ofType('genre')->within($team)->named(['en' => 'Music', 'sk' => 'Hudba'])->create();
    $jazz = Tag::factory()->under($music)->named('Jazz')->create();

    expect($jazz->type)->toBe('genre')
        ->and($jazz->scope_key)->toBe(ScopeKey::for($team))
        ->and($jazz->path)->toBe('/'.$music->getKey().'/')
        ->and($jazz->depth)->toBe(2)
        ->and($jazz->parent?->is($music))->toBeTrue()
        ->and($music->children->pluck('name')->all())->toBe(['Jazz'])
        ->and($music->getTranslation('name', 'sk'))->toBe('Hudba')
        ->and($music->slugFor('sk'))->toBe('hudba')
        ->and($jazz->isRoot())->toBeFalse()
        ->and($music->isRoot())->toBeTrue();
});

it('keeps lock rows on their own table', function (): void {
    $lock = TagLock::query()->create(['type' => 'genre', 'scope_key' => '']);

    expect($lock->getTable())->toBe('tag_locks')->and(TagLock::query()->count())->toBe(1);
});
