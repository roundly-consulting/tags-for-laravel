<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tags\Actions\Catalog\MoveTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\ReorderTagsAction;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\TagNode;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Events\TagMoved;
use RoundlyConsulting\Tags\Events\TagsReordered;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\TagHasChildrenException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Handles\TypeTags;
use RoundlyConsulting\Tags\Http\Resources\TagSummaryResource;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

beforeEach(function (): void {
    Tags::define('genre')->hierarchical(maxDepth: 4)->single();
    $this->genres = Tags::type('genre');
    $this->music = $this->genres->make('Music')->create();
    $this->jazz = $this->genres->make('Jazz')->under($this->music)->create();
    $this->bebop = $this->genres->make('Bebop')->under($this->jazz)->create();
});

/**
 * @param  list<TagNode>  $nodes
 * @return array<string, mixed>
 */
function treeShape(array $nodes): array
{
    $shape = [];

    foreach ($nodes as $node) {
        $shape[(string) $node->tag->slug] = treeShape($node->children);
    }

    return $shape;
}

test('paths_and_depths_follow_parents', function (): void {
    expect($this->music->path)->toBe('/')
        ->and($this->music->depth)->toBe(1)
        ->and($this->jazz->path)->toBe('/'.$this->music->getKey().'/')
        ->and($this->jazz->depth)->toBe(2)
        ->and($this->bebop->path)->toBe('/'.$this->music->getKey().'/'.$this->jazz->getKey().'/')
        ->and($this->bebop->depth)->toBe(3);
});

test('children_must_share_type_and_scope', function (): void {
    Tags::define('other')->hierarchical();
    $team = Team::query()->create(['name' => 'Acme']);
    $foreign = Tags::type('other')->make('Foreign')->create();

    expect(fn () => $this->genres->make('X')->under($foreign))->toThrow(TagTypeMismatchException::class)
        ->and(fn () => $this->genres->within($team)->make('X')->under($this->music))->toThrow(TagScopeMismatchException::class)
        ->and(fn () => $this->genres->create(new TagData(name: 'X', parent: $foreign)))->toThrow(TagTypeMismatchException::class);

    $this->genres->delete($this->bebop);

    expect(fn () => $this->genres->make('Y')->under($this->bebop->fresh() ?? $this->bebop))->toThrow(InvalidTagHierarchyException::class, 'parent tag is deleted');
});

test('flat_types_refuse_parents', function (): void {
    $live = Tags::type('tag')->make('Live')->create();

    expect(fn () => Tags::type('tag')->make('Child')->under($live))->toThrow(InvalidTagHierarchyException::class, 'flat')
        ->and(fn () => Tags::type('tag')->move($live, $live))->toThrow(InvalidTagHierarchyException::class, 'flat');
});

test('depth_is_capped_by_type_and_config', function (): void {
    $cool = $this->genres->make('Cool')->under($this->bebop)->create();

    expect($cool->depth)->toBe(4)
        ->and(fn () => $this->genres->make('Too deep')->under($cool)->create())->toThrow(InvalidTagHierarchyException::class, 'at most 4 levels');

    config()->set('tags.hierarchy.max_depth', 2);
    Tags::extend('genre', static fn ($type) => $type);

    // A handle carries the type it resolved; a new one sees the lowered cap.
    expect(fn () => Tags::type('genre')->make('Swing')->under($this->jazz)->create())->toThrow(InvalidTagHierarchyException::class, 'at most 2 levels');
});

test('move_checks_the_depth_of_the_whole_subtree', function (): void {
    $rock = $this->genres->make('Rock')->create();
    $punk = $this->genres->make('Punk')->under($rock)->create();
    $this->genres->make('Hardcore')->under($punk)->create();

    // rock (1) → punk (2) → hardcore (3); under bebop (3) the deepest would be 6 > 4.
    expect(fn () => $this->genres->move($rock, $this->bebop))->toThrow(InvalidTagHierarchyException::class)
        ->and(fn () => $this->genres->move($rock, $this->jazz))->toThrow(InvalidTagHierarchyException::class)
        ->and($this->genres->move($rock, $this->music)->depth)->toBe(2);
});

test('move_refuses_cycles', function (): void {
    expect(fn () => $this->genres->move($this->music, $this->bebop))->toThrow(InvalidTagHierarchyException::class, 'below itself')
        ->and(fn () => $this->genres->move($this->jazz, $this->jazz))->toThrow(InvalidTagHierarchyException::class, 'below itself');
});

