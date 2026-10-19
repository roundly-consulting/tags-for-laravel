<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Relations;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use RoundlyConsulting\Tags\Exceptions\ReadOnlyTagsRelationException;

/**
 * The `tags()` relation of a taggable model (and the inverse `Tag::taggedModels()`): a plain
 * morph-to-many for every read — `with('tags')`, `whereHas`, `withCount`, `wherePivot`, … — whose
 * every write throws before touching a row. Writing a tagging past the package would skip the
 * type rules, scopes, usage counts, events and the fake, so the exception names the verb to use.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
final class TagsRelation extends MorphToMany
{
    /**
     * @param  mixed  $ids
     * @param  array<array-key, mixed>  $attributes
     * @param  bool  $touch
     */
    public function attach($ids, array $attributes = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('attach');
    }

    /**
     * @param  mixed  $ids
     * @param  bool  $touch
     */
    public function detach($ids = null, $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('detach');
    }

    /**
     * @param  mixed  $ids
     * @param  bool  $detaching
     */
    public function sync($ids, $detaching = true): never
    {
        throw ReadOnlyTagsRelationException::for('sync');
    }

    /**
     * @param  mixed  $ids
     */
    public function syncWithoutDetaching($ids): never
    {
        throw ReadOnlyTagsRelationException::for('sync_without_detaching');
    }

    /**
     * @param  mixed  $ids
     * @param  array<array-key, mixed>  $values
     */
    public function syncWithPivotValues($ids, array $values, bool $detaching = true): never
    {
        throw ReadOnlyTagsRelationException::for('sync');
    }

    /**
     * @param  mixed  $ids
     * @param  bool  $touch
     */
    public function toggle($ids, $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('toggle');
    }

    /**
     * @param  mixed  $id
     * @param  array<array-key, mixed>  $attributes
     * @param  bool  $touch
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('update_existing_pivot');
    }

    /**
     * @param  array<array-key, mixed>  $pivotAttributes
     * @param  bool  $touch
     */
    public function save(Model $model, array $pivotAttributes = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  array<array-key, mixed>  $pivotAttributes
     * @param  bool  $touch
     */
    public function saveQuietly(Model $model, array $pivotAttributes = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  mixed  $models
     * @param  array<array-key, mixed>  $pivotAttributes
     */
    public function saveMany($models, array $pivotAttributes = []): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  mixed  $models
     * @param  array<array-key, mixed>  $pivotAttributes
     */
    public function saveManyQuietly($models, array $pivotAttributes = []): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>  $joining
     * @param  bool  $touch
     */
    public function create(array $attributes = [], array $joining = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>  $joining
     * @param  bool  $touch
     */
    public function createQuietly(array $attributes = [], array $joining = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  iterable<array-key, mixed>  $records
     * @param  array<array-key, mixed>  $joinings
     */
    public function createMany(iterable $records, array $joinings = []): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  iterable<array-key, mixed>  $records
     * @param  array<array-key, mixed>  $joinings
     */
    public function createManyQuietly(iterable $records, array $joinings = []): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  Closure|array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $joining
     * @param  bool  $touch
     */
    public function firstOrCreate(array $attributes = [], Closure|array $values = [], array $joining = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  Closure|array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $joining
     * @param  bool  $touch
     */
    public function createOrFirst(array $attributes = [], Closure|array $values = [], array $joining = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  Closure|array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $joining
     * @param  bool  $touch
     */
    public function updateOrCreate(array $attributes, Closure|array $values = [], array $joining = [], $touch = true): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  mixed  $default
     * @param  mixed  $step
     * @param  array<array-key, mixed>  $extra
     */
    public function incrementOrCreate(array $attributes, string $column = 'count', $default = 1, $step = 1, array $extra = []): never
    {
        throw ReadOnlyTagsRelationException::for('create');
    }
}
