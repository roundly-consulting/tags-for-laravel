<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;
use RoundlyConsulting\Tags\Http\Resources\TagSummaryResource;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Rules\TagsRule;
use RoundlyConsulting\Tags\Testing\TagExpectations;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\RestrictedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

beforeEach(function (): void {
    Tags::define('genre')->hierarchical()->single();
    Tags::define('label')->max(3);
    $this->music = Tags::type('genre')->make('Music')->create();
    $this->jazz = Tags::type('genre')->make('Jazz')->under($this->music)->create();
    $this->rock = Tags::type('genre')->make('Rock')->create();
});

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 * @return array<string, list<string>>
 */
function tagErrors(array $data, array $rules): array
{
    return Validator::make($data, $rules)->errors()->toArray();
}

test('tags_rule_curated', function (): void {
    expect(tagErrors(['category' => 'jazz'], ['category' => [TagsRule::for('genre')]]))->toBe([])
        ->and(tagErrors(['category' => 'Polka'], ['category' => [TagsRule::for('genre')]])['category'][0])->toBe('The category contains the unknown tag [Polka].')
        ->and(Tag::query()->count())->toBe(3);
});

test('tags_rule_free_form', function (): void {
    expect(tagErrors(['labels' => ['Brand new', 'Other']], ['labels' => ['array', TagsRule::for('label')]]))->toBe([])
        ->and(tagErrors(['labels' => ['!!!']], ['labels' => [TagsRule::for('label')]])['labels'][0])->toBe('The labels contains an invalid tag [!!!].')
        ->and(tagErrors(['labels' => [['nested']]], ['labels' => [TagsRule::for('label')]]))->toHaveKey('labels')
        ->and(Tag::query()->count())->toBe(3);
});

test('tags_rule_ids', function (): void {
    $live = Tags::type('label')->make('Live')->create();

    expect(tagErrors(['labels' => [(string) $live->getKey()]], ['labels' => [TagsRule::for('label')->byId()]]))->toBe([])
        ->and(tagErrors(['labels' => [$this->jazz->getKey()]], ['labels' => [TagsRule::for('label')->byId()]]))->toHaveKey('labels')
        ->and(tagErrors(['labels' => ['abc']], ['labels' => [TagsRule::for('label')->byId()]])['labels'][0])->toContain('invalid')
        ->and(tagErrors(['labels' => [999999]], ['labels' => [TagsRule::for('label')->byId()]])['labels'][0])->toContain('unknown tag [999999]')
        ->and(tagErrors(['labels' => []], ['labels' => ['array', TagsRule::for('label')->byId()]]))->toBe([]);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('tags_rule_scope', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    Tags::type('genre')->within($team)->make('Teamcore')->create();

    expect(tagErrors(['category' => 'teamcore'], ['category' => [TagsRule::for('genre')]]))->toHaveKey('category')
        ->and(tagErrors(['category' => 'teamcore'], ['category' => [TagsRule::for('genre')->within($team)]]))->toBe([])
        ->and(tagErrors(['category' => 'jazz'], ['category' => [TagsRule::for('genre')->within($team)]]))->toBe([])
        ->and(tagErrors(['category' => 'teamcore'], ['category' => [TagsRule::for('genre')->within(null)]]))->toHaveKey('category');
});

test('tags_rule_refuses_foreign_scope_ids', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    $theirs = Tags::type('label')->within($globex)->make('Theirs')->create();

    expect(tagErrors(['labels' => [$theirs->getKey()]], ['labels' => [TagsRule::for('label')->byId()->within($acme)]]))->toHaveKey('labels')
        ->and(tagErrors(['labels' => [$theirs->getKey()]], ['labels' => [TagsRule::for('label')->byId()->within($globex)]]))->toBe([]);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('tags_rule_taggable', function (): void {
    $restricted = RestrictedArticle::query()->create();

    expect(tagErrors(['labels' => ['x']], ['labels' => [TagsRule::for('label')->taggable($restricted)]]))->toBe([])
        ->and(tagErrors(['category' => 'jazz'], ['category' => [TagsRule::for('genre')->taggable($restricted)]])['category'][0])->toBe('The category cannot be used on this record.');
});

test('tags_rule_accepts_an_unsaved_taggable', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    Tags::type('genre')->within($team)->make('Teamcore')->create();

    $draft = new ScopedArticle(['team_id' => $team->getKey()]);

    expect(tagErrors(['category' => 'teamcore'], ['category' => [TagsRule::for('genre')->taggable($draft)]]))->toBe([])
        ->and(tagErrors(['category' => 'teamcore'], ['category' => [TagsRule::for('genre')->taggable(new ScopedArticle)]]))->toHaveKey('category');

    $unsavedScope = new ScopedArticle;
    $unsavedScope->setRelation('team', new Team(['name' => 'Draft']));

    expect(fn () => Validator::make(['category' => 'jazz'], ['category' => [TagsRule::for('genre')->taggable($unsavedScope)]])->passes())
        ->toThrow(InvalidArgumentException::class, 'saved model');
});

test('tags_rule_within_overrides_the_taggables_scope', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    Tags::type('genre')->within($globex)->make('Globexcore')->create();

    $draft = new ScopedArticle(['team_id' => $acme->getKey()]);

    expect(tagErrors(['category' => 'globexcore'], ['category' => [TagsRule::for('genre')->taggable($draft)]]))->toHaveKey('category')
        ->and(tagErrors(['category' => 'globexcore'], ['category' => [TagsRule::for('genre')->taggable($draft)->within($globex)]]))->toBe([]);
});

