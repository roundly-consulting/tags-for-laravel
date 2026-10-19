<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
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

test('repair_counts_stay_exact_under_concurrent_attaches', function (): void {
    $live = Tags::type('tag')->make('Live')->create();
    $articles = collect(range(1, 10))->map(fn (int $index): Article => Article::query()->create(['title' => "a{$index}"]));
    // A drifted count the repair loop keeps putting right while the attaches run.
    Tag::query()->whereKey($live->getKey())->toBase()->update(['usage_count' => 99]);

    $repairer = (new Racer)->run(static function (): string {
        foreach (range(1, 20) as $run) {
            Tags::repair(new RepairOptions(paths: false, scopeKeys: false, orphans: false));
        }

        return 'repaired';
    });

    $attachers = $articles->map(fn (Article $article): Racer => (new Racer)->run(static function () use ($article): string {
        Article::query()->findOrFail($article->getKey())->attachTags('live');

        return 'attached';
    }))->all();

    $outcomes = [$repairer->outcome(), ...array_map(static fn (Racer $racer): string => $racer->outcome(), $attachers)];

    // The last repair may have run before the last attach: a final one must find nothing.
    expect(array_values(array_unique($outcomes)))->toBe(['repaired', 'attached'])
        ->and(Tagging::query()->count())->toBe(10)
        ->and($live->fresh()?->usage_count)->toBe(10)
        ->and(Tags::repair(new RepairOptions(paths: false, scopeKeys: false, orphans: false, dryRun: true))->counts)->toBe(0);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// Inside a host transaction a tagging write locks the tag rows it may touch before it reads the
// model's pivot rows with a locking read — the order a tag delete takes (tag rows, then pivot
// rows) — so the two never deadlock. The delete runs in a host transaction too, so neither side
// is retried: the absence of the cycle itself is what this proves.
test('a_nested_tagging_write_and_a_tag_delete_never_deadlock', function (string $verb): void {
    $x = Tags::type('tag')->make('X')->create();
    $article = Article::query()->create();
    $article->attachTags($x);
    $racer = new Racer;
    $started = false;

    // At the write's locking read of the article's pivot rows, a delete of x starts elsewhere.
    DB::listen(static function (QueryExecuted $query) use (&$started, $racer, $x): void {
        if ($started || ! str_contains(strtolower($query->sql), 'for update') || ! str_contains($query->sql, 'taggables')) {
            return;
        }

        $started = true;

        $racer->run(static fn (): string => DB::connection('racer')->transaction(static function () use ($x): string {
            Tags::type('tag')->delete(Tag::on('racer')->findOrFail($x->getKey()));

            return 'deleted';
        }))->waitUntilBlockedOrDone();
    });

    $outcome = 'done';

    try {
        DB::transaction(static fn () => match ($verb) {
            'detach' => $article->detachTags($x),
            'sync' => $article->syncTags(null, 'tag'),
            'clear' => $article->clearTags(),
            'attach' => $article->attachTags('y'),
        });
    } catch (QueryException $exception) {
        $outcome = 'error: '.$exception->getMessage();
    }

    expect($started)->toBeTrue()
        ->and($outcome)->toBe('done')
        ->and($racer->outcome())->toBe('deleted')
        ->and(Tagging::query()->where('tag_id', $x->getKey())->count())->toBe(0)
        ->and($x->fresh()?->usage_count)->toBe(0);
})->with(['detach', 'sync', 'clear', 'attach'])->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// The other side: the delete holds the tag row; a host transaction's detach waits for it instead
// of holding the pivot rows the delete needs.
test('the_hosts_transaction_survives_a_concurrent_tag_delete', function (): void {
    $x = Tags::type('tag')->make('X')->create();
    $article = Article::query()->create();
    $article->attachTags($x);
    $racer = new Racer;
    $started = false;

    DB::listen(static function (QueryExecuted $query) use (&$started, $racer, $x, $article): void {
        $sql = strtolower($query->sql);

        if ($started || ! str_contains($sql, 'for update') || (! str_contains($sql, '"tags"') && ! str_contains($sql, '`tags`'))) {
            return;
        }

        $started = true;

        $racer->run(static fn (): string => DB::connection('racer')->transaction(static function () use ($x, $article): string {
            $changes = Article::on('racer')->findOrFail($article->getKey())->detachTags(Tag::on('racer')->findOrFail($x->getKey()));

            return 'detached';
        }))->waitUntilBlockedOrDone();
    });

    Tags::type('tag')->delete($x);

    expect($started)->toBeTrue()
        ->and($racer->outcome())->toBe('detached')
        ->and(Tagging::query()->where('tag_id', $x->getKey())->count())->toBe(0);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// A detach decides from the taggings it read before it waited on the tag rows: a delete, restore
// and re-attach elsewhere landing in that wait must not cost the tag a count it still has.
test('detach_counts_only_rows_it_deleted', function (): void {
    $article = Article::query()->create(['title' => 'A']);
    $other = Article::query()->create(['title' => 'B']);
    $article->attachTags(['rock']);
    $rock = Tags::type('tag')->findOrFail('rock');
    $racer = new Racer;

    DB::transaction(function () use ($article, $other, $rock, $racer): void {
        Tag::query()->whereKey($rock->getKey())->lockForUpdate()->first();

        // The detach reads the article's taggings ({rock}), then waits on rock's row.
        $racer->run(static function () use ($article, $rock): string {
            $changes = Tags::detach(Article::query()->findOrFail($article->getKey()), Tag::query()->findOrFail($rock->getKey()));

            return 'detached '.count($changes->detached);
        })->waitUntilBlockedOrDone();

        // Meanwhile: deleted everywhere, restored, and put on another article.
        Tags::type('tag')->delete($rock);
        Tags::type('tag')->restore(Tag::query()->withTrashed()->findOrFail($rock->getKey()));
        $other->attachTags([Tag::query()->findOrFail($rock->getKey())]);
    });

    expect($racer->outcome())->toBe('detached 0')
        ->and(Tagging::query()->where('tag_id', $rock->getKey())->count())->toBe(1)
        ->and(Tag::query()->findOrFail($rock->getKey())->usage_count)->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
