<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tags\Actions\Tagging\AttachTagsAction;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Events\Tagged;
use RoundlyConsulting\Tags\Events\Untagged;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeNotAllowedException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Exceptions\UnsavedTaggableException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Handles\TaggableTags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ArticleTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\RestrictedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\UuidArticle;

beforeEach(function (): void {
    Tags::define('genre')->hierarchical()->single();
    Tags::define('label')->max(3);
    $this->article = Article::query()->create(['title' => 'Concert']);
});

/**
 * @return list<string>
 */
function slugsOf(Article|Note|ScopedArticle|RestrictedArticle $model, ?string $type = null): array
{
    $tags = $type === null ? $model->tags()->get() : $model->tagsOf($type);

    return $tags->map(static fn (Tag $tag): string => (string) $tag->slug)->sort()->values()->all();
}

test('accepts_models_ids_slugs_and_names', function (): void {
    $live = Tags::type('label')->make('Live')->create();
    $outdoor = Tags::type('label')->make('Outdoor')->create();
    Tags::type('label')->make('Late night')->create();

    $this->article->attachTags([$live, (int) $outdoor->getKey(), 'late-night'], 'label');

    expect(slugsOf($this->article, 'label'))->toBe(['late-night', 'live', 'outdoor']);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('duplicate_inputs_collapse', function (): void {
    $live = Tags::type('label')->make('Live')->create();

    $changes = Tags::attach($this->article, [$live, 'live', 'LIVE', 'Fresh', 'fresh!'], 'label');

    expect(array_map(static fn (Tag $tag): string => (string) $tag->slug, $changes->attached))->toBe(['live', 'fresh'])
        ->and(Tagging::query()->count())->toBe(2)
        ->and(Tag::query()->count())->toBe(2);
});

test('trashed_tags_are_refused_as_input', function (): void {
    $live = Tags::type('label')->make('Live')->create();
    Tags::type('label')->delete($live);

    expect(fn () => $this->article->attachTags($live, 'label'))->toThrow(TagNotFoundException::class, 'deleted')
        ->and(fn () => $this->article->attachTags((int) $live->getKey(), 'label'))->toThrow(TagNotFoundException::class, 'deleted')
        ->and(fn () => $this->article->attachTags(999999, 'label'))->toThrow(TagNotFoundException::class, '999999')
        ->and(Tagging::query()->count())->toBe(0);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('type_is_inferred_from_models_and_ids', function (): void {
    $jazz = Tags::type('genre')->make('Jazz')->create();
    $live = Tags::type('label')->make('Live')->create();

    $changes = Tags::attach($this->article, [$jazz, (int) $live->getKey(), 'free']);

    expect(slugsOf($this->article, 'genre'))->toBe(['jazz'])
        ->and(slugsOf($this->article, 'label'))->toBe(['live'])
        ->and(slugsOf($this->article, 'tag'))->toBe(['free'])
        ->and(Tagging::query()->pluck('type')->sort()->values()->all())->toBe(['genre', 'label', 'tag'])
        ->and($changes->ofType('genre')->attached)->toHaveCount(1);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('strings_need_a_type_or_the_default', function (): void {
    $this->article->attachTags('loose');

    expect(slugsOf($this->article, 'tag'))->toBe(['loose']);

    config()->set('tags.default_type', '');

    expect(fn () => $this->article->attachTags('loose'))->toThrow(UnknownTagTypeException::class, 'there is no default type')
        ->and(fn () => $this->article->syncTags(['loose']))->toThrow(UnknownTagTypeException::class)
        ->and(UnknownTagTypeException::typeRequired()->errorCode())->toBe('type_required');
});

test('explicit_type_refuses_tags_of_another_type', function (): void {
    $jazz = Tags::type('genre')->make('Jazz')->create();

    expect(fn () => $this->article->attachTags($jazz, 'label'))->toThrow(TagTypeMismatchException::class)
        ->and(fn () => $this->article->detachTags($jazz, 'label'))->toThrow(TagTypeMismatchException::class)
        ->and(fn () => $this->article->syncTags([$jazz], 'label'))->toThrow(TagTypeMismatchException::class);
});

test('curated_types_never_create', function (): void {
    expect(fn () => $this->article->attachTags('Polka', 'genre'))->toThrow(TagNotFoundException::class, 'Polka')
        ->and(fn () => $this->article->syncTags('Polka', 'genre'))->toThrow(TagNotFoundException::class)
        ->and(Tag::query()->count())->toBe(0);
});

test('attach_beyond_max_throws_and_writes_nothing', function (): void {
    $this->article->attachTags(['a', 'b'], 'label');

    expect(fn () => $this->article->attachTags(['c', 'd'], 'label'))->toThrow(TagLimitExceededException::class, 'at most 3 tags')
        ->and(slugsOf($this->article, 'label'))->toBe(['a', 'b'])
        // The doomed call never created c or d.
        ->and(Tag::query()->count())->toBe(2);

    Tags::type('genre')->make('Jazz')->create();
    Tags::type('genre')->make('Rock')->create();
    $this->article->syncTags('jazz', 'genre');

    expect(fn () => $this->article->attachTags('rock', 'genre'))->toThrow(TagLimitExceededException::class, 'only one tag');
});

test('sync_replaces_within_one_type_only', function (): void {
    $this->article->attachTags(['a', 'b'], 'label')->attachTags('loose');

    $changes = Tags::sync($this->article, ['b', 'c'], 'label');

    expect(slugsOf($this->article, 'label'))->toBe(['b', 'c'])
        ->and(slugsOf($this->article, 'tag'))->toBe(['loose'])
        ->and(array_map(static fn (Tag $tag): ?string => $tag->slug, $changes->attached))->toBe(['c'])
        ->and(array_map(static fn (Tag $tag): ?string => $tag->slug, $changes->detached))->toBe(['a'])
        ->and(fn () => $this->article->syncTags(['a', 'b', 'c', 'd'], 'label'))->toThrow(TagLimitExceededException::class);

    $this->article->syncTags([], 'label');

    expect(slugsOf($this->article, 'label'))->toBe([])
        ->and(slugsOf($this->article, 'tag'))->toBe(['loose']);
});

test('sync_of_a_single_type_sets_the_category', function (): void {
    $jazz = Tags::type('genre')->make('Jazz')->create();
    Tags::type('genre')->make('Rock')->create();

    $this->article->syncTags('jazz', 'genre');
    expect($this->article->tagOf('genre')?->is($jazz))->toBeTrue();

    $this->article->syncTags('rock', 'genre');
    expect($this->article->tagOf('genre')?->slug)->toBe('rock');

    $this->article->syncTags(null, 'genre');
    expect($this->article->tagOf('genre'))->toBeNull();
});

test('detach_ignores_unknown_names', function (): void {
    $this->article->attachTags(['a', 'b'], 'label');

    $changes = Tags::detach($this->article, ['a', 'nope', ''], 'label');

    expect(slugsOf($this->article, 'label'))->toBe(['b'])
        ->and($changes->detached)->toHaveCount(1)
        ->and(Tags::detach($this->article, 'a', 'label')->isEmpty())->toBeTrue()
        ->and(Tags::detach($this->article, 999999, 'label')->isEmpty())->toBeTrue();
});

test('clear_without_type_removes_everything', function (): void {
    $this->article->attachTags(['a', 'b'], 'label')->attachTags('loose');

    $this->article->clearTags('label');
    expect(slugsOf($this->article))->toBe(['loose']);

    $this->article->clearTags();
    expect(slugsOf($this->article))->toBe([]);
});

test('checks_run_in_the_documented_order', function (): void {
    $jazz = Tags::type('genre')->make('Jazz')->create();
    Tags::type('genre')->make('Rock')->create();
    $this->article->syncTags($jazz, 'genre');
    $team = Team::query()->create(['name' => 'Acme']);
    $foreign = Tags::type('label')->within($team)->make('Theirs')->create();

    // Resolution before the max: a curated name that does not exist is not found, even though
    // the model already has its one category.
    expect(fn () => $this->article->attachTags('Polka', 'genre'))->toThrow(TagNotFoundException::class)
        // An existing one is over the max.
        ->and(fn () => $this->article->attachTags('rock', 'genre'))->toThrow(TagLimitExceededException::class)
        // The type before the allowed types, the allowed types before the scope.
        ->and(fn () => RestrictedArticle::query()->firstOrFail()->attachTags($jazz, 'label'))->toThrow(TagTypeMismatchException::class)
        ->and(fn () => RestrictedArticle::query()->firstOrFail()->attachTags($jazz))->toThrow(TagTypeNotAllowedException::class)
        ->and(fn () => $this->article->attachTags($foreign, 'label'))->toThrow(TagScopeMismatchException::class)
        ->and(fn () => $this->article->attachTags($foreign, 'nope'))->toThrow(UnknownTagTypeException::class);
});

test('allowed_types_are_enforced_from_both_sides', function (): void {
    Tags::define('article:only')->on(Article::class);
    $restricted = RestrictedArticle::query()->create(['title' => 'Labels only']);
    $note = Note::query()->create(['body' => 'x']);

    $restricted->attachTags('live', ArticleTagType::Label);

    expect(slugsOf($restricted, 'label'))->toBe(['live'])
        ->and(fn () => $restricted->attachTags('x'))->toThrow(TagTypeNotAllowedException::class, 'RestrictedArticle')
        ->and(fn () => $note->attachTags('x', 'article:only'))->toThrow(TagTypeNotAllowedException::class, 'Note')
        ->and(Tags::attach($this->article, 'x', 'article:only')->attached)->toHaveCount(1)
        ->and((new TagTypeNotAllowedException('x'))->errorCode())->toBe('tag_type_not_allowed');
});

test('writes_are_idempotent_and_silent_when_nothing_changes', function (): void {
    $this->article->attachTags('live', 'label');
    $before = Tag::query()->firstOrFail()->usage_count;
    Event::fake([Tagged::class, Untagged::class]);

    $again = Tags::attach($this->article, 'live', 'label');
    $sync = Tags::sync($this->article, ['live'], 'label');
    $detach = Tags::detach($this->article, 'absent', 'label');

    expect($again->isEmpty())->toBeTrue()
        ->and($sync->isEmpty())->toBeTrue()
        ->and($detach->isEmpty())->toBeTrue()
        ->and(Tag::query()->firstOrFail()->usage_count)->toBe($before)
        ->and(Tags::attach($this->article, [], 'label')->isEmpty())->toBeTrue()
        ->and(Tags::detach($this->article, null)->isEmpty())->toBeTrue();

    Event::assertNotDispatched(Tagged::class);
    Event::assertNotDispatched(Untagged::class);
});

test('tagged_and_untagged_fire_after_commit', function (): void {
    $events = [];
    Event::listen(Tagged::class, static function (Tagged $event) use (&$events): void {
        $events[] = 'tagged:'.DB::transactionLevel().':'.count($event->tags);
    });
    Event::listen(Untagged::class, static function (Untagged $event) use (&$events): void {
        $events[] = 'untagged:'.DB::transactionLevel().':'.count($event->tags);
    });

    DB::transaction(function () use (&$events): void {
        $this->article->attachTags(['a', 'b'], 'label');
        $this->article->detachTags('a', 'label');

        expect($events)->toBe([]);
    });

    expect($events)->toBe(['tagged:0:2', 'untagged:0:1']);
});

test('rolled_back_writes_fire_nothing', function (): void {
    Event::fake([Tagged::class]);

    try {
        DB::transaction(function (): void {
            $this->article->attachTags(['a', 'b'], 'label');

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(Tagged::class);

    expect(Tagging::query()->count())->toBe(0)->and(Tag::query()->count())->toBe(0);
});

test('unsaved_models_cannot_be_tagged', function (): void {
    $draft = new Article(['title' => 'Draft']);

    expect(fn () => $draft->attachTags('x'))->toThrow(UnsavedTaggableException::class, 'Save the Article')
        ->and(fn () => Tags::sync($draft, ['x']))->toThrow(UnsavedTaggableException::class)
        ->and(fn () => Tags::detach($draft, ['x']))->toThrow(UnsavedTaggableException::class)
        ->and(fn () => Tags::clear($draft))->toThrow(UnsavedTaggableException::class)
        ->and(UnsavedTaggableException::make('Article')->status())->toBe(500);
});

it('refuses a model whose row is gone', function (): void {
    $gone = Article::query()->create(['title' => 'Gone']);
    Article::query()->whereKey($gone->getKey())->forceDelete();

    expect(fn () => $gone->attachTags('x'))->toThrow(UnsavedTaggableException::class);
});

test('a_mismatched_key_type_fails_fast_naming_the_config_key', function (): void {
    $article = UuidArticle::query()->create(['title' => 'Uuid']);

    expect(fn () => $article->attachTags('x'))->toThrow(InvalidTagsConfigurationException::class, 'tags.key_type');
})->skip(fn (): bool => config('tags.key_type') !== 'bigint', 'the bigint leg');

test('force_deleting_a_taggable_detaches_its_tags_and_decrements_counts', function (): void {
    $note = Note::query()->create(['body' => 'x']);
    $this->article->attachTags(['a', 'b'], 'label');
    $note->attachTags('a', 'label');
    Event::fake([Untagged::class]);

    $this->article->forceDelete();
    $note->delete();

    expect(Tagging::query()->count())->toBe(0)
        ->and(Tags::type('label')->findOrFail('a')->usage_count)->toBe(0)
        ->and(Tags::type('label')->findOrFail('b')->usage_count)->toBe(0);

    Event::assertDispatchedTimes(Untagged::class, 2);
});

test('soft_deleting_a_taggable_keeps_its_tags', function (): void {
    $this->article->attachTags(['a', 'b'], 'label');

    $this->article->delete();

    expect(Tagging::query()->count())->toBe(2)
        ->and(Tags::type('label')->findOrFail('a')->usage_count)->toBe(1);

    $this->article->restore();

    expect(slugsOf($this->article, 'label'))->toBe(['a', 'b']);
});

test('usage_counts_follow_every_write', function (): void {
    $other = Article::query()->create(['title' => 'Other']);
    $note = Note::query()->create(['body' => 'x']);

    $this->article->attachTags(['a', 'b'], 'label');
    $other->attachTags(['a'], 'label');
    $note->attachTags(['a'], 'label');

    $count = static fn (string $slug): int => Tags::type('label')->findOrFail($slug)->usage_count;

    expect($count('a'))->toBe(3)->and($count('b'))->toBe(1);

    $other->detachTags('a', 'label');
    $this->article->syncTags(['b', 'c'], 'label');
    $note->clearTags();

    expect($count('a'))->toBe(0)->and($count('b'))->toBe(1)->and($count('c'))->toBe(1);
});

test('mixed_type_attach_is_atomic', function (): void {
    // Strings carry one type per call, so a call mixes types through models and keys.
    $live = Tags::type('label')->make('Live')->create();
    config()->set('tags.default_type', 'genre');

    expect(fn () => Tags::attach($this->article, [$live, 'Polka']))->toThrow(TagNotFoundException::class)
        ->and(Tagging::query()->count())->toBe(0);

    // Free-form names of one type with a tag of another type over its max: nothing is created
    // or attached.
    config()->set('tags.default_type', 'tag');
    $jazz = Tags::type('genre')->make('Jazz')->create();
    $rock = Tags::type('genre')->make('Rock')->create();
    $this->article->syncTags($jazz, 'genre');

    expect(fn () => Tags::attach($this->article, [$rock, 'brand-new']))->toThrow(TagLimitExceededException::class)
        ->and(Tag::query()->where('type', 'tag')->count())->toBe(0)
        ->and(Tagging::query()->count())->toBe(1);
});

test('return_types_per_layer', function (): void {
    expect(Tags::attach($this->article, 'a', 'label'))->toBeInstanceOf(TagChanges::class)
        ->and(app(AttachTagsAction::class)->execute($this->article, TagSelection::of('b', 'label')))->toBeInstanceOf(TagChanges::class)
        ->and(Tags::for($this->article)->attach('c', 'label'))->toBeInstanceOf(TaggableTags::class)
        ->and($this->article->attachTags('c', 'label'))->toBe($this->article)
        ->and($this->article->detachTags('c', 'label'))->toBe($this->article)
        ->and($this->article->syncTags(['a'], 'label'))->toBe($this->article)
        ->and($this->article->clearTags())->toBe($this->article);
});

test('lowered_max_blocks_attach_but_allows_shrinking', function (): void {
    $this->article->attachTags(['a', 'b', 'c'], 'label');
    config()->set('tags.overrides', ['label' => ['max' => 2]]);
    Tags::extend('label', static fn ($type) => $type);

    expect(fn () => $this->article->attachTags('d', 'label'))->toThrow(TagLimitExceededException::class)
        ->and(Tags::attach($this->article, 'a', 'label')->isEmpty())->toBeTrue()
        ->and(fn () => $this->article->syncTags(['a', 'b', 'c'], 'label'))->toThrow(TagLimitExceededException::class);

    $this->article->detachTags('c', 'label');
    expect(slugsOf($this->article, 'label'))->toBe(['a', 'b']);

    $this->article->syncTags(['a'], 'label');
    expect(slugsOf($this->article, 'label'))->toBe(['a']);
});

it('refuses tag inputs of the wrong shape', function (mixed $input): void {
    $this->article->attachTags($input, 'label');
})->with([
    'float' => [1.5],
    'array of arrays' => [[['x']]],
    'object' => [new stdClass],
])->throws(InvalidArgumentException::class, 'tag model, a key, a name');

it('refuses a blank name for a free-form type', function (): void {
    $this->article->attachTags(['ok', '  '], 'label');
})->throws(InvalidTagException::class, 'needs a name');
