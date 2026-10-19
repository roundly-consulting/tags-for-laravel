<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

/**
 * Tag `$count` fresh articles with the tag named `$slug`.
 */
function useTag(string $slug, int $count): void
{
    foreach (range(1, $count) as $_) {
        Article::query()->create()->attachTags($slug);
    }
}

test('suggest_ranks_prefix_before_contains', function (): void {
    Tags::type('tag')->make('Rock')->create();
    Tags::type('tag')->make('Rockabilly')->create();
    Tags::type('tag')->make('Punk rock')->create();
    useTag('rockabilly', 2);
    useTag('punk-rock', 5);

    expect(Tags::type('tag')->suggest('roc')->pluck('slug')->all())->toBe(['rockabilly', 'rock', 'punk-rock']);
});

test('suggest_searches_every_locale', function (): void {
    Tags::type('tag')->make(['en' => 'Music', 'sk' => 'Hudba'])->create();

    expect(Tags::type('tag')->suggest('hud')->pluck('slug')->all())->toBe(['music'])
        ->and(Tags::type('tag')->suggest('MUS')->pluck('slug')->all())->toBe(['music'])
        // Contains across locales too.
        ->and(Tags::type('tag')->suggest('udb')->pluck('slug')->all())->toBe(['music']);
});

test('suggest_escapes_like_wildcards', function (): void {
    Tags::type('tag')->make('100% cotton')->create();
    Tags::type('tag')->make('1000 things')->create();
    Tags::type('tag')->make('a_b')->create();
    Tags::type('tag')->make('axb')->create();

    expect(Tags::type('tag')->suggest('100%')->pluck('name')->all())->toBe(['100% cotton', '1000 things'])
        ->and(Tags::type('tag')->suggest('0%')->pluck('name')->all())->toBe(['100% cotton'])
        // "_b" slugifies to "b" (no prefix hit); the name search must not read "_" as a wildcard.
        ->and(Tags::type('tag')->suggest('_b')->pluck('name')->all())->toBe(['a_b']);
});

test('suggest_is_scope_aware', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    Tags::type('tag')->make('Printer')->create();
    Tags::type('tag')->within($acme)->make('Printhead')->create();
    Tags::type('tag')->within($globex)->make('Printing press')->create();

    expect(Tags::type('tag')->suggest('print')->pluck('name')->all())->toBe(['Printer'])
        ->and(Tags::type('tag')->within($acme)->suggest('print')->pluck('name')->sort()->values()->all())->toBe(['Printer', 'Printhead'])
        ->and(Tags::type('tag')->within($acme, includeGlobal: false)->suggest('print')->pluck('name')->all())->toBe(['Printhead']);
});

test('suggest_caps_the_limit', function (): void {
    foreach (range(1, 60) as $index) {
        Tags::type('tag')->make("Item {$index}")->create();
    }

    expect(Tags::type('tag')->suggest('item'))->toHaveCount(10)
        ->and(Tags::type('tag')->suggest('item', 5))->toHaveCount(5)
        ->and(Tags::type('tag')->suggest('item', 500))->toHaveCount(50)
        ->and(Tags::type('tag')->suggest('item', 0))->toHaveCount(1);
});

test('suggest_ignores_trashed_tags', function (): void {
    $rock = Tags::type('tag')->make('Rock')->create();
    Tags::type('tag')->delete($rock);

    expect(Tags::type('tag')->suggest('rock'))->toHaveCount(0);
});

test('suggest_skips_contains_when_prefix_fills_the_limit', function (): void {
    Tags::type('tag')->make('Jazz')->create();
    Tags::type('tag')->make('Jazz funk')->create();
    Tags::type('tag')->make('Acid jazz')->create();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $two = Tags::type('tag')->suggest('jazz', 2);
    $filled = count(DB::getQueryLog());

    DB::flushQueryLog();
    $three = Tags::type('tag')->suggest('jazz', 3);
    $topped = count(DB::getQueryLog());

    DB::flushQueryLog();
    $none = Tags::type('tag')->suggest('  !!! ');

    expect($two->pluck('slug')->all())->toBe(['jazz', 'jazz-funk'])
        ->and($filled)->toBe(1)
        ->and($three->pluck('slug')->all())->toBe(['jazz', 'jazz-funk', 'acid-jazz'])
        ->and($topped)->toBe(2)
        ->and($none)->toHaveCount(0)
        ->and(DB::getQueryLog())->toBe([]);
});

test('popular_orders_by_usage', function (): void {
    Tags::type('tag')->make('Unused')->create();
    useTag('a', 1);
    useTag('b', 3);
    useTag('c', 2);

    expect(Tags::type('tag')->popular()->pluck('slug')->all())->toBe(['b', 'c', 'a'])
        ->and(Tags::type('tag')->popular(2)->pluck('slug')->all())->toBe(['b', 'c'])
        ->and(Tags::type('tag')->popular(1000))->toHaveCount(3);
});

test('popular_on_a_class_counts_live', function (): void {
    useTag('a', 3);
    $note = Note::query()->create(['body' => 'x']);
    $note->attachTags(['b']);
    Note::query()->create(['body' => 'y'])->attachTags(['b']);

    $onNotes = Tags::type('tag')->popular(on: Note::class);

    expect($onNotes->pluck('slug')->all())->toBe(['b'])
        ->and($onNotes->first()?->getAttribute('taggables_count'))->toBe(2)
        ->and(Tags::type('tag')->popular(on: Article::class)->pluck('slug')->all())->toBe(['a'])
        ->and(fn () => Tags::type('tag')->popular(on: 'Not\\AClass'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Tags::type('tag')->popular(where: fn () => null))->toThrow(InvalidArgumentException::class, 'needs the taggable class');
});

test('popular_where_counts_only_matching_taggables', function (): void {
    Article::query()->create(['listed' => true])->attachTags(['public', 'both']);
    Article::query()->create(['listed' => false])->attachTags(['secret', 'both']);
    Article::query()->create(['listed' => false])->attachTags(['both']);

    $listed = Tags::type('tag')->popular(on: Article::class, where: fn (Builder $articles) => $articles->where('listed', true));

    // A tag used only on unlisted articles neither counts nor surfaces.
    expect($listed->pluck('slug')->sort()->values()->all())->toBe(['both', 'public'])
        ->and($listed->firstWhere('slug', 'both')?->getAttribute('taggables_count'))->toBe(1)
        ->and(Tags::type('tag')->popular(on: Article::class)->firstWhere('slug', 'both')?->getAttribute('taggables_count'))->toBe(3);
});
