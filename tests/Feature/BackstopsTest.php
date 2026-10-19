<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tags\Actions\Internal\ResolveTagsAction;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Events\Untagged;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagTypeException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Resources\TagResource;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\Doctor;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\RemoteNote;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\UntaggableNote;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/*
 * Race backstops and defensive branches, made deterministic on SQLite (the real-engine legs reach
 * the races for real, but measure no coverage). Each race is staged in one session: a query
 * listener or a model listener writes what a concurrent writer would have, at the moment it would.
 *
 * Accepted as engine-only or unreachable, not chased here:
 * - Support\LockedRow: the MySQL/MariaDB upsert branch (runs on the mysql leg).
 * - Testing\TagExpectations: the guard for a suite without Pest.
 * - Types\TagTypeRegistry::caller(): the fallback when no frame outside the package defined a type.
 */

beforeEach(function (): void {
    Tags::define('genre')->hierarchical();
    Tags::define('label');
});

/**
 * A global tag row written past the package, as a concurrent writer would have.
 */
function insertRawTag(string $type, string $name, string $slug): void
{
    TagModel::query()->toBase()->insert([
        'type' => $type,
        'scope_key' => '',
        'path' => '/',
        'depth' => 1,
        'position' => 9,
        'usage_count' => 0,
        'name' => json_encode(['en' => $name]),
        'slug' => json_encode(['en' => $slug]),
    ]);
}

/**
 * Write a winning row the first time a query matching `$needle` runs.
 */
function insertRawTagAfter(string $needle, string $type, string $name, string $slug): void
{
    $armed = true;

    DB::listen(static function (QueryExecuted $query) use (&$armed, $needle, $type, $name, $slug): void {
        if ($armed && str_contains($query->sql, $needle)) {
            $armed = false;
            insertRawTag($type, $name, $slug);
        }
    });
}

test('store_returns_a_tag_created_after_resolution', function (): void {
    $type = app(TagTypeRegistry::class)->resolve('label');
    $live = Tags::type('label')->make('Rock')->create();

    $stored = TagTransaction::run(static fn () => TagStore::store(new TagDraft($type, null, '', ['en' => 'Rock']), 'rock'));

    expect($stored->tag->is($live))->toBeTrue()
        ->and($stored->created)->toBeFalse()
        ->and($stored->restored)->toBeFalse();
});

