<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

/*
 * A host (or an admin panel) deletes a tag with a plain `$tag->delete()`: a soft delete that
 * detaches nothing. Every later path must behave as if the taggings were gone.
 */

beforeEach(function (): void {
    Tags::define('label')->max(2);
});

test('a_direct_eloquent_delete_never_resurrects_taggings', function (): void {
    $first = Article::query()->create(['title' => 'first']);
    $first->attachTags('rock', 'label');
    $rock = Tag::query()->firstOrFail();

    $rock->delete();

    $second = Article::query()->create(['title' => 'second']);
    $second->attachTags('rock', 'label');

    expect($first->tagsOf('label'))->toHaveCount(0)
        ->and($second->tagsOf('label')->pluck('slug')->all())->toBe(['rock'])
        ->and($rock->fresh()?->usage_count)->toBe(1)
        ->and(Tagging::query()->count())->toBe(1);
});

test('restore_after_a_direct_delete_starts_clean', function (string $via): void {
    $article = Article::query()->create();
    $article->attachTags(['rock', 'pop'], 'label');
    $rock = Tags::type('label')->findOrFail('rock');
    $rock->delete();

    $restored = match ($via) {
        'handle' => Tags::type('label')->restore($rock),
        'find or create' => Tags::type('label')->findOrCreate('Rock'),
        'create' => Tags::type('label')->make('Rock')->create(),
    };

    expect($restored->is($rock))->toBeTrue()
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->usage_count)->toBe(0)
        ->and(Tagging::query()->where('tag_id', $rock->getKey())->count())->toBe(0)
        ->and($article->tagsOf('label')->pluck('slug')->all())->toBe(['pop']);
})->with(['handle', 'find or create', 'create']);

test('a_direct_delete_frees_the_max_and_the_name', function (): void {
    $article = Article::query()->create();
    $article->attachTags(['rock', 'pop'], 'label');
    Tags::type('label')->findOrFail('rock')->delete();

    // The deleted tag's leftover tagging neither counts against the max…
    $article->attachTags('jazz', 'label');

    expect($article->tagsOf('label')->pluck('slug')->sort()->values()->all())->toBe(['jazz', 'pop']);

    // …nor hides the name when the same model takes it again.
    $article->detachTags('jazz', 'label');
    $article->attachTags('rock', 'label');

    $rock = Tags::type('label')->findOrFail('rock');

    expect($article->tagsOf('label')->pluck('slug')->sort()->values()->all())->toBe(['pop', 'rock'])
        ->and($rock->usage_count)->toBe(1)
        ->and(Tagging::query()->where('tag_id', $rock->getKey())->count())->toBe(1);
});

test('scopes_ignore_taggings_of_deleted_tags', function (): void {
    $only = Article::query()->create(['title' => 'only']);
    $only->attachTags('rock', 'label');
    $other = Article::query()->create(['title' => 'other']);
    $other->attachTags('pop', 'label');
    Tags::type('label')->findOrFail('rock')->delete();

    expect(Article::query()->withoutTags([], 'label')->pluck('title')->all())->toBe(['only'])
        ->and(Article::query()->withoutTags([])->pluck('title')->all())->toBe(['only'])
        ->and(Article::query()->withTagsOf('label')->pluck('title')->all())->toBe(['other'])
        ->and(Article::query()->withAnyTags(['rock', 'pop'], 'label')->pluck('title')->all())->toBe(['other']);
});
