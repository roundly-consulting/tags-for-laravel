<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Handles\TypeTags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Testing\TagsFake;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\EventTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;

beforeEach(function (): void {
    Tags::define('label');
    $this->article = Article::query()->create(['title' => 'Concert']);
});

test('fake_assertions_pass_through_every_layer', function (): void {
    $fake = Tags::fake();

    $this->article->attachTags(['live'], 'label');                 // the trait
    Tags::for($this->article)->attach('outdoor', 'label');         // the handle
    app(TagsManager::class)->sync($this->article, ['live', 'free'], 'label'); // DI
    Tags::detach($this->article, 'free', 'label');                 // the facade
    $this->article->clearTags('tag');

    $fake->assertTagged($this->article, ['live'], type: 'label');
    $fake->assertTagged($this->article, ['outdoor', 'free']);
    $fake->assertUntagged($this->article, ['outdoor', 'free'], 'label');
    $fake->assertSynced($this->article, 'label', ['live']);
    $fake->assertCleared($this->article, 'tag');
    $fake->assertCleared($this->article);

    // Every call ran for real.
    expect($this->article->tagsOf('label')->pluck('slug')->all())->toBe(['live']);
});

test('fake_records_the_deletion_hook_as_a_clear', function (): void {
    $note = Note::query()->create(['body' => 'x']);
    $note->attachTags('x');
    $fake = Tags::fake();

    $note->delete();

    $fake->assertCleared($note);
    $fake->assertUntagged($note, ['x']);
});

test('fake_assertions_fail_with_a_message', function (Closure $assert, string $message): void {
    $fake = Tags::fake();
    $other = Article::query()->create(['title' => 'Other']);
    $this->article->attachTags(['live'], 'label');

    expect(fn () => $assert($fake, $this->article, $other))->toThrow(ExpectationFailedException::class, $message);
})->with([
    'tagged, another model' => [fn (TagsFake $fake, $article, $other) => $fake->assertTagged($other), 'Expected tags to be attached to the model'],
    'tagged, another tag' => [fn (TagsFake $fake, $article) => $fake->assertTagged($article, ['outdoor']), 'tag [outdoor] to be attached'],
    'tagged, another type' => [fn (TagsFake $fake, $article) => $fake->assertTagged($article, null, 'tag'), 'Expected tags to be attached'],
    'nothing tagged' => [fn (TagsFake $fake) => $fake->assertNothingTagged(), 'Expected no tags to be attached, but 1 were.'],
    'untagged' => [fn (TagsFake $fake, $article) => $fake->assertUntagged($article), 'Expected tags to be detached'],
    'synced' => [fn (TagsFake $fake, $article) => $fake->assertSynced($article, 'label'), "Expected the model's [label] tags to be synced"],
    'cleared' => [fn (TagsFake $fake, $article) => $fake->assertCleared($article), "Expected the model's tags to be cleared"],
    'cleared of a type' => [fn (TagsFake $fake, $article) => $fake->assertCleared($article, 'label'), "Expected the model's [label] tags to be cleared"],
]);

test('fake_nothing_assertions_fail_after_the_effect', function (): void {
    $fake = Tags::fake();
    $this->article->attachTags(['live', 'outdoor'], 'label')->detachTags('live', 'label')->syncTags(['outdoor'], 'label')->clearTags();

    expect(fn () => $fake->assertNothingUntagged())->toThrow(ExpectationFailedException::class, 'Expected no tags to be detached, but 2 were.')
        ->and(fn () => $fake->assertNothingSynced())->toThrow(ExpectationFailedException::class, 'Expected no tags to be synced, but 1 syncs were recorded.')
        ->and(fn () => $fake->assertNothingCleared())->toThrow(ExpectationFailedException::class, 'Expected no tags to be cleared, but 1 clears were recorded.')
        ->and(fn () => $fake->assertSynced($this->article, 'label', ['live']))->toThrow(ExpectationFailedException::class, 'synced to 1 tag(s), but it carries 0');
});

test('fake_nothing_assertions_pass_on_an_untouched_fake', function (): void {
    $fake = Tags::fake();

    $fake->assertNothingTagged();
    $fake->assertNothingUntagged();
    $fake->assertNothingSynced();
    $fake->assertNothingCleared();
    $fake->assertNothingHappened();
    $fake->assertNoTagCreated();
    $fake->assertNoTagUpdated();
    $fake->assertNoTagMoved();
    $fake->assertNoTagDeleted();
    $fake->assertNoTagRestored();
    $fake->assertNoTagsReordered();

    expect($fake->recorded())->toBe([]);
});