test('store_recovers_from_a_unique_violation', function (): void {
    // After the holders probe: the next query (the sibling position) is where a racer commits.
    insertRawTagAfter('max(', 'label', 'Rock', 'rock');

    $found = Tags::type('label')->findOrCreate('Rock');

    expect($found->wasRecentlyCreated)->toBeFalse()
        ->and($found->position)->toBe(9)
        ->and(Tag::query()->where('type', 'label')->count())->toBe(1);

    insertRawTagAfter('max(', 'label', 'Jazz', 'jazz');

    expect(fn () => Tags::type('label')->make('Jazz')->create())
        ->toThrow(DuplicateTagException::class, 'A tag with the slug [jazz] in [en] already exists.');
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'raw rows need a key on uuid installs');

test('duplicate_without_a_matching_locale_is_generic', function (): void {
    $holder = Tags::type('label')->make('Rock')->create();
    $duplicate = new ReflectionMethod(TagStore::class, 'duplicate');

    expect($duplicate->invoke(null, $holder, ['en' => 'jazz'], false)->getMessage())->toBe(DuplicateTagException::generic()->getMessage())
        ->and($duplicate->invoke(null, $holder, ['en' => 'rock'], true)->getMessage())->toContain('restore that tag');
});

test('rename_recovers_from_a_unique_violation', function (): void {
    $jazz = Tags::type('label')->make('Jazz')->create();
    $armed = true;
    Event::listen('eloquent.updating: '.TagModel::class(), static function () use (&$armed): void {
        if ($armed) {
            $armed = false;
            insertRawTag('label', 'Rock', 'rock');
        }
    });

    expect(fn () => Tags::type('label')->rename($jazz, 'Rock'))->toThrow(DuplicateTagException::class, DuplicateTagException::generic()->getMessage())
        ->and($jazz->fresh()?->slug)->toBe('jazz');
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'raw rows need a key on uuid installs');

test('find_or_create_returns_a_tag_created_between_lookups', function (): void {
    // The unlocked lookup misses; the tag commits before the locked one.
    insertRawTagAfter('slug', 'label', 'Rock', 'rock');

    $rock = Tags::type('label')->findOrCreate('Rock');

    expect($rock->wasRecentlyCreated)->toBeFalse()
        ->and($rock->position)->toBe(9)
        ->and(Tag::query()->count())->toBe(1);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'raw rows need a key on uuid installs');

test('writes_on_a_vanished_tag_are_not_found', function (string $write): void {
    $music = Tags::type('genre')->make('Music')->create();
    $rock = Tags::type('label')->make('Rock')->create();
    $old = Tags::type('label')->make('Old')->create();
    $old = Tags::type('label')->delete($old);

    $vanish = static fn (Tag $tag) => TagModel::query()->withTrashed()->whereKey($tag->getKey())->toBase()->delete();

    $call = match ($write) {
        'delete' => function () use ($rock, $vanish) {
            $vanish($rock);

            return Tags::type('label')->delete($rock);
        },
        'restore' => function () use ($old, $vanish) {
            $vanish($old);

            return Tags::type('label')->restore($old);
        },
        'reorder' => function () use ($music, $vanish) {
            $vanish($music);

            return Tags::type('genre')->reorder($music, []);
        },
    };

    expect($call)->toThrow(TagNotFoundException::class);
})->with(['delete', 'restore', 'reorder']);

test('detach_skips_a_deleted_tag', function (): void {
    $article = Article::query()->create();
    $article->attachTags(['rock', 'pop'], 'label');
    $rock = Tags::type('label')->findOrFail('rock');
    $deleted = Tags::type('label')->delete($rock);

    expect(Tags::detach($article, [$deleted, 'pop'], 'label')->detached)->toHaveCount(1)
        ->and($article->tagsOf('label'))->toHaveCount(0);
});

test('tag_inputs_of_an_undefined_type_are_refused', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    $ghost = Tag::query()->create(['type' => 'ghost', 'name' => ['en' => 'Ghost'], 'path' => '/', 'depth' => 1, 'position' => 0, 'usage_count' => 0]);
    $theirs = Tag::query()->create(['type' => 'ghost', 'scope_type' => $globex->getMorphClass(), 'scope_id' => $globex->getKey(), 'name' => ['en' => 'Theirs'], 'path' => '/', 'depth' => 1, 'position' => 0, 'usage_count' => 0]);
    $resolve = app(ResolveTagsAction::class);

    expect(fn () => $resolve->execute(Article::query()->create(), TagSelection::of([$ghost]), strict: true))->toThrow(UnknownTagTypeException::class)
        ->and(fn () => $resolve->execute(ScopedArticle::query()->create(['team_id' => $acme->getKey()]), TagSelection::of([$theirs]), strict: true))->toThrow(TagScopeMismatchException::class);
});

test('repair_paths_end_the_chain_at_a_dangling_parent', function (): void {
    $other = Tags::type('label')->make('Elsewhere')->create();
    $stray = Tags::type('genre')->make('Stray')->create();
    // A parent in another tree, set past the package: the chain ends there.
    TagModel::query()->whereKey($stray->getKey())->toBase()->update(['parent_id' => $other->getKey(), 'path' => '/x/', 'depth' => 2]);

    $report = Tags::repair(new RepairOptions(scopeKeys: false, counts: false, orphans: false));

    expect($report->paths)->toBe(1)
        ->and($stray->fresh()?->path)->toBe('/')
        ->and($stray->fresh()?->depth)->toBe(1);
});

test('repair_orphans_respects_the_type_option', function (): void {
    $gone = Article::query()->create();
    $gone->attachTags('a', 'label')->attachTags('b', 'tag');
    Article::query()->whereKey($gone->getKey())->forceDelete();
    $kept = Article::query()->create();
    $kept->attachTags('c', 'label')->attachTags('d', 'tag');
    Tags::type('label')->findOrFail('c')->delete();
    Tags::type('tag')->findOrFail('d')->delete();

    $report = Tags::repair(new RepairOptions(paths: false, scopeKeys: false, counts: false, type: 'label'));

    expect($report->orphans)->toBe(1)
        ->and($report->trashedTaggings)->toBe(1)
        ->and(Tagging::query()->orderBy('type')->pluck('type')->all())->toBe(['tag', 'tag']);
});

test('deletion_hook_ignores_models_that_are_not_taggable', function (): void {
    $note = UntaggableNote::query()->create(['body' => 'x']);
    $fake = Tags::fake();

    $note->delete();

    $fake->assertNothingCleared();
});

test('has_with_an_unsluggable_string_is_false', function (): void {
    $article = Article::query()->create();
    $article->attachTags('live', 'label');

    expect(Tags::for($article)->has('!!!'))->toBeFalse()
        ->and(Tags::for($article)->has('   '))->toBeFalse()
        ->and(Tags::for($article)->has('Live', 'label'))->toBeTrue();
});

test('a_tag_whose_scope_owner_is_gone_renders_a_null_scope', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $tag = Tags::type('label')->within($team)->make('Ours')->create();
    Team::query()->whereKey($team->getKey())->delete();

    expect(TagResource::scope(Tag::query()->findOrFail($tag->getKey()), Request::create('/')))->toBeNull();
});

test('siblings_of_a_child_share_its_parent', function (): void {
    $music = Tags::type('genre')->make('Music')->create();
    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    Tags::type('genre')->make('Swing')->under($music)->create();
    $rock = Tags::type('genre')->make('Rock')->create();

    expect($jazz->siblings()->get()->pluck('slug')->all())->toBe(['swing'])
        ->and($rock->siblings()->get()->pluck('slug')->all())->toBe(['music']);
});

test('suggest_with_a_blank_term_runs_no_query', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(Tags::type('label')->suggest('   '))->toHaveCount(0)
        ->and(DB::getQueryLog())->toBe([]);
});

