<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Relations\TagsRelation;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Support\TagScopes;
use RoundlyConsulting\Tags\TagsManager;

/**
 * Tags on an Eloquent model. Implement {@see Taggable} and use this trait:
 *
 * ```php
 * $concert->syncTags($jazz, 'genre')->attachTags(['Live', 'Outdoor'], 'label');
 * $concert->tagOf('genre')?->name;
 * Concert::withAnyTags(['music'], 'genre', descendants: true)->get();
 * ```
 *
 * Every write goes through the manager (`Tags::for($this)`), so the type rules, scopes, usage
 * counts, events and `Tags::fake()` all apply; the type is always the last, optional argument.
 * Reads of one type filter the loaded `tags` relation in memory when it is loaded.
 *
 * A force delete (or a delete without soft deletes) detaches every tag. A mass delete
 * (`Model::query()->delete()`) fires no model event: run `php artisan tags:repair --orphans`.
 *
 * Reserved names: tags, tagsOf, tagOf, hasTag, hasAnyTags, hasAllTags, attachTags, detachTags,
 * syncTags, clearTags, tagScope, tagTypes, bootHasTags, scopeWithAnyTags, scopeWithAllTags,
 * scopeWithoutTags, scopeWithTagsOf.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Taggable
 */
trait HasTags
{
    public static function bootHasTags(): void
    {
        static::deleted(static function (Model $model): void {
            if (! $model instanceof Taggable) {
                return;
            }

            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);

            if ($softDeletes && ! (method_exists($model, 'isForceDeleting') && $model->isForceDeleting())) {
                return;
            }

            app(TagsManager::class)->clearDeleted($model);
        });
    }

    /**
     * The model's tags, ordered by position. Read-only: every write method throws and names the
     * verb to use.
     *
     * @return TagsRelation<Tag, $this>
     */
    public function tags(): TagsRelation
    {
        $instance = $this->newRelatedInstance(TagModel::class());

        $relation = new TagsRelation(
            $instance->newQuery(),
            $this,
            'taggable',
            TagsConfig::table('taggables'),
            'taggable_id',
            'tag_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'tags',
            false,
        );

        $relation->using(Tagging::class)->withPivot('type', 'created_at');
        $relation->getQuery()->orderBy($instance->qualifyColumn('position'))->orderBy($instance->getQualifiedKeyName());

        return $relation;
    }

    /**
     * The owner of this model's free-form tags, or null for global tags only.
     */
    public function tagScope(): ?Model
    {
        return null;
    }

    /**
     * The tag types this model may carry, or null for any defined type.
     *
     * @return list<string|BackedEnum>|null
     */
    public function tagTypes(): ?array
    {
        return null;
    }

    /**
     * The model's tags of one type: the loaded relation filtered in memory, else one query.
     *
     * @return Collection<int, Tag>
     */
    public function tagsOf(string|BackedEnum $type): Collection
    {
        return app(TagsManager::class)->for($this)->of($type);
    }

    /**
     * The model's one tag of a type (its category), or null.
     */
    public function tagOf(string|BackedEnum $type): ?Tag
    {
        return app(TagsManager::class)->for($this)->one($type);
    }

    public function hasTag(Tag|int|string $tag, string|BackedEnum|null $type = null): bool
    {
        return app(TagsManager::class)->for($this)->has($tag, $type);
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function hasAnyTags(mixed $tags, string|BackedEnum|null $type = null): bool
    {
        return app(TagsManager::class)->for($this)->hasAny($tags, $type);
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function hasAllTags(mixed $tags, string|BackedEnum|null $type = null): bool
    {
        return app(TagsManager::class)->for($this)->hasAll($tags, $type);
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function attachTags(mixed $tags, string|BackedEnum|null $type = null): static
    {
        app(TagsManager::class)->for($this)->attach($tags, $type);

        return $this;
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function detachTags(mixed $tags, string|BackedEnum|null $type = null): static
    {
        app(TagsManager::class)->for($this)->detach($tags, $type);

        return $this;
    }

    /**
     * Make this model's tags of one type exactly these: `syncTags($jazz, 'genre')` sets a category,
     * `syncTags(null, 'genre')` clears it.
     *
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function syncTags(mixed $tags, string|BackedEnum|null $type = null): static
    {
        app(TagsManager::class)->for($this)->sync($tags, $type);

        return $this;
    }

    public function clearTags(string|BackedEnum|null $type = null): static
    {
        app(TagsManager::class)->for($this)->clear($type);

        return $this;
    }

    /**
     * Models carrying at least one of the tags.
     *
     * @param  Builder<static>  $query
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function scopeWithAnyTags(Builder $query, mixed $tags, string|BackedEnum|null $type = null, bool $descendants = false, ?Model $within = null): void
    {
        TagScopes::any($query, $tags, $type, $descendants, $within);
    }

    /**
     * Models carrying every one of the tags.
     *
     * @param  Builder<static>  $query
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function scopeWithAllTags(Builder $query, mixed $tags, string|BackedEnum|null $type = null, bool $descendants = false, ?Model $within = null): void
    {
        TagScopes::all($query, $tags, $type, $descendants, $within);
    }

    /**
     * Models carrying none of the tags; with none given, no tag of the type (or no tag at all).
     *
     * @param  Builder<static>  $query
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function scopeWithoutTags(Builder $query, mixed $tags = [], string|BackedEnum|null $type = null, bool $descendants = false, ?Model $within = null): void
    {
        TagScopes::none($query, $tags, $type, $descendants, $within);
    }

    /**
     * Models carrying at least one tag of the type.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithTagsOf(Builder $query, string|BackedEnum $type): void
    {
        TagScopes::ofType($query, $type);
    }
}
