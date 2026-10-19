<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Support\Racer;

/**
 * Two sessions at once on a real engine (pgsql, mysql). The parent holds its transaction open
 * while a forked racer runs the competing write, then commits; the racer must wait on the lock
 * (or the unique index) and decide on the committed state.
 */
test('concurrent_find_or_create_yields_one_tag', function (): void {
    $racer = new Racer;

    $parent = DB::transaction(function () use ($racer): Tag {
        $tag = Tags::type('tag')->findOrCreate('Rock');

        $racer->run(static fn (): string => (string) Tags::type('tag')->findOrCreate('ROCK')->getKey())->waitUntilBlockedOrDone();

        return $tag;
    });

    expect($racer->outcome())->toBe((string) $parent->getKey())
        ->and(Tag::query()->withTrashed()->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

it('refuses the second of two concurrent creates of one name', function (): void {
    $racer = new Racer;

    DB::transaction(function () use ($racer): void {
        Tags::type('tag')->make('Rock')->create();

        $racer->run(static function (): string {
            try {
                Tags::type('tag')->make('rock')->create();

                return 'created';
            } catch (DuplicateTagException) {
                return 'duplicate';
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('duplicate')
        ->and(Tag::query()->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('concurrent_recreates_restore_once', function (): void {
    $rock = Tags::type('tag')->make('Rock')->create();
    Tags::type('tag')->delete($rock);
    $racer = new Racer;

    DB::transaction(function () use ($racer): void {
        Tags::type('tag')->findOrCreate('Rock');

        $racer->run(static fn (): string => (string) Tags::type('tag')->findOrCreate('rock')->getKey())->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe((string) $rock->getKey())
        ->and(Tag::query()->withTrashed()->count())->toBe(1)
        ->and($rock->fresh()?->trashed())->toBeFalse();
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
