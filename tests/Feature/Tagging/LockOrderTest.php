<?php

declare(strict_types=1);

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
