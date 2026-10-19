<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;

/**
 * The lock-first row pattern (a local copy of lifecycle's `LockedRow::mutex`): the row with a
 * unique key, created when missing, locked `FOR UPDATE` until the transaction ends.
 *
 * Plain `firstOrCreate()` is not enough: its fallback is a non-locking read, which under MySQL
 * REPEATABLE READ can miss the row another transaction just committed. An existing row is locked
 * by its unique key; a missing one is inserted with `INSERT … ON DUPLICATE KEY UPDATE` (MySQL) or
 * `INSERT … ON CONFLICT DO NOTHING` (elsewhere) and then locked with a fresh, locking read.
 *
 * @internal
 */
final class LockedRow
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  non-empty-array<non-empty-string, mixed>  $attributes  the unique key
     * @return TModel
     */
    public static function mutex(Builder $query, array $attributes): Model
    {
        $existing = (clone $query)->where($attributes)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $model = $query->getModel()->newInstance($attributes);

        if ($model->usesTimestamps()) {
            $model->updateTimestamps();
        }

        $driver = DatabaseDriver::tryFrom($model->getConnection()->getDriverName());

        $driver === DatabaseDriver::Mysql || $driver === DatabaseDriver::Mariadb
            ? $query->toBase()->upsert([$model->getAttributes()], array_keys($attributes), [array_key_first($attributes)])
            : $query->toBase()->insertOrIgnore([$model->getAttributes()]);

        return (clone $query)->where($attributes)->lockForUpdate()->firstOrFail();
    }
}
