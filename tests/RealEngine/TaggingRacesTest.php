<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Support\Racer;

beforeEach(function (): void {
    Tags::define('genre')->hierarchical()->single();
});

test('concurrent_single_attach_respects_max', function (): void {
    Tags::type('genre')->make('Jazz')->create();
    Tags::type('genre')->make('Rock')->create();
    $article = Article::query()->create();
    $racer = new Racer;

    DB::transaction(function () use ($article, $racer): void {
        $article->attachTags('jazz', 'genre');

        $racer->run(static function () use ($article): string {
            try {
                Article::on('racer')->findOrFail($article->getKey())->attachTags('rock', 'genre');

                return 'attached';
            } catch (TagLimitExceededException) {
                return 'limit';
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('limit')
        ->and($article->tagsOf('genre')->pluck('slug')->all())->toBe(['jazz']);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

it('respects the max inside a host transaction that read before it waited', function (): void {
    Tags::type('genre')->make('Jazz')->create();
    Tags::type('genre')->make('Rock')->create();
    $article = Article::query()->create();
    $racer = new Racer;

    DB::transaction(function () use ($article, $racer): void {
        $article->attachTags('jazz', 'genre');

        $racer->run(static function () use ($article): string {
            try {
                return DB::connection('racer')->transaction(static function () use ($article): string {
                    // A plain read first: MySQL's REPEATABLE READ snapshot starts here, before the wait.
                    Tagging::on('racer')->count();
                    Article::on('racer')->findOrFail($article->getKey())->attachTags('rock', 'genre');

                    return 'attached';
                });
            } catch (TagLimitExceededException) {
                return 'limit';
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('limit')
        ->and($article->tagsOf('genre')->pluck('slug')->all())->toBe(['jazz']);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('concurrent_attaches_count_exactly', function (): void {
    $live = Tags::type('tag')->make('Live')->create();
    $articles = collect(range(0, 20))->map(fn (int $index): Article => Article::query()->create(['title' => "a{$index}"]));
    $racers = [];

    DB::transaction(function () use ($articles, &$racers): void {
        $articles[0]->attachTags('live');

        foreach ($articles->slice(1) as $article) {
            $racers[] = (new Racer)->run(static function () use ($article): string {
                Article::on('racer')->findOrFail($article->getKey())->attachTags('live');

                return 'attached';
            });
        }

        end($racers)->waitUntilBlockedOrDone();
    });

    $outcomes = array_map(static fn (Racer $racer): string => $racer->outcome(), $racers);

    expect(array_unique($outcomes))->toBe(['attached'])
        ->and($live->fresh()?->usage_count)->toBe(21)
        ->and(Tagging::query()->count())->toBe(21);

    // Interleaved detaches and attaches stay exact too.
    $racers = [];

    DB::transaction(function () use ($articles, &$racers): void {
        $articles[0]->detachTags('live');

        foreach ($articles->slice(1, 10) as $index => $article) {
            $racers[] = (new Racer)->run(static function () use ($article, $index): string {
                $model = Article::on('racer')->findOrFail($article->getKey());
                $index % 2 === 0 ? $model->detachTags('live') : $model->detachTags('live')->attachTags('live');

                return 'done';
            });
        }

        end($racers)->waitUntilBlockedOrDone();
    });

    array_map(static fn (Racer $racer): string => $racer->outcome(), $racers);

    expect($live->fresh()?->usage_count)->toBe(Tagging::query()->where('tag_id', $live->getKey())->count());
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('concurrent_delete_and_attach_leave_no_tagging_on_a_trashed_tag', function (): void {
    $live = Tags::type('tag')->make('Live')->create();
    $article = Article::query()->create();
    $racer = new Racer;

    // The delete holds the tag row; the attach resolved the tag as live and waits for it.
    DB::transaction(function () use ($live, $article, $racer): void {
        Tags::type('tag')->delete($live);

        $racer->run(static function () use ($article): string {
            try {
                Article::on('racer')->findOrFail($article->getKey())->attachTags('live');

                return 'attached';
            } catch (TagNotFoundException) {
                return 'not found';
            }
        })->waitUntilBlockedOrDone();
    });

    $outcome = $racer->outcome();
    $tag = Tag::query()->withTrashed()->where('type', 'tag')->orderBy('id')->get();

    // Either the attach saw the delete (not found), or it ran before the delete's lock and the
    // delete detached it; or it created a fresh "live" after the delete committed — never a
    // tagging on the trashed row.
    expect(Tagging::query()->where('tag_id', $live->getKey())->count())->toBe(0)
        ->and($live->fresh()?->usage_count)->toBe(0)
        ->and($outcome)->toBeIn(['not found', 'attached'])
        ->and($tag->first()?->trashed() === false || Tagging::query()->count() === 0)->toBeTrue();

    // The other way round: the attach holds the tag row, the delete waits and then detaches it.
    $rock = Tags::type('tag')->make('Rock')->create();
    $racer = new Racer;

    DB::transaction(function () use ($rock, $article, $racer): void {
        $article->attachTags($rock);

        $racer->run(static function () use ($rock): string {
            Tags::type('tag')->delete(Tag::on('racer')->findOrFail($rock->getKey()));

            return 'deleted';
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('deleted')
        ->and(Tagging::query()->where('tag_id', $rock->getKey())->count())->toBe(0)
        ->and($rock->fresh()?->usage_count)->toBe(0)
        ->and($rock->fresh()?->trashed())->toBeTrue();
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
