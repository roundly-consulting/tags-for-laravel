<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ArticleTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\TwoTraitsArticle;

beforeEach(function (): void {
    Tags::define(ArticleTagType::Genre)->hierarchical()->single();
    Tags::define(ArticleTagType::Label);
});

test('tags_of_reads_the_loaded_relation', function (): void {
    $article = Article::query()->create();
    $article->attachTags(['Live', 'Outdoor'], 'label')->attachTags('Loose');

    $loaded = Article::query()->with('tags')->findOrFail($article->getKey());
    DB::enableQueryLog();
    DB::flushQueryLog();

    $labels = $loaded->tagsOf('label');
    $loose = $loaded->tagOf('tag');

    expect(DB::getQueryLog())->toBe([])
        ->and($labels->pluck('slug')->all())->toBe(['live', 'outdoor'])
        ->and($loose?->slug)->toBe('loose')
        // Unloaded: one query, never a constrained relation left behind.
        ->and($article->tagsOf('label')->pluck('slug')->all())->toBe(['live', 'outdoor'])
        ->and($article->relationLoaded('tags'))->toBeFalse();
});

test('listing_25_models_with_tags_runs_two_queries', function (): void {
    Tags::type(ArticleTagType::Genre)->make('Jazz')->create();

    foreach (range(1, 25) as $index) {
        Article::query()->create(['title' => "Concert {$index}"])
            ->syncTags('jazz', ArticleTagType::Genre)
            ->attachTags(['Live', "Band {$index}"], ArticleTagType::Label);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $names = [];

    foreach (Article::query()->with('tags')->get() as $article) {
        $names[] = $article->tagOf(ArticleTagType::Genre)?->name.'|'.$article->tagsOf(ArticleTagType::Label)->pluck('name')->join(', ');
    }

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and($names)->toHaveCount(25)
        ->and($names[0])->toBe('Jazz|Live, Band 1');
});

test('handle_writes_chain_and_reads_see_them', function (): void {
    $article = Article::query()->create();
    $article->load('tags');

    $handle = Tags::for($article)->attach(['Live', 'Outdoor'], 'label')->detach('outdoor', 'label')->attach('Loose');

    expect($handle->all()->pluck('slug')->sort()->values()->all())->toBe(['live', 'loose'])
        ->and($handle->of('label')->pluck('slug')->all())->toBe(['live'])
        ->and($handle->one('tag')?->slug)->toBe('loose')
        ->and($handle->has('LIVE'))->toBeTrue()
        ->and($handle->has('live', 'tag'))->toBeFalse()
        ->and($handle->has(Tag::query()->firstOrFail()))->toBeTrue()
        ->and($handle->has('!!!'))->toBeFalse()
        ->and($handle->hasAny(['nope', 'loose']))->toBeTrue()
        ->and($handle->hasAny(['nope']))->toBeFalse()
        ->and($handle->hasAll(['live', 'loose']))->toBeTrue()
        ->and($handle->hasAll(['live', 'nope']))->toBeFalse()
        ->and($handle->model())->toBe($article)
        ->and($handle->sync(['Fresh'], 'label')->clear('tag')->all()->pluck('slug')->all())->toBe(['fresh'])
        ->and($article->hasTag('fresh'))->toBeTrue()
        ->and($article->hasAnyTags(['x', 'fresh'], 'label'))->toBeTrue()
        ->and($article->hasAllTags(['fresh', 'x']))->toBeFalse();
});

test('scopes_match_the_documented_sets', function (string $scope, array $arguments, array $expected): void {
    $a = Article::query()->create(['title' => 'a']);
    $b = Article::query()->create(['title' => 'b']);
    $c = Article::query()->create(['title' => 'c']);
    Article::query()->create(['title' => 'untagged']);
    $a->attachTags(['Live', 'Outdoor'], 'label');
    $b->attachTags(['Live'], 'label')->attachTags('Loose');
    $c->attachTags(['Indoor'], 'label');

    $titles = Article::query()->{$scope}(...$arguments)->orderBy('title')->pluck('title')->all();

    expect($titles)->toBe($expected);
})->with([
    'any' => ['withAnyTags', [['live'], 'label'], ['a', 'b']],
    'any of several' => ['withAnyTags', [['outdoor', 'indoor'], 'label'], ['a', 'c']],
    'any, unknown ignored' => ['withAnyTags', [['indoor', 'nope'], 'label'], ['c']],
    'any, all unknown' => ['withAnyTags', [['nope'], 'label'], []],
    'all' => ['withAllTags', [['live', 'outdoor'], 'label'], ['a']],
    'all of one' => ['withAllTags', [['live'], 'label'], ['a', 'b']],
    'without' => ['withoutTags', [['live'], 'label'], ['c', 'untagged']],
    'without, unknown' => ['withoutTags', [['nope'], 'label'], ['a', 'b', 'c', 'untagged']],
    'without any tag of a type' => ['withoutTags', [[], 'tag'], ['a', 'c', 'untagged']],
    'of a type' => ['withTagsOf', ['tag'], ['b']],
    'by name' => ['withAnyTags', [['LIVE!'], 'label'], ['a', 'b']],
]);

test('with_all_tags_with_an_unknown_string_matches_nothing', function (): void {
    $article = Article::query()->create();
    $article->attachTags(['Live'], 'label');

    expect(Article::query()->withAllTags(['live', 'nope'], 'label')->count())->toBe(0)
        ->and(Article::query()->withAllTags([], 'label')->count())->toBe(1);
});

test('without_tags_without_args_finds_untagged', function (): void {
    $tagged = Article::query()->create(['title' => 'tagged']);
    Article::query()->create(['title' => 'untagged']);
    $tagged->attachTags('x');

    expect(Article::query()->withoutTags()->pluck('title')->all())->toBe(['untagged']);
});

it('matches models, keys and tags of every scope unless narrowed', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $other = Team::query()->create(['name' => 'Globex']);
    $global = Tags::type('label')->make('Printer')->create();
    $scoped = Tags::type('label')->within($team)->make('Printer')->create();
    $a = ScopedArticle::query()->create(['title' => 'a']);
    $b = ScopedArticle::query()->create(['title' => 'b', 'team_id' => $team->getKey()]);
    $a->attachTags($global, 'label');
    $b->attachTags($scoped, 'label');

    expect(ScopedArticle::query()->withAnyTags('printer', 'label')->count())->toBe(2)
        ->and(ScopedArticle::query()->withAnyTags('printer', 'label', within: $team)->count())->toBe(2)
        ->and(ScopedArticle::query()->withAnyTags('printer', 'label', within: $other)->pluck('title')->all())->toBe(['a'])
        ->and(ScopedArticle::query()->withAnyTags([$global])->pluck('title')->all())->toBe(['a'])
        ->and(ScopedArticle::query()->withAnyTags([(int) $scoped->getKey()])->pluck('title')->all())->toBe(['b'])
        ->and(ScopedArticle::query()->withAnyTags([999999])->count())->toBe(0)
        ->and(fn () => ScopedArticle::query()->withAnyTags([$global], 'tag')->get())->toThrow(TagTypeMismatchException::class)
        ->and(fn () => ScopedArticle::query()->withAnyTags(['x'], 'nope')->get())->toThrow(UnknownTagTypeException::class)
        ->and(fn () => ScopedArticle::query()->withTagsOf('nope')->get())->toThrow(UnknownTagTypeException::class);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

// §4.11: a string matches in every scope of the type — each scope by its own first chain locale
// with a match, never one locale picked for all scopes together.
test('strings_match_each_scopes_own_tag', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    $a = ScopedArticle::query()->create(['title' => 'a', 'team_id' => $acme->getKey()]);
    $g = ScopedArticle::query()->create(['title' => 'g', 'team_id' => $globex->getKey()]);
    $n = ScopedArticle::query()->create(['title' => 'n']);
    $a->attachTags(Tags::type('label')->within($acme)->make(['en' => 'Rock'])->create(), 'label');
    $g->attachTags(Tags::type('label')->within($globex)->make(['sk' => 'Rock'])->create(), 'label');
    $n->attachTags(Tags::type('label')->make(['sk' => 'Rock'])->create(), 'label');
    $titles = static fn ($query): array => $query->orderBy('title')->pluck('title')->all();

    expect($titles(ScopedArticle::query()->withAnyTags('rock', 'label')))->toBe(['a', 'g', 'n'])
        ->and($titles(ScopedArticle::query()->withAllTags('rock', 'label')))->toBe(['a', 'g', 'n'])
        ->and($titles(ScopedArticle::query()->withoutTags('rock', 'label')))->toBe([])
        ->and($titles(ScopedArticle::query()->withAnyTags('rock', 'label', within: $acme)))->toBe(['a', 'n'])
        ->and($titles(ScopedArticle::query()->withAnyTags('rock', 'label', within: $globex)))->toBe(['g', 'n']);
});

test('trait_has_no_name_collisions', function (): void {
    $page = TwoTraitsArticle::query()->create(['name' => ['en' => 'Home', 'sk' => 'Domov']]);

    $page->attachTags(['Featured']);

    $reserved = ['tags', 'tagsOf', 'tagOf', 'hasTag', 'hasAnyTags', 'hasAllTags', 'attachTags', 'detachTags', 'syncTags',
        'clearTags', 'tagScope', 'tagTypes', 'bootHasTags', 'scopeWithAnyTags', 'scopeWithAllTags', 'scopeWithoutTags', 'scopeWithTagsOf'];

    foreach ($reserved as $method) {
        expect(method_exists(Model::class, $method))->toBeFalse($method);
    }

    expect($page->slugFor('sk'))->toBe('domov')
        ->and($page->getTranslation('name', 'sk'))->toBe('Domov')
        ->and($page->tagsOf('tag')->pluck('slug')->all())->toBe(['featured'])
        ->and(TwoTraitsArticle::query()->withAnyTags('featured')->count())->toBe(1);
});

test('enum_type_names_are_accepted_by_the_trait', function (): void {
    $article = Article::query()->create();
    Tags::type(ArticleTagType::Genre)->make('Jazz')->create();

    $article->syncTags('jazz', ArticleTagType::Genre)->attachTags('live', ArticleTagType::Label);

    expect($article->tagOf(ArticleTagType::Genre)?->slug)->toBe('jazz')
        ->and(Article::query()->withAnyTags(['live'], ArticleTagType::Label)->count())->toBe(1)
        ->and(Article::query()->withTagsOf(ArticleTagType::Genre)->count())->toBe(1);
});

// "0" is a name like any other, also beside other names.
test('a_tag_slugged_zero_resolves_among_other_names', function (): void {
    $zero = Article::query()->create(['title' => 'zero']);
    $zero->attachTags(['0']);
    Article::query()->create(['title' => 'jazz'])->attachTags(['jazz']);

    expect(Article::query()->withAnyTags(['0', 'rock'])->pluck('title')->all())->toBe(['zero']);

    Tags::define('cur')->curated();
    Tags::type('cur')->make('0')->create();
    Tags::type('cur')->make('Rock')->create();

    $zero->attachTags(['0', 'rock'], 'cur');

    expect($zero->tagsOf('cur')->pluck('slug')->sort()->values()->all())->toBe(['0', 'rock']);
});

test('unknown_types_throw_in_the_trait_and_scopes', function (Closure $use): void {
    $article = Article::query()->create();

    expect(fn () => $use($article))->toThrow(UnknownTagTypeException::class);
})->with([
    'attach' => [fn (Article $article) => $article->attachTags('x', 'nope')],
    'detach' => [fn (Article $article) => $article->detachTags('x', 'nope')],
    'sync' => [fn (Article $article) => $article->syncTags('x', 'nope')],
    'clear' => [fn (Article $article) => $article->clearTags('nope')],
    'tagsOf' => [fn (Article $article) => $article->tagsOf('nope')],
    'scope' => [fn (Article $article) => Article::query()->withAnyTags('x', 'nope')->get()],
    'without scope' => [fn (Article $article) => Article::query()->withoutTags([], 'nope')->get()],
]);
