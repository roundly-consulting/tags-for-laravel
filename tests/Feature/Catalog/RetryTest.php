<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A deadlock aborts the whole transaction; the outermost one is retried, and every attempt must
 * start from fresh models — a model the rolled-back attempt saved would carry a key that no
 * longer exists (a phantom row, an FK failure on the next write).
 */
test('deadlock_retry_starts_from_a_fresh_model', function (): void {
    Tags::define('genre')->hierarchical();
    $music = Tags::type('genre')->make('Music')->create();
    $attempts = 0;

    Tag::creating(static function () use (&$attempts): void {
        if ($attempts++ === 0) {
            throw new PDOException('Deadlock found when trying to get lock; try restarting transaction');
        }
    });

    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();

    expect($attempts)->toBe(2)
        ->and(Tag::query()->count())->toBe(2)
        ->and($jazz->exists)->toBeTrue()
        ->and($jazz->fresh()?->parent?->is($music))->toBeTrue()
        ->and($jazz->path)->toBe('/'.$music->getKey().'/');
});

it('gives up after three deadlocks in a row', function (): void {
    Tag::creating(static function (): void {
        throw new PDOException('Deadlock found when trying to get lock; try restarting transaction');
    });

    expect(fn () => Tags::type('tag')->make('Rock')->create())->toThrow(PDOException::class)
        ->and(Tag::query()->count())->toBe(0);
});