test('move_rewrites_the_subtree', function (): void {
    Event::fake([TagMoved::class]);
    $rock = $this->genres->make('Rock')->create();

    $moved = $this->genres->move($this->jazz, $rock);

    $bebop = $this->bebop->fresh();

    expect($moved->parent_id)->toBe($rock->getKey())
        ->and($moved->path)->toBe('/'.$rock->getKey().'/')
        ->and($bebop?->path)->toBe('/'.$rock->getKey().'/'.$this->jazz->getKey().'/')
        ->and($bebop?->depth)->toBe(3)
        ->and($this->genres->move($this->jazz)->isRoot())->toBeTrue()
        ->and($this->bebop->fresh()?->depth)->toBe(2)
        ->and($this->bebop->fresh()?->path)->toBe('/'.$this->jazz->getKey().'/');

    Event::assertDispatched(TagMoved::class, fn (TagMoved $event): bool => $event->from?->is($this->music) === true && $event->to?->is($rock) === true);
});

test('explicit_position_shifts_siblings', function (): void {
    $rock = $this->genres->make('Rock')->create();
    $pop = $this->genres->make('Pop')->position(0)->create();
    $folk = $this->genres->make('Folk')->position(1)->create();

    expect($this->genres->roots()->pluck('slug')->all())->toBe(['pop', 'folk', 'music', 'rock']);

    $this->genres->move($rock, null, 0);

    expect($this->genres->roots()->pluck('slug')->all())->toBe(['rock', 'pop', 'folk', 'music']);
});

test('reorder_sets_sibling_positions', function (): void {
    $rock = $this->genres->make('Rock')->under($this->music)->create();
    $pop = $this->genres->make('Pop')->under($this->music)->create();
    Event::fake([TagsReordered::class]);

    $reordered = $this->genres->reorder($this->music, [$pop, $rock, $this->jazz]);

    expect($reordered->pluck('slug')->all())->toBe(['pop', 'rock', 'jazz'])
        ->and($reordered->pluck('position')->all())->toBe([0, 1, 2])
        ->and($this->music->children()->get()->pluck('slug')->all())->toBe(['pop', 'rock', 'jazz']);

    Event::assertDispatched(TagsReordered::class, fn (TagsReordered $event): bool => $event->type === 'genre' && count($event->tags) === 3);
});

test('reorder_renumbers_unlisted_siblings_after', function (): void {
    $rock = $this->genres->make('Rock')->under($this->music)->create();
    $pop = $this->genres->make('Pop')->under($this->music)->create();

    $reordered = $this->genres->reorder($this->music, [(string) $pop->getKey()]);

    expect($reordered->pluck('slug')->all())->toBe(['pop', 'jazz', 'rock'])
        ->and($reordered->pluck('position')->all())->toBe([0, 1, 2]);

    // Flat types reorder their roots.
    $a = Tags::type('tag')->make('A')->create();
    $b = Tags::type('tag')->make('B')->create();

    expect(Tags::type('tag')->reorder(null, [$b, $a])->pluck('slug')->all())->toBe(['b', 'a']);
});

test('reorder_refuses_foreign_tags', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $theirs = $this->genres->within($team)->make('Theirs')->create();

    expect(fn () => $this->genres->reorder($this->music, [$this->bebop]))->toThrow(TagNotFoundException::class)
        ->and(fn () => $this->genres->reorder(null, [$theirs]))->toThrow(TagNotFoundException::class)
        ->and(fn () => $this->genres->reorder(null, [999999]))->toThrow(TagNotFoundException::class)
        ->and(fn () => $this->genres->within($team)->reorder($this->music, []))->toThrow(TagScopeMismatchException::class);
});

test('delete_with_children_refuses_by_default', function (): void {
    expect(fn () => $this->genres->delete($this->jazz))->toThrow(TagHasChildrenException::class, 'jazz')
        ->and($this->jazz->fresh()?->trashed())->toBeFalse()
        ->and((new TagHasChildrenException('x'))->status())->toBe(409);
});

