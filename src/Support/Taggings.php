<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\Exceptions\UnsavedTaggableException;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Types\TagType;

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
     * when `$locked`.
     *
     * @return array<string, string>
     */
    public static function current(Model $taggable, ?string $type = null, bool $locked = false): array
    {
        $query = Tagging::query()
            ->where('taggable_type', $taggable->getMorphClass())
            ->where('taggable_id', $taggable->getKey());

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
}
