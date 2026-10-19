<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Closure;
use Illuminate\Database\Connection;

/**
 * Every mutating action runs here, on the tag model's connection: retried after a deadlock when
 * this is the outermost transaction (Laravel never retries a nested one). Each attempt re-reads
 * its rows, so a retry never builds on a rolled-back model.
 *
 * @internal
 */
final class TagTransaction
{
    public const int ATTEMPTS = 3;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        return self::connection()->transaction($callback, self::ATTEMPTS);
    }

    /**
     * A savepoint inside the running transaction: a failed statement rolls back only itself, so
     * PostgreSQL keeps the outer transaction usable.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function savepoint(Closure $callback): mixed
    {
        return self::connection()->transaction($callback);
    }

    public static function connection(): Connection
    {
        return TagModel::make()->getConnection();
    }
}