test('tags_rule_max', function (): void {
    expect(tagErrors(['labels' => ['a', 'b', 'c', 'd']], ['labels' => [TagsRule::for('label')]])['labels'][0])->toBe('The labels may hold at most 3 tags.')
        ->and(tagErrors(['labels' => ['a', 'b']], ['labels' => [TagsRule::for('label')->max(1)]])['labels'][0])->toBe('The labels may hold only one tag.')
        ->and(tagErrors(['labels' => ['a', 'b']], ['labels' => [TagsRule::for('label')->max(10)]]))->toBe([])
        ->and(tagErrors(['labels' => ['a', 'b', 'c', 'd']], ['labels' => [TagsRule::for('tag')->max(3)]]))->toHaveKey('labels');
});

test('tags_rule_names_the_unknown_entry', function (): void {
    expect(tagErrors(['category' => ['jazz', 'Polka', 'Waltz']], ['category' => ['array', TagsRule::for('genre')->max(5)]])['category'][0])
        ->toBe('The category contains the unknown tag [Polka].');

    app()->setLocale('sk');

    expect(tagErrors(['category' => 'Polka'], ['category' => [TagsRule::for('genre')]])['category'][0])
        ->toBe('Pole category obsahuje neznámy štítok „Polka“.');
});

test('tags_rule_names_the_invalid_entry', function (mixed $entries, string $named): void {
    expect(tagErrors(['labels' => $entries], ['labels' => [TagsRule::for('label')]])['labels'][0])
        ->toBe("The labels contains an invalid tag [{$named}].");
})->with([
    'unsluggable' => [['ok', '!!!', '???'], '!!!'],
    'too long' => [[str_repeat('a', 65)], str_repeat('a', 64).'...'],
    'nested' => [[['nested']], '["nested"]'],
    'blank' => [['  '], '  '],
]);

