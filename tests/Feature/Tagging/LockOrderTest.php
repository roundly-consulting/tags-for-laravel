<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Support\RecordsLocks;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;

/**
 * The lock order: tree lock → taggable row → tag inserts/restores → tag rows (key order,
 * liveness re-checked) → pivot writes → counts. Every lock is taken inside the transaction.
 */
beforeEach(function (): void {
    Tags::define('genre')->hierarchical();
    $this->article = Article::query()->create(['title' => 'Concert']);
});

test('the_taggable_row_is_locked_for_every_write', function (Closure $write): void {
    $this->article->attachTags(['a', 'b']);
    RecordsLocks::start();

    $write($this->article);

    expect(RecordsLocks::tables())->toContain('articles')
        ->and(collect(LockRecorder::recorded())->every(fn (array $lock): bool => $lock['transactionDepth'] >= 1))->toBeTrue();
})->with([
    'attach' => [fn (Article $article) => $article->attachTags('c')],
    'detach' => [fn (Article $article) => $article->detachTags('a')],
    'sync' => [fn (Article $article) => $article->syncTags(['a'])],
    'clear' => [fn (Article $article) => $article->clearTags()],
])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

test('tag_rows_are_locked_before_pivot_writes', function (): void {
    $this->article->attachTags(['a']);
    RecordsLocks::start();

    $this->article->syncTags(['b', 'a', 'c']);

    expect(RecordsLocks::writes())->toBe([
        // The model first, then b and c are new: probe their slugs (locking) and insert them.
        'lock:articles',
        'lock:tags', 'insert:tags',
        'lock:tags', 'insert:tags',
        // Then the tag rows in key order, then the pivot and the counts.
        'lock:tags',
        'insert:taggables',
        'update:tags',
    ]);
})->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

test('lock_order_is_tree_then_taggable_then_tags', function (): void {
    $music = Tags::type('genre')->make('Music')->create();
    RecordsLocks::start();

    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    $this->article->attachTags($jazz, 'genre');
    Tags::type('genre')->delete($jazz);

    $writes = RecordsLocks::writes();
    $tree = array_search('lock:tag_locks', $writes, true);
    $taggable = array_search('lock:articles', $writes, true);
    $pivotInsert = array_search('insert:taggables', $writes, true);
    $pivotDelete = array_search('delete:taggables', $writes, true);

    expect($tree)->toBe(0)
        ->and($taggable)->toBeGreaterThan($tree)
        ->and($writes[$taggable + 1])->toBe('lock:tags')
        ->and($pivotInsert)->toBeGreaterThan($taggable + 1)
        // The delete: tree lock, the tag rows, then the pivot.
        ->and(array_slice($writes, $pivotInsert + 2, 3))->toBe(['lock:tag_locks', 'lock:tags', 'update:tags'])
        ->and($pivotDelete)->toBeGreaterThan($pivotInsert)
        ->and(collect(LockRecorder::recorded())->every(fn (array $lock): bool => $lock['transactionDepth'] >= 1))->toBeTrue();
})->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

test('tree_writes_take_the_tree_lock', function (Closure $write): void {
    $music = Tags::type('genre')->make('Music')->create();
    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    $rock = Tags::type('genre')->make('Rock')->create();
    RecordsLocks::start();

    $write($music, $jazz, $rock);

    expect(RecordsLocks::tables()[0] ?? null)->toBe('tag_locks')
        ->and(LockRecorder::recorded()[0]['transactionDepth'] ?? 0)->toBeGreaterThanOrEqual(1);
})->with([
    'create under a parent' => [fn ($music) => Tags::type('genre')->make('Bebop')->under($music)->create()],
    'create at a position' => [fn () => Tags::type('genre')->make('Pop')->position(0)->create()],
    'move' => [fn ($music, $jazz, $rock) => Tags::type('genre')->move($jazz, $rock)],
    'reorder' => [fn ($music, $jazz, $rock) => Tags::type('genre')->reorder(null, [$rock, $music])],
    'delete' => [fn ($music, $jazz) => Tags::type('genre')->delete($jazz)],
    'restore' => [function ($music, $jazz): void {
        Tags::type('genre')->delete($jazz);
        RecordsLocks::start();
        Tags::type('genre')->restore($jazz);
    }],
])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

// In a transaction of its own a tree write's first statement is the tree lock, so its plain reads
// see the previous writer; a host transaction may hold an older snapshot (MySQL), so there the tree
// rows are read with locking reads.
test('tree_writes_in_a_host_transaction_read_the_tree_with_locking_reads', function (Closure $write, int $reads): void {
    $music = Tags::type('genre')->make('Music')->create();
    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    $rock = Tags::type('genre')->make('Rock')->create();
    $tagRowLocks = static fn (): int => count(array_filter(RecordsLocks::tables(), static fn (string $table): bool => $table === 'tags'));

    RecordsLocks::start();
    $write($music, $jazz, $rock);
    $own = $tagRowLocks();

    RecordsLocks::start();
    DB::transaction(static fn () => $write($music->fresh(), $jazz->fresh(), $rock->fresh()));

    expect($tagRowLocks() - $own)->toBeGreaterThanOrEqual($reads)
        ->and(RecordsLocks::tables()[0] ?? null)->toBe('tag_locks');
})->with([
    // the parent, the last sibling position
    'create under a parent' => [fn ($music, $jazz, $rock) => Tags::type('genre')->make('Bebop '.Str::random(4))->under($music)->create(), 2],
    // the tag, its new parent, its deepest descendant, its old parent, the last sibling, the subtree
    'move' => [fn ($music, $jazz, $rock) => Tags::type('genre')->move($jazz, $jazz->parent_id === $rock->getKey() ? $music : $rock), 5],
    // the siblings
    'reorder' => [fn ($music, $jazz, $rock) => Tags::type('genre')->reorder(null, [$rock, $music]), 1],
])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');
