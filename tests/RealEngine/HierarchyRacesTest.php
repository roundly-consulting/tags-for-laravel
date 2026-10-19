<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\TagHasChildrenException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Support\Racer;

test('concurrent_moves_cannot_form_a_cycle', function (): void {
    Tags::define('genre')->hierarchical();
    $a = Tags::type('genre')->make('A')->create();
    $b = Tags::type('genre')->make('B')->create();
    $c = Tags::type('genre')->make('C')->create();
    $racers = [];

    // A under B and B under C commit first; C under A would close the loop.
    DB::transaction(function () use ($a, $b, $c, &$racers): void {
        Tags::type('genre')->move($a, $b);

        $racers[] = (new Racer)->run(static function () use ($b, $c): string {
            try {
                Tags::type('genre')->move(Tag::on('racer')->findOrFail($b->getKey()), Tag::on('racer')->findOrFail($c->getKey()));

                return 'moved';
            } catch (InvalidTagHierarchyException) {
                return 'cycle';
            }
        })->waitUntilBlockedOrDone();
    });

    $first = $racers[0]->outcome();
    $racer = new Racer;

    DB::transaction(function () use ($c, $a, $racer): void {
        Tag::query()->whereKey($c->getKey())->lockForUpdate()->first();

        $racer->run(static function () use ($c, $a): string {
            try {
                Tags::type('genre')->move(Tag::on('racer')->findOrFail($c->getKey()), Tag::on('racer')->findOrFail($a->getKey()));

                return 'moved';
            } catch (InvalidTagHierarchyException) {
                return 'cycle';
            }
        });
    });

    expect($first)->toBe('moved')
        ->and($racer->outcome())->toBe('cycle')
        ->and($c->fresh()?->isRoot())->toBeTrue()
        ->and($a->fresh()?->path)->toBe('/'.$c->getKey().'/'.$b->getKey().'/');
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

it('lets only one of two crossing moves through', function (): void {
    Tags::define('genre')->hierarchical();
    $a = Tags::type('genre')->make('A')->create();
    $b = Tags::type('genre')->make('B')->create();
    $racer = new Racer;

    DB::transaction(function () use ($a, $b, $racer): void {
        Tags::type('genre')->move($a, $b);

        $racer->run(static function () use ($a, $b): string {
            try {
                Tags::type('genre')->move(Tag::on('racer')->findOrFail($b->getKey()), Tag::on('racer')->findOrFail($a->getKey()));

                return 'moved';
            } catch (InvalidTagHierarchyException) {
                return 'cycle';
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('cycle')
        ->and($a->fresh()?->parent_id)->toBe($b->getKey())
        ->and($b->fresh()?->isRoot())->toBeTrue();
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// Inside a host transaction (MySQL's REPEATABLE READ) the snapshot may predate the tree lock: every
// tree row a write decides on is read with a locking read, so it sees the writer it waited for.
test('a_move_in_a_host_transaction_cannot_form_a_cycle', function (): void {
    Tags::define('genre')->hierarchical();
    $a = Tags::type('genre')->make('A')->create();
    $b = Tags::type('genre')->make('B')->create();
    $racer = new Racer;

    DB::transaction(function () use ($a, $b, $racer): void {
        Tags::type('genre')->move($b, $a);   // holds the tree lock until this commits

        $racer->run(static fn (): string => DB::transaction(static function () use ($a, $b): string {
            Tag::query()->count();            // the host reads first: its snapshot starts here

            try {
                Tags::type('genre')->move(Tag::query()->findOrFail($a->getKey()), Tag::query()->findOrFail($b->getKey()));

                return 'moved';
            } catch (InvalidTagHierarchyException) {
                return 'cycle';
            }
        }))->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('cycle')
        ->and($a->fresh()?->isRoot())->toBeTrue()
        ->and($b->fresh()?->parent_id)->toBe($a->getKey());
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('a_create_under_a_moved_parent_in_a_host_transaction_gets_its_current_path', function (): void {
    Tags::define('genre')->hierarchical();
    $root = Tags::type('genre')->make('Root')->create();
    $parent = Tags::type('genre')->make('Parent')->create();
    $racer = new Racer;

    DB::transaction(function () use ($root, $parent, $racer): void {
        Tags::type('genre')->move($parent, $root);

        $racer->run(static fn (): string => DB::transaction(static function () use ($parent): string {
            Tag::query()->count();

            $child = Tags::type('genre')->make('Child')->under(Tag::query()->findOrFail($parent->getKey()))->create();

            return $child->path.'|'.$child->depth;
        }))->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('/'.$root->getKey().'/'.$parent->getKey().'/|3');
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('a_delete_in_a_host_transaction_still_refuses_a_new_child', function (): void {
    Tags::define('genre')->hierarchical();
    $music = Tags::type('genre')->make('Music')->create();
    $racer = new Racer;

    DB::transaction(function () use ($music, $racer): void {
        Tags::type('genre')->make('Jazz')->under($music)->create();   // holds the tree lock

        $racer->run(static fn (): string => DB::transaction(static function () use ($music): string {
            Tag::query()->count();

            try {
                Tags::type('genre')->delete(Tag::query()->findOrFail($music->getKey()));

                return 'deleted';
            } catch (TagHasChildrenException) {
                return 'refused';
            }
        }))->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('refused')
        ->and($music->fresh()?->trashed())->toBeFalse();
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('a_reorder_and_a_reparent_in_a_host_transaction_see_the_current_tree', function (): void {
    Tags::define('genre')->hierarchical();
    $music = Tags::type('genre')->make('Music')->create();
    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    $rock = Tags::type('genre')->make('Rock')->create();
    $racer = new Racer;

    DB::transaction(function () use ($music, $rock, $racer): void {
        Tags::type('genre')->make('Pop')->under($music)->create();   // a new sibling of Jazz
        Tags::type('genre')->move($music, $rock);                    // Music (and Jazz, Pop) under Rock

        $racer->run(static fn (): string => DB::transaction(static function () use ($music): string {
            Tag::query()->count();

            $order = Tags::type('genre')->reorder(Tag::query()->findOrFail($music->getKey()), [])->pluck('slug')->all();
            Tags::type('genre')->delete(Tag::query()->findOrFail($music->getKey()), TagChildren::Reparent);

            return implode(',', $order);
        }))->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('jazz,pop')
        ->and(Tag::query()->where('slug->en', 'pop')->firstOrFail()->path)->toBe('/'.$rock->getKey().'/')
        ->and($jazz->fresh()?->path)->toBe('/'.$rock->getKey().'/')
        ->and($jazz->fresh()?->depth)->toBe(2);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// FR-1: a tree write locks the tag rows it decides on and changes in one ascending sweep, right
// after the tree lock — the order every tagging write locks tag rows in — so the two never wait on
// each other in a cycle. Staged: a key-order locker holds A and then asks for B, while a
// host-transaction move of B under A runs in between.
test('a_nested_move_and_a_tagging_write_never_deadlock', function (): void {
    Tags::define('genre')->hierarchical();
    $a = Tags::type('genre')->make('A')->create();   // the lower key
    $b = Tags::type('genre')->make('B')->create();
    $racer = new Racer;
    $parent = 'done';

    try {
        DB::transaction(function () use ($a, $b, $racer): void {
            Tag::query()->whereKey($a->getKey())->lockForUpdate()->first();   // a tagging write's first row

            $racer->run(static fn (): string => DB::connection('racer')->transaction(static function () use ($a, $b): string {
                Tag::on('racer')->count();   // the host's snapshot (MySQL)
                Tags::type('genre')->move(Tag::on('racer')->findOrFail($b->getKey()), Tag::on('racer')->findOrFail($a->getKey()));

                return 'moved';
            }))->waitUntilBlockedOrDone();

            Tag::query()->whereKey($b->getKey())->lockForUpdate()->first();   // … then its second
        });
    } catch (QueryException $exception) {
        $parent = 'error: '.$exception->getMessage();
    }

    expect($parent)->toBe('done')
        ->and($racer->outcome())->toBe('moved')
        ->and($b->fresh()?->parent_id)->toBe($a->getKey());
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// The same with package code on both sides: a host-transaction move and a host-transaction attach
// of both tags.
test('a_nested_move_and_a_real_attach_never_deadlock', function (): void {
    Tags::define('genre')->hierarchical();
    $a = Tags::type('genre')->make('A')->create();
    $b = Tags::type('genre')->make('B')->create();
    $article = Article::query()->create();
    $racer = new Racer;
    $started = false;

    // Once the move holds B, an attach of [A, B] starts elsewhere.
    DB::listen(static function (QueryExecuted $query) use (&$started, $racer, $a, $b, $article): void {
        $sql = strtolower($query->sql);

        if ($started || ! str_contains($sql, 'for update') || ! str_contains($sql, 'tags') || str_contains($sql, 'tag_locks') || ! in_array($b->getKey(), $query->bindings, false) && ! str_contains($sql, (string) $b->getKey())) {
            return;
        }

        $started = true;

        // In a transaction too, so neither side is retried: the absence of the cycle is the proof.
        $racer->run(static fn (): string => DB::connection('racer')->transaction(static function () use ($a, $b, $article): string {
            Article::on('racer')->findOrFail($article->getKey())->attachTags([Tag::on('racer')->findOrFail($a->getKey()), Tag::on('racer')->findOrFail($b->getKey())], 'genre');

            return 'attached';
        }))->waitUntilBlockedOrDone();
    });

    $parent = 'moved';

    try {
        DB::transaction(static function () use ($a, $b): void {
            Tag::query()->count();
            Tags::type('genre')->move(Tag::query()->findOrFail($b->getKey()), Tag::query()->findOrFail($a->getKey()));
        });
    } catch (QueryException $exception) {
        $parent = 'error: '.$exception->getMessage();
    }

    expect($started)->toBeTrue()
        ->and($parent)->toBe('moved')
        ->and($racer->outcome())->toBe('attached');
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// The other side: a move in a transaction of its own rewrites a descendant with a lower key than
// the moved tag; a host-transaction tagging write holding that descendant and asking for the
// moved tag must not deadlock with it either.
test('a_move_and_a_nested_tagging_write_never_deadlock', function (): void {
    Tags::define('genre')->hierarchical();
    $low = Tags::type('genre')->make('Low')->create();   // the lower key …
    $top = Tags::type('genre')->make('Top')->create();
    $low = Tags::type('genre')->move($low, $top);          // … below the higher one
    $root = Tags::type('genre')->make('Root')->create();
    $racer = new Racer;
    $parent = 'done';

    try {
        DB::transaction(function () use ($low, $top, $root, $racer): void {
            Tag::query()->whereKey($low->getKey())->lockForUpdate()->first();

            $racer->run(static function () use ($top, $root): string {
                Tags::type('genre')->move(Tag::on('racer')->findOrFail($top->getKey()), Tag::on('racer')->findOrFail($root->getKey()));

                return 'moved';
            })->waitUntilBlockedOrDone();

            Tag::query()->whereKey($top->getKey())->lockForUpdate()->first();
        });
    } catch (QueryException $exception) {
        $parent = 'error: '.$exception->getMessage();
    }

    expect($parent)->toBe('done')
        ->and($racer->outcome())->toBe('moved')
        ->and($low->fresh()?->path)->toBe('/'.$root->getKey().'/'.$top->getKey().'/');
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
