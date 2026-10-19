<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\PackageToolkit\Support\LikeEscaper;
use RoundlyConsulting\PackageToolkit\Support\RawExpression;

/**
 * A prefix `LIKE` with the wildcards escaped and an explicit escape character, which SQLite has
 * no default for. Plain `like`: paths and slugs are produced by the package in lower case, so the
 * match is the same on every engine.
 *
 * @internal
 */
final class LikeClause
{
    /**
     * @template TBuilder of Builder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function startsWith(BuilderContract $query, string $column, string $prefix, string $boolean = 'and'): BuilderContract
    {
        $base = $query instanceof Builder ? $query->getQuery() : $query;
        $wrapped = $base->getGrammar()->wrap($column);

        $query->whereRaw(new RawExpression("{$wrapped} like ? escape ?"), [LikeEscaper::escape($prefix).'%', '\\'], $boolean);

        return $query;
    }
}
