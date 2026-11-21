<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tags\Actions\Catalog\MergeTagAction;
use RoundlyConsulting\Tags\DataTransferObjects\TagMerge;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Events\TagDeleted;
use RoundlyConsulting\Tags\Events\TagMerged;
use RoundlyConsulting\Tags\Events\TagMoved;
use RoundlyConsulting\Tags\Events\Untagged;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagMergeException;
use RoundlyConsulting\Tags\Exceptions\TagHasChildrenException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

/**
 * Every row of both tables, so a refused merge can prove it changed nothing.
 *
 * @return array{tags: list<array<string, mixed>>, taggings: list<array<string, mixed>>}
 */
function mergeSnapshot(): array
{
    return [
        'tags' => Tag::query()->withTrashed()->orderBy('id')->toBase()->get()->map(fn (object $row): array => (array) $row)->all(),
        'taggings' => Tagging::query()->orderBy('tag_id')->orderBy('taggable_type')->orderBy('taggable_id')->toBase()->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * @return list<string> `type:key` of every record carrying the tag
 */
function taggedWith(Tag $tag): array
{
    return Tagging::query()->where('tag_id', $tag->getKey())->orderBy('taggable_type')->orderBy('taggable_id')->get()
        ->map(fn (Tagging $tagging): string => $tagging->taggable_type.':'.$tagging->taggable_id)->all();
}

beforeEach(function (): void {
    $this->acme = Team::query()->create(['name' => 'Acme']);
    $this->globex = Team::query()->create(['name' => 'Globex']);
    $this->tags = Tags::type('tag');
});

test('moves_every_tagging_across_taggable_types_and_owners', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Rock music')->create();

    Carbon::setTestNow('2026-01-01 10:00:00');
    $article = Article::query()->create();
    $article->attachTags($from);
    $note = Note::query()->create();
    $note->attachTags($from);
    $acmeTicket = ScopedArticle::query()->create(['team_id' => $this->acme->getKey()]);
    $acmeTicket->attachTags($from);

    Carbon::setTestNow('2026-02-01 10:00:00');
    $both = ScopedArticle::query()->create(['team_id' => $this->globex->getKey()]);
    $both->attachTags([$from, $into]);
    $onlyInto = Article::query()->create();
    $onlyInto->attachTags($into);
    Carbon::setTestNow();

    $merge = $this->tags->merge($from, $into);

    expect($merge)->toBeInstanceOf(TagMerge::class)
        ->and($merge->moved)->toBe(3)
        ->and($merge->deduplicated)->toBe(1)
        ->and($merge->into->is($into))->toBeTrue()
        ->and($merge->into->usage_count)->toBe(5)
        ->and($merge->from->is($from))->toBeTrue()
        ->and($merge->from->trashed())->toBeTrue()
        ->and($merge->from->usage_count)->toBe(0)
        ->and(taggedWith($from))->toBe([])
        ->and(Tagging::query()->where('tag_id', $into->getKey())->count())->toBe(5)
        ->and($article->tagsOf('tag')->modelKeys())->toBe([$into->getKey()])
        ->and($note->tagsOf('tag')->modelKeys())->toBe([$into->getKey()])
        ->and($acmeTicket->tagsOf('tag')->modelKeys())->toBe([$into->getKey()])
        ->and($both->tagsOf('tag')->modelKeys())->toBe([$into->getKey()])
        ->and($into->fresh()?->usage_count)->toBe(5)
        ->and($from->fresh()?->trashed())->toBeTrue()
        ->and(Tag::onlyTrashed()->find($from->getKey())?->usage_count)->toBe(0);

    // The pivot rows moved, never re-inserted: their own columns stay.
    $createdAt = fn (object $record): ?string => Tagging::query()
        ->where('taggable_type', $record->getMorphClass())->where('taggable_id', $record->getKey())
        ->first()?->created_at?->toDateTimeString();

    expect($createdAt($article))->toBe('2026-01-01 10:00:00')
        ->and($createdAt($note))->toBe('2026-01-01 10:00:00')
        ->and($createdAt($acmeTicket))->toBe('2026-01-01 10:00:00')
        ->and($createdAt($both))->toBe('2026-02-01 10:00:00');
});

// A tagging whose model class is gone still moves: no write can tag such a record, so there is
// no row to lock.
test('moves_a_tagging_whose_model_class_is_gone', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Pop')->create();
    Tagging::query()->insert(['tag_id' => $from->getKey(), 'taggable_type' => 'gone_model', 'taggable_id' => 7, 'type' => 'tag']);

    $merge = $this->tags->merge($from, $into);

    expect($merge->moved)->toBe(1)
        ->and(taggedWith($into))->toBe(['gone_model:7']);
});