test('delete_reparent_moves_children_up', function (): void {
    $swing = $this->genres->make('Swing')->under($this->jazz)->create();
    $cool = $this->genres->make('Cool')->under($this->bebop)->create();
    $rock = $this->genres->make('Rock')->under($this->music)->create();

    $this->genres->delete($this->jazz, TagChildren::Reparent);

    expect($this->music->children()->get()->pluck('slug')->all())->toBe(['rock', 'bebop', 'swing'])
        ->and($this->bebop->fresh()?->path)->toBe('/'.$this->music->getKey().'/')
        ->and($this->bebop->fresh()?->depth)->toBe(2)
        ->and($cool->fresh()?->path)->toBe('/'.$this->music->getKey().'/'.$this->bebop->getKey().'/')
        ->and($cool->fresh()?->depth)->toBe(3)
        ->and($swing->fresh()?->depth)->toBe(2)
        ->and($rock->fresh()?->position)->toBe(1);
});

test('delete_cascade_trashes_the_subtree', function (): void {
    $article = Article::query()->create();
    $article->syncTags($this->bebop, 'genre');

    $deleted = $this->genres->delete($this->music, TagChildren::Delete);

    expect($deleted->trashed())->toBeTrue()
        ->and(Tag::query()->where('type', 'genre')->count())->toBe(0)
        ->and(Tag::onlyTrashed()->where('type', 'genre')->count())->toBe(3)
        ->and($article->tagOf('genre'))->toBeNull()
        ->and($this->bebop->fresh()?->usage_count)->toBe(0);
});

test('restoring_a_child_of_a_trashed_parent_is_refused', function (): void {
    $this->genres->delete($this->music, TagChildren::Delete);

    expect(fn () => $this->genres->restore($this->jazz))->toThrow(InvalidTagHierarchyException::class, 'restore it first');

    // Top-down works; restoring a parent restores only that tag.
    $this->genres->restore($this->music);

    expect($this->jazz->fresh()?->trashed())->toBeTrue()
        ->and($this->genres->restore($this->jazz)->trashed())->toBeFalse()
        ->and($this->bebop->fresh()?->trashed())->toBeTrue();
});

test('restore_recomputes_the_path', function (): void {
    $rock = $this->genres->make('Rock')->create();
    $this->genres->delete($this->bebop);
    // The trashed tag's path is rewritten with its parent's move…
    $this->genres->move($this->jazz, $rock);
    // …and a path that drifted anyway is recomputed from the live parent on restore.
    Tag::query()->withTrashed()->whereKey($this->bebop->getKey())->toBase()->update(['path' => '/stale/', 'depth' => 9]);

    $restored = $this->genres->restore($this->bebop);

    expect($restored->path)->toBe('/'.$rock->getKey().'/'.$this->jazz->getKey().'/')
        ->and($restored->depth)->toBe(3);
});

// F-1: a move counts only the live subtree, so it may carry a deleted descendant below the cap;
// that descendant then never comes back deeper than the type allows.
test('restore_respects_the_depth_cap', function (): void {
    Tags::define('cat')->hierarchical(maxDepth: 2);
    $cats = Tags::type('cat');
    $a = $cats->make('A')->create();
    $b = $cats->make('B')->under($a)->create();
    $cats->delete($b);
    $c = $cats->make('C')->create();

    $moved = $cats->move($a->fresh(), $c);

    expect($moved->depth)->toBe(2)
        ->and(Tag::query()->withTrashed()->findOrFail($b->getKey())->depth)->toBe(3)
        ->and(fn () => $cats->restore($b->fresh() ?? $b))->toThrow(InvalidTagHierarchyException::class, 'levels deep')
        ->and(Tag::query()->withTrashed()->findOrFail($b->getKey())->trashed())->toBeTrue();

    // Back within the cap, it restores.
    $cats->move($a->fresh(), null);

    expect($cats->restore(Tag::query()->withTrashed()->findOrFail($b->getKey()))->depth)->toBe(2);
});

test('restoring_a_deleted_subtree_top_down_respects_the_cap', function (): void {
    Tags::define('cat')->hierarchical(maxDepth: 2);
    $cats = Tags::type('cat');
    $a = $cats->make('A')->create();
    $b = $cats->make('B')->under($a)->create();
    $cats->delete($a, TagChildren::Delete);
    $c = $cats->make('C')->create();

    $restoredA = $cats->restore(Tag::query()->withTrashed()->findOrFail($a->getKey()));
    $cats->move($restoredA, $c);

    expect(fn () => $cats->restore(Tag::query()->withTrashed()->findOrFail($b->getKey())))->toThrow(InvalidTagHierarchyException::class, 'levels deep');
});

