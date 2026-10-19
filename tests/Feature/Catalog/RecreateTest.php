<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

/**
 * A deleted tag's name stays reserved; using it again brings the tag back. Only `create()` applies
 * new attributes to it; a find-or-create or a free-form name restores it as it was, in its place.
 */
beforeEach(function (): void {
    Tags::define('genre')->hierarchical(maxDepth: 3);
    Tags::define('topic')->hierarchical()->freeForm();
    $this->genres = Tags::type('genre');
    $this->music = $this->genres->make('Music')->create();
    $this->jazz = $this->genres->make(['en' => 'Jazz', 'sk' => 'Džez'])->under($this->music)
        ->color('#112233')->icon('sax')->description('Swing and more.')->create();
});

function stored(Tag $tag): array
{
    $fresh = Tag::query()->withTrashed()->findOrFail($tag->getKey());

    return [
        'trashed' => $fresh->trashed(),
        'parent' => $fresh->parent_id,
        'path' => $fresh->path,
        'depth' => $fresh->depth,
        'color' => $fresh->color,
        'icon' => $fresh->icon,
        'names' => $fresh->getTranslations('name'),
        'description' => $fresh->getTranslations('description'),
        'slugs' => $fresh->getTranslations('slug'),
    ];
}

test('find_or_create_restores_a_deleted_tag_as_it_was', function (string $via): void {
    $before = stored($this->jazz);
    $this->genres->delete($this->jazz);

    $again = match ($via) {
        'a name' => $this->genres->findOrCreate('jazz'),
        'a locale map' => $this->genres->findOrCreate(['en' => 'JAZZ', 'sk' => 'Iné']),
        'the builder' => $this->genres->make('Jazz')->color('#ffffff')->icon('drum')->description('New.')->findOrCreate(),
    };

    expect($again->is($this->jazz))->toBeTrue()
        ->and(stored($this->jazz))->toBe($before);
})->with(['a name', 'a locale map', 'the builder']);

test('free_form_attach_restores_a_deleted_tag_as_it_was', function (): void {
    $rock = Tags::type('tag')->make(['en' => 'Rock', 'sk' => 'Rock SK'])->color('#ff0000')->create();
    $before = stored($rock);
    Tags::type('tag')->delete($rock);

    Article::query()->create()->attachTags(['rock']);
    Article::query()->create()->syncTags(['ROCK!']);

    expect(stored($rock))->toBe($before)
        ->and(Tag::query()->withTrashed()->where('type', 'tag')->count())->toBe(1);
});

test('a_free_form_attach_restores_a_deleted_child_under_its_live_parent', function (): void {
    $topics = Tags::type('topic');
    $science = $topics->make('Science')->create();
    $physics = $topics->make('Physics')->under($science)->create();
    $topics->delete($physics);

    Article::query()->create()->attachTags('Physics', 'topic');

    expect(stored($physics))->toMatchArray([
        'trashed' => false,
        'parent' => $science->getKey(),
        'path' => '/'.$science->getKey().'/',
        'depth' => 2,
    ]);
});

test('find_or_create_with_a_parent_restores_it_under_that_parent', function (): void {
    $rock = $this->genres->make('Rock')->create();
    $this->genres->delete($this->jazz);

    $again = $this->genres->make('Jazz')->under($rock)->color('#ffffff')->findOrCreate();

    expect($again->is($this->jazz))->toBeTrue()
        ->and(stored($this->jazz))->toMatchArray(['parent' => $rock->getKey(), 'path' => '/'.$rock->getKey().'/', 'color' => '#112233']);
});

test('create_applies_its_attributes_and_moves_only_when_told', function (): void {
    $this->genres->delete($this->jazz);

    $created = $this->genres->create(new TagData(name: 'Jazz', color: '#ffffff'));

    expect($created->is($this->jazz))->toBeTrue()
        ->and(stored($this->jazz))->toMatchArray([
            'parent' => $this->music->getKey(),
            'depth' => 2,
            'color' => '#ffffff',
            'icon' => null,
            'names' => ['en' => 'Jazz'],
            'description' => [],
        ]);

    $this->genres->delete($this->jazz->fresh() ?? $this->jazz);
    $rooted = $this->genres->make('Jazz')->under(null)->create();

    expect($rooted->is($this->jazz))->toBeTrue()
        ->and(stored($this->jazz))->toMatchArray(['parent' => null, 'path' => '/', 'depth' => 1]);
});

test('a_re_create_whose_parent_is_gone', function (): void {
    $this->genres->delete($this->jazz);
    $this->genres->delete($this->music);

    expect(fn () => $this->genres->findOrCreate('Jazz'))->toThrow(InvalidTagHierarchyException::class, 'restore it first')
        ->and(fn () => $this->genres->make('Jazz')->create())->toThrow(InvalidTagHierarchyException::class, 'restore it first')
        ->and(Tag::query()->withTrashed()->findOrFail($this->jazz->getKey())->trashed())->toBeTrue();

    // A free-form name is a root, as every free-form tag of a tree.
    $topics = Tags::type('topic');
    $science = $topics->make('Science')->create();
    $physics = $topics->make('Physics')->under($science)->create();
    $topics->delete($physics);
    $topics->delete($science);

    Article::query()->create()->attachTags('Physics', 'topic');

    expect(stored($physics))->toMatchArray(['trashed' => false, 'parent' => null, 'path' => '/', 'depth' => 1]);
});

test('a_re_create_moves_the_restored_tags_subtree_along', function (): void {
    $bebop = $this->genres->make('Bebop')->under($this->jazz)->create();
    $this->genres->delete($this->jazz, TagChildren::Delete);

    $this->genres->make('Jazz')->under(null)->create();

    expect(stored($bebop))->toMatchArray(['trashed' => true, 'path' => '/'.$this->jazz->getKey().'/', 'depth' => 2]);
});

test('a_re_create_respects_the_depth_cap', function (): void {
    Tags::define('cat')->hierarchical(maxDepth: 2);
    $cats = Tags::type('cat');
    $a = $cats->make('A')->create();
    $b = $cats->make('B')->under($a)->create();
    $cats->delete($b);
    $cats->move($a->fresh() ?? $a, $cats->make('C')->create());

    expect(fn () => $cats->findOrCreate('B'))->toThrow(InvalidTagHierarchyException::class, 'levels deep')
        ->and(Tag::query()->withTrashed()->findOrFail($b->getKey())->trashed())->toBeTrue();
});

// A plain `$tag->delete()` leaves live children: a create may not bring the tag back under one of
// them.
test('a_re_create_under_its_own_child_is_a_cycle', function (): void {
    Tags::define('deep')->hierarchical();
    $deep = Tags::type('deep');
    $a = $deep->make('A')->create();
    $b = $deep->make('B')->under($a)->create();
    $a->delete();

    expect(fn () => $deep->make('A')->under($b)->create())->toThrow(InvalidTagHierarchyException::class, 'below itself')
        ->and(stored($a))->toMatchArray(['trashed' => true, 'parent' => null]);
});