test('recounts_the_target_from_the_pivot_instead_of_adding', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Pop')->create();
    Article::query()->create()->attachTags($from);
    Article::query()->create()->attachTags([$from, $into]);
    Tag::query()->whereKey($into->getKey())->toBase()->update(['usage_count' => 40]);

    $merge = $this->tags->merge($from, $into);

    expect($merge->into->usage_count)->toBe(2)
        ->and($into->fresh()?->usage_count)->toBe(2)
        ->and([$merge->moved, $merge->deduplicated])->toBe([1, 1]);
});

test('merges_an_unused_tag', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Pop')->create();
    Article::query()->create()->attachTags($into);

    $merge = $this->tags->merge($from, $into);

    expect([$merge->moved, $merge->deduplicated, $merge->into->usage_count])->toBe([0, 0, 1])
        ->and($from->fresh()?->trashed())->toBeTrue();
});

test('a_scoped_handle_merges_a_scope_tag_into_a_global_one', function (): void {
    $acmeTags = $this->tags->within($this->acme);
    $from = $acmeTags->make('Printer')->create();
    $into = $this->tags->make('Printers')->create();
    $ticket = ScopedArticle::query()->create(['team_id' => $this->acme->getKey()]);
    $ticket->attachTags($from);

    $merge = $acmeTags->merge($from, $into);

    expect($merge->moved)->toBe(1)
        ->and($ticket->tagsOf('tag')->modelKeys())->toBe([$into->getKey()]);
});

test('the_children_of_the_merged_tag_are_refused_by_default', function (): void {
    Tags::define('genre')->hierarchical();
    $genres = Tags::type('genre');
    $from = $genres->make('Jazz')->create();
    $genres->make('Bebop')->under($from)->create();
    $into = $genres->make('Music')->create();
    $before = mergeSnapshot();

    expect(fn () => $genres->merge($from, $into))
        ->toThrow(TagHasChildrenException::class, 'The tag [jazz] has children: move them to the tag it merges into, or delete them with it.')
        ->and(mergeSnapshot())->toBe($before);
});

test('reparent_moves_the_children_under_the_target', function (): void {
    Tags::define('genre')->hierarchical();
    $genres = Tags::type('genre');
    $music = $genres->make('Music')->create();
    $rock = $genres->make('Rock')->under($music)->create();
    $from = $genres->make('Jazz')->create();
    $bebop = $genres->make('Bebop')->under($from)->create();
    $cool = $genres->make('Cool')->under($bebop)->create();
    $swing = $genres->make('Swing')->under($from)->create();
    $gone = $genres->make('Gone')->under($from)->create();
    $genres->delete($gone);
    $article = Article::query()->create();
    $article->attachTags([$from, $bebop], 'genre');

    $moved = [];
    Event::listen(TagMoved::class, function (TagMoved $event) use (&$moved): void {
        $moved[] = $event->tag->slug;
    });
    $merged = [];
    Event::listen(TagMerged::class, function (TagMerged $event) use (&$merged): void {
        $merged[] = $event;
    });

    $merge = $genres->merge($from, $music, TagChildren::Reparent);
    $prefix = '/'.$music->getKey().'/';

    expect($merge->moved)->toBe(1)
        ->and($music->children()->get()->pluck('slug')->all())->toBe(['rock', 'bebop', 'swing'])
        ->and($rock->fresh()?->position)->toBe(0)
        ->and($bebop->fresh()?->path)->toBe($prefix)
        ->and($bebop->fresh()?->depth)->toBe(2)
        ->and($bebop->fresh()?->position)->toBe(1)
        ->and($swing->fresh()?->path)->toBe($prefix)
        ->and($swing->fresh()?->position)->toBe(2)
        ->and($cool->fresh()?->path)->toBe($prefix.$bebop->getKey().'/')
        ->and($cool->fresh()?->depth)->toBe(3)
        // A deleted child stays with the deleted tag, as after a delete.
        ->and(Tag::onlyTrashed()->find($gone->getKey())?->parent_id)->toBe($from->getKey())
        ->and($article->tagsOf('genre')->pluck('slug')->sort()->values()->all())->toBe(['bebop', 'music'])
        ->and($moved)->toBe([])
        ->and($merged)->toHaveCount(1)
        ->and(array_map(fn (Tag $tag): string => (string) $tag->slug, $merged[0]->reparented))->toBe(['bebop', 'swing'])
        ->and($merged[0]->deleted)->toBe([]);
});