// A plain `$tag->delete()` leaves live children below the deleted tag; its restore counts them.
test('restore_counts_the_live_subtree_against_the_cap', function (): void {
    $this->music->delete();
    Tags::extend('genre', static fn ($type) => $type->hierarchical(maxDepth: 2));

    expect(fn () => Tags::type('genre')->restore(Tag::query()->withTrashed()->findOrFail($this->music->getKey())))
        ->toThrow(InvalidTagHierarchyException::class, 'levels deep');

    Tags::extend('genre', static fn ($type) => $type->hierarchical(maxDepth: 3));

    expect(Tags::type('genre')->restore(Tag::query()->withTrashed()->findOrFail($this->music->getKey()))->trashed())->toBeFalse();
});

test('free_form_hierarchical_types_create_roots', function (): void {
    Tags::define('topic')->hierarchical()->freeForm();
    $article = Article::query()->create();

    $article->attachTags(['Physics'], 'topic');

    expect(Tags::type('topic')->findOrFail('physics')->isRoot())->toBeTrue();
});

test('descendants_on_a_flat_type_is_a_no_op', function (): void {
    $article = Article::query()->create();
    $article->attachTags('live');

    expect(Article::query()->withAnyTags('live', 'tag', descendants: true)->count())->toBe(1);

    // A host override flips the package's tree to flat: no 500, just no descendants.
    config()->set('tags.overrides', ['genre' => ['hierarchical' => false]]);
    Tags::extend('genre', static fn ($type) => $type);
    Article::query()->create()->syncTags($this->jazz, 'genre');

    expect(Article::query()->withAnyTags(['music'], 'genre', descendants: true)->count())->toBe(0)
        ->and(Article::query()->withAnyTags(['jazz'], 'genre', descendants: true)->count())->toBe(1);
});

test('descendant_scopes_match_the_subtree', function (): void {
    $rock = $this->genres->make('Rock')->create();
    $bebopFan = Article::query()->create(['title' => 'bebop']);
    $jazzFan = Article::query()->create(['title' => 'jazz']);
    $rockFan = Article::query()->create(['title' => 'rock']);
    $bebopFan->syncTags($this->bebop, 'genre');
    $jazzFan->syncTags($this->jazz, 'genre');
    $rockFan->syncTags($rock, 'genre');

    $titles = static fn ($query): array => $query->orderBy('title')->pluck('title')->all();

    expect($titles(Article::query()->withAnyTags(['music'], 'genre', descendants: true)))->toBe(['bebop', 'jazz'])
        ->and($titles(Article::query()->withAnyTags(['music'], 'genre')))->toBe([])
        ->and($titles(Article::query()->withAllTags(['jazz'], 'genre', descendants: true)))->toBe(['bebop', 'jazz'])
        ->and($titles(Article::query()->withoutTags(['music'], 'genre', descendants: true)))->toBe(['rock'])
        ->and($titles(Article::query()->withAnyTags([$this->jazz, $rock], descendants: true)))->toBe(['bebop', 'jazz', 'rock']);
});

test('tree_is_built_in_one_query', function (): void {
    $rock = $this->genres->make('Rock')->create();
    $this->genres->make('Swing')->under($this->jazz)->position(0)->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $tree = $this->genres->tree();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(treeShape($tree))->toBe(['music' => ['jazz' => ['swing' => [], 'bebop' => []]], 'rock' => []])
        ->and(treeShape($this->genres->tree($this->jazz)))->toBe(['jazz' => ['swing' => [], 'bebop' => []]])
        ->and(fn () => Tags::type('tag')->tree($this->jazz))->toThrow(TagNotFoundException::class);
});

test('ancestors_in_one_query', function (): void {
    $bebop = Tag::query()->findOrFail($this->bebop->getKey());

    DB::enableQueryLog();
    DB::flushQueryLog();

    $breadcrumb = $bebop->ancestors()->push($bebop)->pluck('name')->join(' › ');

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($breadcrumb)->toBe('Music › Jazz › Bebop')
        ->and($this->music->ancestors()->all())->toBe([])
        ->and($this->jazz->siblings()->count())->toBe(0)
        ->and($this->music->descendants()->count())->toBe(2);
});

