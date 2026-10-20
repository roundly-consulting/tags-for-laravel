<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tags\Events\TagDeleted;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
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

    // A seeder's child: the tree lock serialises the two, and the second finds the first.
    Tags::define('genre')->hierarchical();
    $music = Tags::type('genre')->make('Music')->create();
    $second = new Racer;

    $jazz = DB::transaction(function () use ($second, $music): Tag {
        $tag = Tags::type('genre')->make('Jazz')->under($music)->findOrCreate();

        $second->run(static function (): string {
            $genres = Tags::type('genre');

            return (string) $genres->make('JAZZ')->under($genres->findOrFail('music'))->findOrCreate()->getKey();
        })->waitUntilBlockedOrDone();

        return $tag;
    });

    expect($second->outcome())->toBe((string) $jazz->getKey())
        ->and(Tag::query()->where('type', 'genre')->count())->toBe(2);
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

// A flat type takes no tree lock: the second of two deletes waits on the tag row, then finds it
// deleted — it returns the deleted tag and announces nothing.
test('concurrent_flat_deletes_fire_one_event', function (): void {
    $rock = Tags::type('tag')->make('Rock')->create();
    Article::query()->create()->attachTags($rock);
    $detached = [];
    Event::listen(TagDeleted::class, static function (TagDeleted $event) use (&$detached): void {
        $detached[] = $event->detached;
    });
    $racer = new Racer;

    DB::transaction(function () use ($racer, $rock, &$detached): void {
        Tags::type('tag')->delete($rock);

        $racer->run(static function () use ($rock, &$detached): string {
            $detached = [];
            $result = Tags::type('tag')->delete($rock);

            return json_encode(['trashed' => $result->trashed(), 'events' => $detached], JSON_THROW_ON_ERROR);
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('{"trashed":true,"events":[]}')
        ->and($detached)->toBe([1])
        ->and($rock->fresh()?->trashed())->toBeTrue();
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