test('reparent_is_refused_when_the_children_would_sit_too_deep', function (): void {
    Tags::define('genre')->hierarchical(3);
    $genres = Tags::type('genre');
    $music = $genres->make('Music')->create();
    $rock = $genres->make('Rock')->under($music)->create();
    $from = $genres->make('Jazz')->create();
    $bebop = $genres->make('Bebop')->under($from)->create();
    $genres->make('Cool')->under($bebop)->create();
    $before = mergeSnapshot();

    expect(fn () => $genres->merge($from, $rock, TagChildren::Reparent))
        ->toThrow(InvalidTagHierarchyException::class)
        ->and(mergeSnapshot())->toBe($before);

    // One level higher fits.
    expect($genres->merge($from, $music, TagChildren::Reparent)->into->is($music))->toBeTrue();
});

test('reparent_across_scopes_is_refused', function (): void {
    Tags::define('genre')->hierarchical();
    $acmeGenres = Tags::type('genre')->within($this->acme);
    $from = $acmeGenres->make('Jazz')->create();
    $acmeGenres->make('Bebop')->under($from)->create();
    $into = Tags::type('genre')->make('Music')->create();
    $before = mergeSnapshot();

    expect(fn () => $acmeGenres->merge($from, $into, TagChildren::Reparent))
        ->toThrow(InvalidTagMergeException::class, 'The children of a tag cannot move to a tag of another scope')
        ->and(mergeSnapshot())->toBe($before);

    // Their deletion works across scopes.
    expect($acmeGenres->merge($from, $into, TagChildren::Delete)->from->trashed())->toBeTrue();
});

test('delete_takes_the_subtree_with_its_taggings', function (): void {
    Tags::define('genre')->hierarchical();
    $genres = Tags::type('genre');
    $into = $genres->make('Music')->create();
    $from = $genres->make('Jazz')->create();
    $bebop = $genres->make('Bebop')->under($from)->create();
    $cool = $genres->make('Cool')->under($bebop)->create();
    $article = Article::query()->create();
    $article->attachTags([$from, $cool], 'genre');
    $merged = [];
    Event::listen(TagMerged::class, function (TagMerged $event) use (&$merged): void {
        $merged[] = $event;
    });

    $merge = $genres->merge($from, $into, TagChildren::Delete);

    expect($merge->moved)->toBe(1)
        ->and($article->tagsOf('genre')->pluck('slug')->all())->toBe(['music'])
        ->and(Tag::query()->where('type', 'genre')->get()->pluck('slug')->all())->toBe(['music'])
        ->and(Tag::onlyTrashed()->where('type', 'genre')->count())->toBe(3)
        ->and(Tag::onlyTrashed()->find($cool->getKey())?->usage_count)->toBe(0)
        ->and(taggedWith($cool))->toBe([])
        ->and(array_map(fn (Tag $tag): string => (string) $tag->slug, $merged[0]->deleted))->toBe(['bebop', 'cool'])
        ->and($merged[0]->reparented)->toBe([]);
});

test('a_merge_without_children_ignores_the_children_mode', function (TagChildren $children): void {
    Tags::define('genre')->hierarchical();
    $genres = Tags::type('genre');
    $from = $genres->make('Jazz')->create();
    $into = $genres->make('Music')->create();
    $sibling = $genres->make('Rock')->create();

    $genres->merge($from, $into, $children);

    expect($from->fresh()?->trashed())->toBeTrue()
        ->and($sibling->fresh()?->trashed())->toBeFalse()
        ->and($into->fresh()?->depth)->toBe(1);
})->with([TagChildren::Refuse, TagChildren::Reparent, TagChildren::Delete]);