test('fake_matches_tags_by_model_or_slug', function (): void {
    $fake = Tags::fake();
    $this->article->attachTags(['Live'], 'label');
    $live = Tag::query()->firstOrFail();

    $fake->assertTagged($this->article, [$live]);
    $fake->assertTagged($this->article, ['Live']);
    $fake->assertTagged($this->article, ['live'], 'label');
});

test('fake_naming_rule_is_pinned', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(TagsFake::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'assert'),
        ),
    );

    $pairs = 0;

    foreach ($methods as $method) {
        if ($method === 'assertNothingHappened') {
            continue;
        }

        if (str_starts_with($method, 'assertNothing')) {
            expect($methods)->toContain('assert'.substr($method, strlen('assertNothing')));
        } elseif (str_starts_with($method, 'assertNo')) {
            expect($methods)->toContain('assert'.substr($method, strlen('assertNo')));
        } else {
            $verb = substr($method, strlen('assert'));
            // assert<Verb> ↔ assertNothing<Verb>; assert<Noun><Verb> ↔ assertNo<Noun><Verb>.
            expect(in_array('assertNothing'.$verb, $methods, true) || in_array('assertNo'.$verb, $methods, true))->toBeTrue($method);
            $pairs++;
        }
    }

    expect($pairs)->toBe(11);
});

test('assert_tags_reordered_accepts_an_enum', function (): void {
    Tags::define(EventTagType::Category)->hierarchical();
    $fake = Tags::fake();
    Tags::type(EventTagType::Category)->make('Jazz')->create();
    $rock = Tags::type(EventTagType::Category)->make('Rock')->create();

    Tags::type(EventTagType::Category)->reorder(null, [$rock]);

    $fake->assertTagsReordered(EventTagType::Category);
    $fake->assertTagsReordered('events:category');
    $fake->assertTagsReordered();

    expect(fn () => $fake->assertTagsReordered(EventTagType::Label))
        ->toThrow(ExpectationFailedException::class, 'Expected tags of type [events:label] to be reordered, but none were.');
});

test('fake_covers_every_operation', function (): void {
    Tags::define('genre')->hierarchical();
    // A handle holds the manager it came from: take a new one after each fake.
    $genres = static fn (): TypeTags => Tags::type('genre');

    /** @var array<string, array{0: Closure(): mixed, 1: Closure(TagsFake): void}> $observers */
    $observers = [
        'attach' => [fn () => $this->article->attachTags('live', 'label'), fn (TagsFake $fake) => $fake->assertTagged($this->article)],
        'detach' => [fn () => $this->article->detachTags('live', 'label'), fn (TagsFake $fake) => $fake->assertUntagged($this->article)],
        'sync' => [fn () => $this->article->syncTags(['free'], 'label'), fn (TagsFake $fake) => $fake->assertSynced($this->article, 'label')],
        'clear' => [fn () => $this->article->clearTags(), fn (TagsFake $fake) => $fake->assertCleared($this->article)],
        'create_tag' => [fn () => $genres()->make('Jaz')->create(), fn (TagsFake $fake) => $fake->assertTagCreated()],
        'find_or_create_tag' => [fn () => $genres()->findOrCreate('Rock'), fn (TagsFake $fake) => $fake->assertTagCreated()],
        'update_tag' => [fn () => $genres()->rename($genres()->findOrFail('jaz'), 'Jazz'), fn (TagsFake $fake) => $fake->assertTagUpdated()],
        'move_tag' => [fn () => $genres()->move($genres()->findOrFail('rock'), null, 0), fn (TagsFake $fake) => $fake->assertTagMoved()],
        'reorder_tags' => [fn () => $genres()->reorder(null, [$genres()->findOrFail('jazz')]), fn (TagsFake $fake) => $fake->assertTagsReordered('genre')],
        'delete_tag' => [fn () => $genres()->delete($genres()->findOrFail('rock')), fn (TagsFake $fake) => $fake->assertTagDeleted()],
        'restore_tag' => [fn () => $genres()->restore($genres()->findTrashed('rock') ?? throw new RuntimeException('rock is not deleted')), fn (TagsFake $fake) => $fake->assertTagRestored()],
        'repair' => [fn () => Tags::repair(), fn (TagsFake $fake) => $fake->assertRepaired()],
    ];

    // A new operation without an assertion that observes it fails here.
    expect(array_keys($observers))->toBe(array_map(static fn (TagOperation $operation): string => $operation->value, TagOperation::cases()));

    foreach ($observers as $operation => [$perform, $assert]) {
        $fake = Tags::fake();

        expect(fn () => $assert($fake))->toThrow(ExpectationFailedException::class);

        $perform();
        $assert($fake);

        expect($fake->recorded(TagOperation::from($operation)))->not->toBe([], $operation);
    }
});

