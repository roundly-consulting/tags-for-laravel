<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\TagLock;

/**
 * The lock of one tree — one (type, scope) — that every tree write takes first: creating in a
 * tree, moving, reordering, deleting with children, restoring.
 *
 * Under the lock, tree writes see the tree as the previous writer committed it: in a transaction
 * of its own the lock is the first statement, so the first plain read after it takes MySQL's
 * REPEATABLE READ snapshot. A host transaction may have taken its snapshot before the lock — so
 * there, every tree row a write decides on is read with a locking read, which always sees the
 * latest committed version ({@see self::read()}).
 *
 * @internal
 */
final class TreeLock
{
    public static function lock(string $type, string $scopeKey): void
    {
        LockedRow::mutex(TagLock::query(), ['type' => $type, 'scope_key' => $scopeKey]);
    }

    /**
     * A read of tree rows after the lock: a locking read inside a host transaction.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function read(Builder $query): Builder
    {
        return Taggings::nested() ? $query->lockForUpdate() : $query;
    }

    /**
     * The largest value of an integer column over the rows, read like {@see self::read()} —
     * as the top row, since PostgreSQL refuses `FOR UPDATE` on an aggregate.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function max(Builder $query, string $column): ?int
    {
        $value = self::read((clone $query)->reorder()->orderByDesc($column))->value($column);

        return is_numeric($value) ? (int) $value : null;
    }
}