// Each case sets up its tags, then hands back the merge to refuse.
test('every_refused_merge_changes_nothing', function (Closure $prepare, string $exception, string $message): void {
    Tags::define('genre')->hierarchical();
    Tags::define('label');
    $article = Article::query()->create();
    $article->attachTags(['Rock', 'Pop']);
    $merge = $prepare($this);
    $before = mergeSnapshot();

    expect($merge)->toThrow($exception, $message)
        ->and(mergeSnapshot())->toBe($before);
})->with([
    'the same tag' => [
        fn ($test) => fn () => $test->tags->merge($test->tags->findOrFail('rock'), $test->tags->findOrFail('rock')),
        InvalidTagMergeException::class, 'A tag cannot be merged into itself.',
    ],
    'the same tag, another instance' => [
        fn ($test) => fn () => $test->tags->merge($test->tags->findOrFail('rock'), Tag::query()->findOrFail($test->tags->findOrFail('rock')->getKey())),
        InvalidTagMergeException::class, 'A tag cannot be merged into itself.',
    ],
    'a source of another type' => [
        function ($test) {
            $from = Tags::type('label')->make('Rock')->create();

            return fn () => $test->tags->merge($from, $test->tags->findOrFail('pop'));
        },
        TagTypeMismatchException::class, 'Expected a tag of type [tag], got one of type [label].',
    ],
    'a target of another type' => [
        function ($test) {
            $into = Tags::type('label')->make('Pop')->create();

            return fn () => $test->tags->merge($test->tags->findOrFail('rock'), $into);
        },
        TagTypeMismatchException::class, 'Expected a tag of type [tag], got one of type [label].',
    ],
    'a target of another type, straight to the action' => [
        function ($test) {
            $into = Tags::type('label')->make('Pop')->create();

            return fn () => app(MergeTagAction::class)->execute($test->tags->findOrFail('rock'), $into);
        },
        TagTypeMismatchException::class, 'Expected a tag of type [tag], got one of type [label].',
    ],
    'a target of another scope' => [
        function ($test) {
            $from = $test->tags->within($test->acme)->make('Fax')->create();
            $into = $test->tags->within($test->globex)->make('Faxes')->create();

            return fn () => $test->tags->within($test->acme)->merge($from, $into);
        },
        TagScopeMismatchException::class, 'The tag [faxes] belongs to a scope this handle does not read.',
    ],
    'a global target from a handle that reads no global tags' => [
        function ($test) {
            $from = $test->tags->within($test->acme)->make('Fax')->create();

            return fn () => $test->tags->within($test->acme, includeGlobal: false)->merge($from, $test->tags->findOrFail('pop'));
        },
        TagScopeMismatchException::class, 'The tag [pop] belongs to a scope this handle does not read.',
    ],
    'a global source from a scoped handle' => [
        function ($test) {
            $into = $test->tags->within($test->acme)->make('Fax')->create();

            return fn () => $test->tags->within($test->acme)->merge($test->tags->findOrFail('rock'), $into);
        },
        TagScopeMismatchException::class, 'The tag [rock] belongs to another scope than this handle writes to.',
    ],
    'a scoped source from a global handle' => [
        function ($test) {
            $from = $test->tags->within($test->acme)->make('Fax')->create();

            return fn () => $test->tags->merge($from, $test->tags->findOrFail('pop'));
        },
        TagScopeMismatchException::class, 'The tag [fax] belongs to another scope than this handle writes to.',
    ],
    'a deleted source' => [
        function ($test) {
            $from = $test->tags->make('Jazz')->create();
            $test->tags->delete($from);

            return fn () => $test->tags->merge($from, $test->tags->findOrFail('pop'));
        },
        TagNotFoundException::class, 'The tag is deleted.',
    ],
    'a deleted target' => [
        function ($test) {
            $into = $test->tags->make('Jazz')->create();
            $test->tags->delete($into);

            return fn () => $test->tags->merge($test->tags->findOrFail('rock'), $into);
        },
        TagNotFoundException::class, 'The tag is deleted.',
    ],
    'a source deleted since it was loaded' => [
        function ($test) {
            $from = $test->tags->make('Jazz')->create();
            Tag::query()->whereKey($from->getKey())->toBase()->update(['deleted_at' => now()]);

            return fn () => $test->tags->merge($from, $test->tags->findOrFail('pop'));
        },
        TagNotFoundException::class, 'The tag is deleted.',
    ],
    'a target gone since it was loaded' => [
        function ($test) {
            $into = $test->tags->make('Jazz')->create();
            Tag::query()->whereKey($into->getKey())->forceDelete();

            return fn () => $test->tags->merge($test->tags->findOrFail('rock'), $into);
        },
        TagNotFoundException::class, 'The tag does not exist.',
    ],
    'a target below the source' => [
        function ($test) {
            $genres = Tags::type('genre');
            $music = $genres->make('Music')->create();
            $rock = $genres->make('Rock')->under($music)->create();
            $punk = $genres->make('Punk')->under($rock)->create();

            return fn () => $genres->merge($music, $punk, TagChildren::Reparent);
        },
        InvalidTagMergeException::class, 'A tag cannot be merged into one of its own descendants.',
    ],
    'a target that is the source child' => [
        function ($test) {
            $genres = Tags::type('genre');
            $music = $genres->make('Music')->create();
            $rock = $genres->make('Rock')->under($music)->create();

            return fn () => $genres->merge($music, $rock, TagChildren::Delete);
        },
        InvalidTagMergeException::class, 'A tag cannot be merged into one of its own descendants.',
    ],
]);