test('fake_sees_tags_created_on_the_fly', function (string $verb): void {
    Tags::type('label')->findOrCreate('Existing');
    $fake = Tags::fake();

    $verb === 'attach'
        ? $this->article->attachTags(['Brand new', 'Existing'], 'label')
        : $this->article->syncTags(['Brand new', 'Existing'], 'label');

    $fake->assertTagCreated(fn (Tag $tag): bool => $tag->slug === 'brand-new');
    $fake->assertNoTagRestored();

    expect(fn () => $fake->assertTagCreated(fn (Tag $tag): bool => $tag->slug === 'existing'))
        ->toThrow(ExpectationFailedException::class, 'none matched the callback')
        ->and(fn () => $fake->assertNoTagCreated())
        ->toThrow(ExpectationFailedException::class, 'Expected no tag to be created, but 1 were.');

    // Existing tags only: nothing created.
    $again = Tags::fake();
    Article::query()->create()->attachTags(['Brand new', 'Existing'], 'label');

    $again->assertNoTagCreated();
})->with(['attach', 'sync']);

test('fake_sees_tags_restored_on_the_fly', function (string $via): void {
    $old = Tags::type('label')->make('Old')->create();
    Tags::type('label')->delete($old);
    $fake = Tags::fake();

    match ($via) {
        'attach' => $this->article->attachTags('Old', 'label'),
        'sync' => $this->article->syncTags(['Old'], 'label'),
        'find or create' => Tags::type('label')->findOrCreate('Old'),
        'create' => Tags::type('label')->make('Old')->create(),
    };

    $fake->assertTagRestored(fn (Tag $tag): bool => $tag->is($old) && $tag->wasRecentlyRestored);
    $fake->assertNoTagCreated();

    expect(fn () => $fake->assertNoTagRestored())->toThrow(ExpectationFailedException::class, 'Expected no tag to be restored, but 1 were.')
        ->and(Tag::query()->findOrFail($old->getKey())->wasRecentlyRestored)->toBeFalse();
})->with(['attach', 'sync', 'find or create', 'create']);

test('tag_changes_list_created_and_restored_tags', function (): void {
    Tags::define('genre');
    $old = Tags::type('label')->make('Old')->create();
    Tags::type('label')->delete($old);
    Tags::type('label')->findOrCreate('Existing');

    $attach = Tags::attach($this->article, ['New', 'Old', 'Existing'], 'label');
    $sync = Tags::sync(Article::query()->create(), ['Fresh', 'New'], 'label');
    $detach = Tags::detach($this->article, 'New', 'label');

    expect(array_map(fn (Tag $tag): ?string => $tag->slug, $attach->created))->toBe(['new'])
        ->and(array_map(fn (Tag $tag): ?string => $tag->slug, $attach->restored))->toBe(['old'])
        ->and($attach->created[0])->toBe(collect($attach->attached)->firstWhere('slug', 'new'))
        ->and($attach->created[0]->wasRecentlyCreated)->toBeTrue()
        ->and($attach->restored[0]->wasRecentlyRestored)->toBeTrue()
        ->and(array_map(fn (Tag $tag): ?string => $tag->slug, $sync->created))->toBe(['fresh'])
        ->and($sync->restored)->toBe([])
        ->and($detach->created)->toBe([])
        ->and($detach->restored)->toBe([])
        ->and($detach->isEmpty())->toBeFalse();
});

test('tag_changes_of_type_filters_created_tags', function (): void {
    Tags::define('genre');
    $article = Article::query()->create();
    $genre = Tags::type('genre')->make('Jazz')->create();
    $changes = Tags::attach($article, ['New'], 'label');
    $combined = new TagChanges($article, [...$changes->attached, $genre], [], [...$changes->created, $genre], [$genre]);

    expect(array_map(fn (Tag $tag): ?string => $tag->slug, $combined->ofType('label')->created))->toBe(['new'])
        ->and($combined->ofType('label')->restored)->toBe([])
        ->and(array_map(fn (Tag $tag): ?string => $tag->slug, $combined->ofType('genre')->restored))->toBe(['jazz']);
});