test('load_ancestors_runs_one_query_for_many_tags', function (): void {
    foreach (range(1, 5) as $i) {
        $parent = $this->genres->make("Style {$i}")->under($this->music)->create();

        foreach (range(1, 5) as $j) {
            $this->genres->make("Sub {$i}.{$j}")->under($parent)->create();
        }
    }

    $tags = Tag::query()->where('depth', 3)->orderBy('id')->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    Tags::loadAncestors($tags);

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::flushQueryLog();

    $paths = $tags->map(fn (Tag $tag): string => $tag->ancestors()->pluck('name')->join(' › '));
    $rendered = $tags->map(fn (Tag $tag): array => (new TagSummaryResource($tag))->resolve(Request::create('/')));

    expect(DB::getQueryLog())->toBe([])
        ->and($tags)->toHaveCount(26)
        ->and($paths->first())->toBe('Music › Jazz')
        ->and($paths->last())->toBe('Music › Style 5')
        ->and($rendered->last()['ancestors'] ?? null)->toBe([
            (new TagSummaryResource($this->music->fresh()))->resolve(Request::create('/')),
            (new TagSummaryResource(Tag::query()->where('slug->en', 'style-5')->firstOrFail()))->resolve(Request::create('/')),
        ]);
});

test('ancestors_reads_the_loaded_relation', function (): void {
    $bebop = Tag::query()->findOrFail($this->bebop->getKey());
    $unloaded = Tag::query()->findOrFail($this->bebop->getKey());

    Tags::loadAncestors([$bebop]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $breadcrumb = $bebop->ancestors()->push($bebop)->pluck('name')->join(' › ');

    // Pushing onto the result never changes the loaded relation.
    expect($bebop->ancestors()->pluck('name')->all())->toBe(['Music', 'Jazz'])
        ->and($breadcrumb)->toBe('Music › Jazz › Bebop')
        ->and(DB::getQueryLog())->toBe([])
        ->and($unloaded->ancestors()->pluck('name')->all())->toBe(['Music', 'Jazz'])
        ->and(DB::getQueryLog())->toHaveCount(1);

    // A relation already loaded is left alone.
    $custom = Tag::query()->findOrFail($this->jazz->getKey());
    $custom->setRelation('ancestors', new EloquentCollection);
    Tags::loadAncestors([$custom]);

    expect($custom->ancestors()->all())->toBe([]);
});

test('load_ancestors_skips_nulls_and_refuses_other_values', function (): void {
    $music = Tag::query()->findOrFail($this->music->getKey());
    $article = Article::query()->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    Tags::loadAncestors([]);
    Tags::loadAncestors([null, null]);
    Tags::loadAncestors([null, $music]);

    expect(DB::getQueryLog())->toBe([])
        ->and($music->relationLoaded('ancestors'))->toBeTrue()
        ->and($music->ancestors()->all())->toBe([])
        ->and(fn () => Tags::loadAncestors([$music, $article]))->toThrow(InvalidArgumentException::class, 'Tag models or nulls')
        ->and(fn () => Tags::loadAncestors(['jazz']))->toThrow(InvalidArgumentException::class, 'Tag models or nulls');
});

it('lists the catalog handle as a type-safe handle', function (): void {
    expect($this->genres)->toBeInstanceOf(TypeTags::class);
});

it('records moves and reorders on the fake', function (): void {
    $fake = Tags::fake();
    $rock = Tags::type('genre')->make('Rock')->create();

    Tags::type('genre')->move($this->jazz, $rock);
    Tags::type('genre')->reorder(null, [$rock]);

    $fake->assertTagMoved(fn (Tag $tag): bool => $tag->is($this->jazz));
    $fake->assertTagsReordered();
    $fake->assertTagsReordered('genre');

    expect(fn () => $fake->assertTagsReordered('tag'))->toThrow(ExpectationFailedException::class, 'tags of type [tag] to be reordered')
        ->and(fn () => $fake->assertNoTagMoved())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNoTagsReordered())->toThrow(ExpectationFailedException::class, '1 reorders');
});

it('reorders through the raw action', function (): void {
    $rock = $this->genres->make('Rock')->create();

    $reordered = app(ReorderTagsAction::class)->execute('genre', null, null, [$rock]);
    $moved = app(MoveTagAction::class)->execute($this->jazz, $rock, 0);

    expect($reordered->pluck('slug')->all())->toBe(['rock', 'music'])
        ->and($moved->parent_id)->toBe($rock->getKey());
});