test('tag_merged_fires_once_after_commit_and_never_on_rollback', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Pop')->create();
    Article::query()->create()->attachTags([$from, $into]);
    Article::query()->create()->attachTags($from);
    $merged = [];
    $deleted = 0;
    $untagged = 0;
    Event::listen(TagMerged::class, function (TagMerged $event) use (&$merged): void {
        $merged[] = $event;
    });
    Event::listen(TagDeleted::class, function () use (&$deleted): void {
        $deleted++;
    });
    Event::listen(Untagged::class, function () use (&$untagged): void {
        $untagged++;
    });

    // Rolled back: nothing changed, nothing announced.
    $before = mergeSnapshot();

    try {
        DB::transaction(function () use ($from, $into): never {
            $this->tags->merge($from, $into);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($merged)->toBe([])
        ->and(mergeSnapshot())->toBe($before);

    // Committed: announced once, after the commit.
    DB::transaction(function () use ($from, $into, &$merged): void {
        $this->tags->merge($from, $into);

        expect($merged)->toBe([]);
    });

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->from->is($from))->toBeTrue()
        ->and($merged[0]->from->trashed())->toBeTrue()
        ->and($merged[0]->into->is($into))->toBeTrue()
        ->and($merged[0]->moved)->toBe(1)
        ->and($merged[0]->deduplicated)->toBe(1)
        ->and($deleted)->toBe(0)
        ->and($untagged)->toBe(0);
});

test('restoring_the_merged_tag_brings_back_no_tagging', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Pop')->create();
    $article = Article::query()->create();
    $article->attachTags($from);

    $this->tags->merge($from, $into);
    $restored = $this->tags->restore($this->tags->findTrashed('rock') ?? throw new RuntimeException('rock is not deleted'));

    expect($restored->trashed())->toBeFalse()
        ->and($restored->usage_count)->toBe(0)
        ->and(taggedWith($restored))->toBe([])
        ->and($article->tagsOf('tag')->modelKeys())->toBe([$into->getKey()]);
});

test('a_merged_tag_can_no_longer_be_attached', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Pop')->create();
    $this->tags->merge($from, $into);

    expect(fn () => Article::query()->create()->attachTags($from))->toThrow(TagNotFoundException::class)
        ->and(taggedWith($from))->toBe([]);
});

test('the_fake_records_merges', function (): void {
    $from = $this->tags->make('Rock')->create();
    $into = $this->tags->make('Pop')->create();
    Article::query()->create()->attachTags($from);
    $fake = Tags::fake();

    $fake->assertNoTagMerged();

    Tags::type('tag')->merge($from, $into);

    $fake->assertTagMerged();
    $fake->assertTagMerged(fn (TagMerge $merge): bool => $merge->into->is($into) && $merge->moved === 1);
    $fake->assertNoTagDeleted();

    expect(fn () => $fake->assertTagMerged(fn (TagMerge $merge): bool => $merge->moved === 2))
        ->toThrow(ExpectationFailedException::class, 'Expected a tag to be merged, but none matched the callback.')
        ->and(fn () => $fake->assertNoTagMerged())
        ->toThrow(ExpectationFailedException::class, 'Expected no tag to be merged, but 1 were.');
});
