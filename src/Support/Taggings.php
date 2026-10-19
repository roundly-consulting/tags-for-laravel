<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Exceptions\UnsavedTaggableException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The taggable side of a tagging write: the model's candidate scopes, its lock and its current
 * taggings.
 *
 * @internal
 */
final class Taggings
{
    /**
     * A taggable must be saved, and its key must fit `tags.key_type`.
     */
    public static function ensureTaggable(Model&Taggable $taggable): void
    {
        if (! $taggable->exists || $taggable->getKey() === null) {
            throw UnsavedTaggableException::make(class_basename($taggable));
        }

        KeyShape::ensureOutbound($taggable);
    }

    /**
     * The scope keys a string resolves in for this model and type, the model's scope first.
     *
     * @return list<string>
     */
    public static function candidateScopeKeys(Model&Taggable $taggable, TagType $type): array
    {
        $scope = self::creationScope($taggable, $type);

        return $scope === null ? [ScopeKey::GLOBAL] : [ScopeKey::for($scope), ScopeKey::GLOBAL];
    }

    /**
     * Where a free-form tag of this type is created for this model: its scope, or global for a
     * global-only type or a model without a scope.
     */
    public static function creationScope(Model&Taggable $taggable, TagType $type): ?Model
    {
        return $type->globalOnly ? null : $taggable->tagScope();
    }

    /**
     * The type a tagging call's names are in, or null when it names no tag by name (or there is
     * no default type: resolution reports that).
     */
    public static function typeOfNames(TagSelection $selection, TagTypeRegistry $registry): ?TagType
    {
        foreach ($selection->tags as $tag) {
            if (is_string($tag)) {
                return $selection->type === null ? $registry->defaultType() : $registry->resolve($selection->type);
            }
        }

        return null;
    }

    /**
     * Before the model's row: the tree lock of a free-form tree type whose names this write may
     * create or bring back — the lock order every tree write takes (tree, then rows).
     */
    public static function lockTreeForNames(Model&Taggable $taggable, ?TagType $type): void
    {
        if ($type !== null && $type->hierarchical && ! $type->curated) {
            TreeLock::lock($type->name, ScopeKey::for(self::creationScope($taggable, $type)));
        }
    }

    /**
     * Lock the taggable's own row: every tagging write of one model serialises here, so the max
     * rule and a sync hold under concurrent writers.
     */
    public static function lock(Model $taggable): void
    {
        $locked = $taggable->newQueryWithoutScopes()->whereKey($taggable->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw UnsavedTaggableException::make(class_basename($taggable));
        }
    }

    /**
     * Whether the tagging write runs inside a transaction the host opened. Every write locks the
     * model's row as its first statement, so in a transaction of its own its later plain reads see
     * what the previous writer committed (MySQL's REPEATABLE READ takes its snapshot at the first
     * plain read). A host transaction may have taken that snapshot earlier: then the current
     * taggings are read with a locking read, which always sees the latest committed rows.
     */
    public static function nested(): bool
    {
        return TagTransaction::connection()->transactionLevel() > 1;
    }

    /**
     * The model's current taggings: tag key => type, optionally of one type, with a locking read
     * when `$locked`. Taggings of a deleted tag (a plain `$tag->delete()` leaves them behind) are
     * not carried: they count against no max and no write touches them, unless
     * `$includeDeletedTags` (the deletion hook removes every row of a model that is gone).
     *
     * @return array<string, string>
     */
    public static function current(Model $taggable, ?string $type = null, bool $locked = false, bool $includeDeletedTags = false): array
    {
        $query = Tagging::query()
            ->where('taggable_type', $taggable->getMorphClass())
            ->where('taggable_id', $taggable->getKey());

        if (! $includeDeletedTags) {
            $query->whereIn('tag_id', self::liveTagKeys());
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        if ($locked) {
            $query->lockForUpdate();
        }

        $current = [];

        foreach ($query->get(['tag_id', 'type']) as $tagging) {
            $current[(string) $tagging->tag_id] = $tagging->type;
        }

        return $current;
    }

    /**
     * The keys of every live tag, as a subquery: what a pivot read narrows to so a deleted tag's
     * leftover taggings never count.
     */
    public static function liveTagKeys(): QueryBuilder
    {
        $tags = TagModel::make();

        return TagModel::query()->select($tags->getQualifiedKeyName())->toBase();
    }

    /**
     * Remove every tagging of a tag and zero its usage count — before a restore, so a tag a plain
     * `$tag->delete()` soft-deleted without detaching comes back clean. On the designed path
     * (`Tags::type($type)->delete()` detached everything) there is nothing to remove. Runs in the
     * caller's transaction, after the caller locked the tag row.
     *
     * @return int the taggings removed
     */
    public static function purge(Tag $tag): int
    {
        $removed = Tagging::query()->where('tag_id', $tag->getKey())->delete();

        if ($tag->usage_count !== 0) {
            TagModel::query()->withTrashed()->whereKey($tag->getKey())->toBase()->update(['usage_count' => 0]);
            $tag->usage_count = 0;
            $tag->syncOriginalAttribute('usage_count');
        }

        return $removed;
    }
}