// A model loaded with its tags (route binding, a resource) then written through a flat verb:
// later reads see the write, under the fake and without it.
test('flat_verbs_drop_the_loaded_relation', function (bool $faked): void {
    $faked && Tags::fake();
    $this->article->load('tags');

    Tags::attach($this->article, ['live'], 'label');
    expect($this->article->tagsOf('label')->pluck('slug')->all())->toBe(['live']);

    Tags::sync($this->article, ['free'], 'label');
    expect($this->article->tagOf('label')?->slug)->toBe('free');

    $this->article->load('tags');
    Tags::detach($this->article, 'free', 'label');
    expect($this->article->tagsOf('label'))->toHaveCount(0);

    Tags::attach($this->article, ['rock']);
    $this->article->load('tags');
    Tags::clear($this->article);
    expect($this->article->tagsOf('tag'))->toHaveCount(0);
})->with(['real' => false, 'faked' => true]);

test('assert_synced_reads_fresh_tags', function (): void {
    $fake = Tags::fake();
    $this->article->load('tags');

    Tags::sync($this->article, ['rock']);

    $fake->assertSynced($this->article, 'tag', ['rock']);

    // A stale loaded relation that still lists the old set never lets the assertion pass.
    $this->article->attachTags(['jazz']);
    $this->article->setRelation('tags', new Collection([Tag::query()->where('slug->en', 'rock')->firstOrFail()]));

    expect(fn () => $fake->assertSynced($this->article, 'tag', ['rock']))->toThrow(ExpectationFailedException::class);
});

// A call that changed nothing is no restore, update or delete: the catalog assertions check what
// really happened.
test('fake_ignores_no_op_catalog_calls', function (): void {
    $live = Tags::type('label')->make('Live')->create();
    $fake = Tags::fake();
    $labels = static fn (): TypeTags => Tags::type('label');

    $labels()->restore($live);
    $labels()->rename($live, 'Live');

    $fake->assertNoTagRestored();
    $fake->assertNoTagUpdated();

    $labels()->delete($live);
    $labels()->delete($live);

    $fake->assertTagDeleted();
    expect(fn () => $fake->assertNoTagDeleted())->toThrow(ExpectationFailedException::class, 'but 1 were.');

    $labels()->restore($live);
    $labels()->rename($live, 'Live music');

    $fake->assertTagRestored(fn (Tag $tag): bool => $tag->is($live));
    $fake->assertTagUpdated(fn (Tag $tag): bool => $tag->name === 'Live music');
});

// A move to where the tag already is, or a reorder to the current order, fires no event: the fake's
// move and reorder assertions skip it too, and `recorded()` still lists it.
test('fake_ignores_no_op_moves_and_reorders', function (): void {
    Tags::define('genre')->hierarchical();
    $music = Tags::type('genre')->make('Music')->create();
    $rock = Tags::type('genre')->make('Rock')->create();
    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    $fake = Tags::fake();
    $genres = static fn (): TypeTags => Tags::type('genre');

    $genres()->move($jazz, $music, 0);
    $genres()->move($rock, null, 1);
    $genres()->move($rock, null);
    $genres()->reorder(null, [$music, $rock]);
    $genres()->reorder($music, [$jazz]);

    $fake->assertNoTagMoved();
    $fake->assertNoTagsReordered();
    expect($fake->recorded(TagOperation::MoveTag))->toHaveCount(3)
        ->and($fake->recorded(TagOperation::ReorderTags))->toHaveCount(2)
        ->and(fn () => $fake->assertTagMoved())->toThrow(ExpectationFailedException::class, 'Expected a tag to be moved, but none was.')
        ->and(fn () => $fake->assertTagsReordered())->toThrow(ExpectationFailedException::class, 'Expected tags to be reordered, but none were.');

    $genres()->move($rock, null, 0);
    $genres()->reorder(null, [$music, $rock]);

    $fake->assertTagMoved(fn (Tag $tag): bool => $tag->is($rock));
    $fake->assertTagsReordered('genre');
    expect(fn () => $fake->assertNoTagMoved())->toThrow(ExpectationFailedException::class, 'but 1 were.')
        ->and(fn () => $fake->assertNoTagsReordered())->toThrow(ExpectationFailedException::class, 'but 1 reorders');
});

// A clear of every type (`clearTags()`, the deletion hook) clears each type too.
test('assert_cleared_of_a_type_accepts_a_clear_of_every_type', function (): void {
    $note = Note::query()->create(['body' => 'x']);
    $note->attachTags('x');
    $fake = Tags::fake();
    $this->article->attachTags(['live'], 'label');

    $this->article->clearTags();
    $note->delete();

    $fake->assertCleared($this->article, 'label');
    $fake->assertCleared($note, 'tag');
});
