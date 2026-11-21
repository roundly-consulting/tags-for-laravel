<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Testing\Concurrency\Racer;

/**
 * Merges against a second session on a real engine (pgsql, mysql): the parent holds its
 * transaction open while a forked racer runs the competing write, then commits.
 */
beforeEach(function (): void {
    $this->tags = Tags::type('tag');
    $this->from = $this->tags->make('Rock')->create();
    $this->into = $this->tags->make('Rock music')->create();
    $this->other = $this->tags->make('Stone')->create();
    $this->carrier = Article::query()->create();
    $this->carrier->attachTags($this->from);
    Note::query()->create()->attachTags([$this->from, $this->into]);
});

/**
 * @return int how many taggings point at the tag
 */
function taggingsOf(Tag $tag): int
{
    return Tagging::query()->where('tag_id', $tag->getKey())->count();
}

test('two_merges_of_one_tag_at_once_one_wins_the_other_is_refused', function (): void {
    $racer = new Racer;

    DB::transaction(function () use ($racer): void {
        $this->tags->merge($this->from, $this->into);

        $racer->run(function (): string {
            try {
                Tags::type('tag')->merge($this->from, $this->other);

                return 'merged';
            } catch (TagNotFoundException) {
                return 'refused';
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('refused')
        ->and(taggingsOf($this->from))->toBe(0)
        ->and(taggingsOf($this->into))->toBe(2)
        ->and(taggingsOf($this->other))->toBe(0)
        ->and($this->into->fresh()?->usage_count)->toBe(2)
        ->and($this->other->fresh()?->usage_count)->toBe(0)
        ->and($this->from->fresh()?->trashed())->toBeTrue();
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl + posix')->group('real-engine');

test('two_merges_of_one_tree_tag_at_once_one_wins_the_other_is_refused', function (): void {
    Tags::define('genre')->hierarchical();
    $genres = Tags::type('genre');
    $from = $genres->make('Jazz')->create();
    $genres->make('Bebop')->under($from)->create();
    $into = $genres->make('Music')->create();
    $other = $genres->make('Sound')->create();
    $racer = new Racer;

    DB::transaction(function () use ($racer, $genres, $from, $into, $other): void {
        $genres->merge($from, $into, TagChildren::Reparent);

        $racer->run(static function () use ($from, $other): string {
            try {
                Tags::type('genre')->merge($from, $other, TagChildren::Reparent);

                return 'merged';
            } catch (TagNotFoundException) {
                return 'refused';
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('refused')
        ->and($into->children()->get()->pluck('slug')->all())->toBe(['bebop'])
        ->and($other->children()->count())->toBe(0);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl + posix')->group('real-engine');

// The merge holds the source's row: the attach waits, then finds the tag deleted.
test('an_attach_of_the_source_during_a_merge_is_refused_and_orphans_nothing', function (): void {
    $late = Article::query()->create();
    $racer = new Racer;

    DB::transaction(function () use ($racer, $late): void {
        $this->tags->merge($this->from, $this->into);

        $racer->run(function () use ($late): string {
            try {
                Article::query()->findOrFail($late->getKey())->attachTags($this->from);

                return 'attached';
            } catch (TagNotFoundException) {
                return 'refused';
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('refused')
        ->and(taggingsOf($this->from))->toBe(0)
        ->and($late->tagsOf('tag')->all())->toBe([])
        ->and(taggingsOf($this->into))->toBe(2);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl + posix')->group('real-engine');

// The attach holds the source's row: the merge waits, then moves the new tagging too.
test('an_attach_of_the_source_before_a_merge_commits_is_moved', function (): void {
    $early = Article::query()->create();
    $racer = new Racer;

    DB::transaction(function () use ($racer, $early): void {
        $early->attachTags($this->from);

        $racer->run(function (): string {
            $merge = Tags::type('tag')->merge($this->from, $this->into);

            return $merge->moved.'/'.$merge->deduplicated.'/'.$merge->into->usage_count;
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('2/1/3')
        ->and(taggingsOf($this->from))->toBe(0)
        ->and($early->tagsOf('tag')->modelKeys())->toBe([$this->into->getKey()])
        ->and($this->into->fresh()?->usage_count)->toBe(3);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl + posix')->group('real-engine');

// The merge holds the rows of the records it moves taggings on: a write on one of them waits,
// then decides on the moved tagging — never a duplicate insert.
test('an_attach_of_the_target_on_a_record_being_merged_waits_and_adds_nothing', function (): void {
    $racer = new Racer;

    DB::transaction(function () use ($racer): void {
        $this->tags->merge($this->from, $this->into);

        $racer->run(function (): string {
            $changes = Tags::attach(Article::query()->findOrFail($this->carrier->getKey()), $this->into);

            return 'attached '.count($changes->attached);
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('attached 0')
        ->and($this->carrier->tagsOf('tag')->modelKeys())->toBe([$this->into->getKey()])
        ->and(taggingsOf($this->into))->toBe(2)
        ->and($this->into->fresh()?->usage_count)->toBe(2);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl + posix')->group('real-engine');
