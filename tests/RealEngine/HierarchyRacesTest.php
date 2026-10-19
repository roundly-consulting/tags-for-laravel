<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
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
