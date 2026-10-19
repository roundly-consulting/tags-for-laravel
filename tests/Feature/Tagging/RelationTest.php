<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tags\Events\Tagged;
use RoundlyConsulting\Tags\Exceptions\ReadOnlyTagsRelationException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Relations\TagsRelation;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

beforeEach(function (): void {
    $this->article = Article::query()->create(['title' => 'Concert']);
    $this->tag = Tags::type('tag')->make('Live')->create();
});

/**
 * Every write method of the relation, with the verb its message names.
 *
 * @return array<string, array{0: Closure(TagsRelation<Tag, Article>|TagsRelation<Article, Tag>, Tag, Article): mixed, 1: string}>
 */
function relationWrites(): array
{
    return [
        'attach' => [fn ($relation, $tag) => $relation->attach($tag->getKey()), 'attachTags()'],
        'detach' => [fn ($relation, $tag) => $relation->detach($tag->getKey()), 'detachTags()'],
        'sync' => [fn ($relation, $tag) => $relation->sync([$tag->getKey()]), 'syncTags()'],
        'syncWithoutDetaching' => [fn ($relation, $tag) => $relation->syncWithoutDetaching([$tag->getKey()]), 'attachTags()'],
        'syncWithPivotValues' => [fn ($relation, $tag) => $relation->syncWithPivotValues([$tag->getKey()], ['type' => 'tag']), 'syncTags()'],
        'toggle' => [fn ($relation, $tag) => $relation->toggle([$tag->getKey()]), 'detachTags()'],
        'updateExistingPivot' => [fn ($relation, $tag) => $relation->updateExistingPivot($tag->getKey(), ['type' => 'x']), 'no editable pivot columns'],
        'save' => [fn ($relation, $tag, $model) => $relation->save($model), 'attachTags()'],
        'saveQuietly' => [fn ($relation, $tag, $model) => $relation->saveQuietly($model), 'attachTags()'],
        'saveMany' => [fn ($relation, $tag, $model) => $relation->saveMany([$model]), 'attachTags()'],
        'saveManyQuietly' => [fn ($relation, $tag, $model) => $relation->saveManyQuietly([$model]), 'attachTags()'],
        'create' => [fn ($relation) => $relation->create(['name' => ['en' => 'Made']]), 'findOrCreate()'],
        'createQuietly' => [fn ($relation) => $relation->createQuietly(['name' => ['en' => 'Made']]), 'findOrCreate()'],
        'createMany' => [fn ($relation) => $relation->createMany([['name' => ['en' => 'Made']]]), 'findOrCreate()'],
        'createManyQuietly' => [fn ($relation) => $relation->createManyQuietly([['name' => ['en' => 'Made']]]), 'findOrCreate()'],
        'firstOrCreate' => [fn ($relation) => $relation->firstOrCreate(['type' => 'tag']), 'findOrCreate()'],
        'createOrFirst' => [fn ($relation) => $relation->createOrFirst(['type' => 'tag']), 'findOrCreate()'],
        'updateOrCreate' => [fn ($relation) => $relation->updateOrCreate(['type' => 'tag']), 'findOrCreate()'],
        'incrementOrCreate' => [fn ($relation) => $relation->incrementOrCreate(['type' => 'tag']), 'findOrCreate()'],
    ];
}

test('tags_relation_writes_throw_naming_the_trait_verb', function (Closure $write, string $verb): void {
    $before = [Tag::query()->withTrashed()->count(), Tagging::query()->count()];

    try {
        $write($this->article->tags(), $this->tag, Tag::query()->firstOrFail());
        $this->fail('The write must throw.');
    } catch (ReadOnlyTagsRelationException $exception) {
        expect($exception->getMessage())->toContain($verb)->not->toContain('\\');
    }

    expect([Tag::query()->withTrashed()->count(), Tagging::query()->count()])->toBe($before);
})->with(relationWrites());

test('inverse_tagged_models_relation_is_read_only_too', function (Closure $write, string $verb): void {
    $before = [Article::query()->count(), Tagging::query()->count()];

    expect(fn () => $write($this->tag->taggedModels(Article::class), $this->tag, $this->article))->toThrow(ReadOnlyTagsRelationException::class, $verb)
        ->and([Article::query()->count(), Tagging::query()->count()])->toBe($before);
})->with(array_filter(relationWrites(), static fn (string $method): bool => in_array($method, ['attach', 'detach', 'sync', 'toggle', 'save', 'create'], true), ARRAY_FILTER_USE_KEY));

test('tags_relation_reads_and_eager_loads_still_work', function (): void {
    $this->article->attachTags(['Live', 'Outdoor']);
    $other = Article::query()->create(['title' => 'Other']);
    $other->attachTags('Live');

    $loaded = Article::query()->with('tags')->orderBy('id')->get();
    $first = $loaded->first();

    expect($this->article->tags())->toBeInstanceOf(MorphToMany::class)
        ->and($first?->tags->pluck('slug')->all())->toBe(['live', 'outdoor'])
        ->and($first?->tags->first()?->pivot?->getAttribute('type'))->toBe('tag')
        ->and(Article::query()->whereHas('tags', fn ($query) => $query->where('tags.id', $this->tag->getKey()))->count())->toBe(2)
        ->and(Article::query()->withCount('tags')->orderBy('id')->pluck('tags_count')->all())->toBe([2, 1])
        ->and($this->article->tags()->wherePivot('type', 'tag')->count())->toBe(2)
        ->and($this->article->tags()->exists())->toBeTrue()
        ->and($this->tag->taggedModels(Article::class)->count())->toBe(2)
        ->and($this->tag->taggings()->count())->toBe(2);
});

test('has_attached_throws_with_the_factory_recipe', function (): void {
    expect(fn () => Article::factory()->hasAttached(Tag::factory(), [], 'tags')->create())
        ->toThrow(ReadOnlyTagsRelationException::class, 'afterCreating(fn ($model) => $model->attachTags(...))')
        ->and(Tagging::query()->count())->toBe(0);
});

test('after_creating_recipe_tags_through_the_verbs', function (): void {
    Event::fake([Tagged::class]);

    $made = Article::factory()
        ->afterCreating(static fn (Article $article): Article => $article->attachTags(['live'], 'tag'))
        ->create();

    expect($made->tagsOf('tag')->pluck('slug')->all())->toBe(['live'])
        ->and($this->tag->fresh()?->usage_count)->toBe(1);

    Event::assertDispatched(Tagged::class);
});

test('read_only_messages_name_no_classes', function (string $group): void {
    app()->setLocale('en');
    $english = ReadOnlyTagsRelationException::for($group)->getMessage();
    app()->setLocale('sk');
    $slovak = ReadOnlyTagsRelationException::for($group)->getMessage();

    expect($english)->not->toContain('\\')->not->toStartWith('tags::')
        ->and($slovak)->not->toContain('\\')->not->toStartWith('tags::')
        ->and($slovak)->not->toBe($english);
})->with(['attach', 'detach', 'sync', 'sync_without_detaching', 'toggle', 'update_existing_pivot', 'create']);