test('lookup_skips_locales_without_a_candidate', function (): void {
    // "§" has no slug in English or Slovak, and "ss" in Spanish.
    config()->set('translatable.locales', ['en', 'sk', 'es']);
    $section = Tags::type('label')->make(['en' => 'Section', 'es' => 'ss'])->create();

    expect(TagLookup::byStrings(Tags::type('label')->definition(), [''], ['§'])['§']->is($section))->toBeTrue();
});

test('doctor_warns_about_a_taggable_on_another_connection', function (): void {
    config()->set('tags.taggables', ['article' => Article::class, 'remote' => RemoteNote::class]);

    $messages = array_map(static fn ($finding): string => $finding->message, app(Doctor::class)->run());

    expect($messages)->toContain('RemoteNote uses another database connection than the tags tables; the query scopes need one connection.');
});

test('doctor_scope_check_skips_unknown_classes', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $tag = Tags::type('label')->within($team)->make('Ours')->create();
    Tagging::query()->insert(['tag_id' => $tag->getKey(), 'taggable_type' => 'Gone\\Model', 'taggable_id' => 1, 'type' => 'label']);

    $sections = array_map(static fn ($finding): string => $finding->section, app(Doctor::class)->run());

    expect($sections)->not->toContain('scopes')
        ->and($sections)->toContain('orphans');
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'raw rows');

test('name_of_a_resolved_type_is_its_name', function (): void {
    expect(TagTypeRegistry::nameOf(Tags::type('genre')->definition()))->toBe('genre');
});

test('extend_refuses_a_malformed_name', function (): void {
    expect(fn () => Tags::extend('Not A Name', static fn () => null))->toThrow(InvalidTagTypeException::class);
});

test('config_rule_values_are_validated', function (array $rules, ?string $error): void {
    config()->set('tags.types', ['configured' => $rules]);

    $resolve = static fn () => app(TagTypeRegistry::class)->resolve('configured');

    $error === null
        ? expect($resolve()->label('en'))->toBe('Configured genre')
        : expect($resolve)->toThrow(InvalidTagTypeException::class, $error);
})->with([
    'a float flag' => [['curated' => 1.5], 'curated must be a boolean'],
    'a string label' => [['label' => 'Configured genre'], null],
    'a label map with a non-string' => [['label' => ['en' => 5]], 'label must be a string or a locale map'],
]);

// O-8 staged: while the detach waits for the tag row, a concurrent delete removes the tagging and
// a re-attach elsewhere takes the count back to 1. The detach writes, counts and announces nothing.
test('detach_counts_only_the_taggings_it_removes', function (): void {
    $article = Article::query()->create(['title' => 'A']);
    $other = Article::query()->create(['title' => 'B']);
    $article->attachTags(['rock']);
    $rock = Tags::type('tag')->findOrFail('rock');
    $armed = true;

    DB::listen(static function (QueryExecuted $query) use (&$armed, $article, $other, $rock): void {
        if ($armed && str_contains($query->sql, 'from "tags" where "tags"."id" in') && str_contains($query->sql, 'order by "id" asc')) {
            $armed = false;
            Tagging::query()->where('taggable_id', $article->getKey())->delete();
            Tagging::query()->insert(['tag_id' => $rock->getKey(), 'taggable_type' => $other->getMorphClass(), 'taggable_id' => $other->getKey(), 'type' => 'tag']);
        }
    });

    Event::fake([Untagged::class]);
    $changes = Tags::detach($article, $rock);

    expect($armed)->toBeFalse()
        ->and($changes->detached)->toBe([])
        ->and($rock->fresh()?->usage_count)->toBe(1)
        ->and(Tagging::query()->where('tag_id', $rock->getKey())->count())->toBe(1);

    Event::assertNotDispatched(Untagged::class);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint' || DB::getDriverName() !== 'sqlite', 'staged on sqlite');

// Every package caller sweeps first; a direct store of a tree draft still takes its own locks.
test('a_direct_store_of_a_tree_draft_takes_its_own_locks', function (): void {
    $type = app(TagTypeRegistry::class)->resolve('genre');

    $stored = TagTransaction::run(static fn () => TagStore::store(new TagDraft($type, null, '', ['en' => 'Folk'])));

    expect($stored->created)->toBeTrue()
        ->and(DB::table('tag_locks')->where('type', 'genre')->exists())->toBeTrue();
});