test('tags_rule_messages_are_the_same_for_foreign_and_missing_keys', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    $theirs = Tags::type('label')->within($globex)->make('Theirs')->create();
    $ours = Tags::type('label')->within($acme)->make('Ours')->create();
    $rule = fn (): TagsRule => TagsRule::for('label')->byId()->within($acme);
    $foreignKey = (string) $theirs->getKey();

    $foreign = tagErrors(['labels' => [(string) $ours->getKey(), $foreignKey]], ['labels' => [$rule()]])['labels'][0];
    $missing = tagErrors(['labels' => [(string) $ours->getKey(), '999999']], ['labels' => [$rule()]])['labels'][0];

    expect($foreign)->toBe("The labels contains the unknown tag [{$foreignKey}].")
        ->and($missing)->toBe('The labels contains the unknown tag [999999].')
        ->and(str_replace($foreignKey, '999999', $foreign))->toBe($missing)
        ->and(tagErrors(['labels' => ['1', 'abc']], ['labels' => [$rule()]])['labels'][0])->toBe('The labels contains an invalid tag [abc].');
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('tags_rule_scalar_for_single', function (): void {
    expect(tagErrors(['category' => 'jazz'], ['category' => [TagsRule::for('genre')]]))->toBe([])
        ->and(tagErrors(['labels' => 'live'], ['labels' => [TagsRule::for('label')]])['labels'][0])->toBe('The labels must be a list of tags.')
        ->and(tagErrors(['category' => ['jazz', 'rock']], ['category' => [TagsRule::for('genre')]])['category'][0])->toContain('only one tag')
        ->and(tagErrors(['category' => null], ['category' => ['nullable', TagsRule::for('genre')]]))->toBe([]);
});

test('tags_rule_dedupe', function (): void {
    expect(tagErrors(['labels' => ['Live', 'live', 'LIVE!', 'a', 'b']], ['labels' => [TagsRule::for('label')]]))->toBe([])
        ->and(tagErrors(['category' => ['jazz', 'Jazz']], ['category' => [TagsRule::for('genre')]]))->toBe([]);
});

test('by_id_recipe_never_creates_numeric_names', function (): void {
    $live = Tags::type('label')->make('Live')->create();
    $article = Article::query()->create();
    $validated = Validator::make(['labels' => [(string) $live->getKey()]], ['labels' => ['array', TagsRule::for('label')->byId()]])->validate();

    $article->syncTags(Tags::type('label')->findMany($validated['labels']), 'label');

    expect($article->tagsOf('label')->pluck('slug')->all())->toBe(['live'])
        ->and(Tag::query()->where('type', 'label')->count())->toBe(1);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('tags_filter_matches_the_scopes', function (string $mode, array $values, bool $descendants): void {
    $articles = [
        'bebop' => $this->jazz,
        'rock' => $this->rock,
        'music' => $this->music,
    ];

    foreach ($articles as $title => $tag) {
        Article::query()->create(['title' => $title])->syncTags($tag, 'genre')->attachTags([$title.'-label', 'shared'], 'label');
    }

    Article::query()->create(['title' => 'none']);

    $filter = TagsFilter::{$mode}('genre');

    if ($descendants) {
        $filter->withDescendants();
    }

    $request = Request::create('/', 'GET', ['filter' => ['genre' => implode(',', $values)]]);
    $viaFilter = QueryBuilder::for(Article::class, $request)->allowedFilters(AllowedFilter::custom('genre', $filter))->orderBy('title')->pluck('title')->all();

    $scope = ['any' => 'withAnyTags', 'all' => 'withAllTags', 'none' => 'withoutTags'][$mode];
    $viaScope = Article::query()->{$scope}($values, 'genre', $descendants)->orderBy('title')->pluck('title')->all();

    expect($viaFilter)->toBe($viaScope);
})->with([
    'any' => ['any', ['jazz', 'rock'], false],
    'any with descendants' => ['any', ['music'], true],
    'all' => ['all', ['jazz'], false],
    'all, one unknown' => ['all', ['jazz', 'nope'], false],
    'none' => ['none', ['rock'], false],
    'none with descendants' => ['none', ['music'], true],
]);

test('tags_filter_ignores_an_empty_value', function (string $mode, mixed $value): void {
    Article::query()->create(['title' => 'tagged'])->attachTags('live', 'label');
    Article::query()->create(['title' => 'untagged']);

    $query = Article::query()->orderBy('title');
    TagsFilter::{$mode}('label')->apply($query, $value, 'labels');

    expect($query->pluck('title')->all())->toBe(['tagged', 'untagged']);
})->with(['any', 'all', 'none'])->with([
    'empty string' => [''],
    'blank string' => ['  '],
    'empty list' => [[]],
    'list of blanks' => [['', ' ']],
    'junk' => [[['nested'], null, 1.5]],
]);

test('tags_filter_ignores_an_empty_query_string_value', function (string $mode): void {
    Article::query()->create(['title' => 'tagged'])->attachTags('live', 'label');
    Article::query()->create(['title' => 'untagged']);

    $request = Request::create('/?filter[labels]=', 'GET');
    $titles = QueryBuilder::for(Article::class, $request)
        ->allowedFilters(AllowedFilter::custom('labels', TagsFilter::{$mode}('label')))
        ->orderBy('title')
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['tagged', 'untagged']);
})->with(['any', 'all', 'none']);

it('filters by key, within a scope resolved late', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $teamLabel = Tags::type('label')->within($team)->make('Ours')->create();
    $article = ScopedArticle::query()->create(['title' => 'ours', 'team_id' => $team->getKey()]);
    $article->attachTags($teamLabel, 'label');

    $apply = static function (TagsFilter $filter, string $value): array {
        $request = Request::create('/', 'GET', ['filter' => ['labels' => $value]]);

        return QueryBuilder::for(ScopedArticle::class, $request)->allowedFilters(AllowedFilter::custom('labels', $filter))->pluck('title')->all();
    };

    expect($apply(TagsFilter::any('label')->byId(), (string) $teamLabel->getKey()))->toBe(['ours'])
        ->and($apply(TagsFilter::any('label')->byId()->within(fn () => Team::query()->create(['name' => 'Other'])), (string) $teamLabel->getKey()))->toBe([])
        ->and($apply(TagsFilter::any('label')->within(fn () => $team), 'ours'))->toBe(['ours'])
        ->and($apply(TagsFilter::any('label')->within($team), 'ours'))->toBe(['ours'])
        ->and($apply(TagsFilter::all('label')->within(null), 'ours'))->toBe(['ours']);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('expectations_pass_and_fail', function (): void {
    TagExpectations::register();
    $article = Article::query()->create();
    $article->attachTags(['live', 'outdoor'], 'label');
    $bare = Article::query()->create();

    expect($article)->toHaveTags(['live'], 'label')
        ->and($article)->toHaveTags(['live', 'outdoor'])
        ->and($bare)->toHaveNoTags()
        ->and($article)->toHaveNoTags('genre')
        ->and(fn () => expect($article)->toHaveTags(['nope']))->toThrow(ExpectationFailedException::class)
        ->and(fn () => expect($article)->toHaveNoTags())->toThrow(ExpectationFailedException::class);
});

test('tag_summary_resource_runs_no_queries_and_no_gate_checks', function (): void {
    $checks = 0;
    Gate::before(static function () use (&$checks): ?bool {
        $checks++;

        return null;
    });
    $tag = Tag::query()->findOrFail($this->jazz->getKey());

    DB::enableQueryLog();
    DB::flushQueryLog();

    $rendered = (new TagSummaryResource($tag))->resolve(Request::create('/'));

    expect(DB::getQueryLog())->toBe([])
        ->and($checks)->toBe(0)
        ->and($rendered)->toBe([
            'id' => $tag->getKey(),
            'type' => 'genre',
            'name' => 'Jazz',
            'slug' => 'jazz',
            'color' => null,
            'icon' => null,
            'depth' => 2,
        ]);
});

test('tag_summary_resource_leaks_no_class_names', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $tag = Tags::type('label')->within($team)->make('Ours')->create();

    $json = (string) json_encode(TagSummaryResource::collection([$tag])->resolve(Request::create('/')));

    expect($json)->not->toContain('\\\\')->not->toContain('Team')->not->toContain('scope');
});

test('tag_summary_resource_renders_loaded_ancestors_without_queries', function (): void {
    $jazz = Tag::query()->findOrFail($this->jazz->getKey());
    $rock = Tag::query()->findOrFail($this->rock->getKey());
    Tags::loadAncestors([$jazz, $rock]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $rendered = TagSummaryResource::collection([$jazz, $rock])->resolve(Request::create('/'));

    expect(DB::getQueryLog())->toBe([])
        ->and($rendered[0]['ancestors'])->toBe([[
            'id' => $this->music->getKey(),
            'type' => 'genre',
            'name' => 'Music',
            'slug' => 'music',
            'color' => null,
            'icon' => null,
            'depth' => 1,
        ]])
        ->and($rendered[1]['ancestors'])->toBe([]);
});

test('tag_summary_resource_omits_ancestors_unless_loaded', function (): void {
    $jazz = Tag::query()->findOrFail($this->jazz->getKey());

    expect((new TagSummaryResource($jazz))->resolve(Request::create('/')))->not->toHaveKey('ancestors')
        ->and(array_keys((new TagSummaryResource($jazz))->resolve(Request::create('/'))))->toBe(['id', 'type', 'name', 'slug', 'color', 'icon', 'depth']);
});
