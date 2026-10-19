<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * The one place the (possibly host-swapped) tag model is resolved. Every query, relation,
 * factory and action reaches the tag model through here, never through `Tag::query()`, so a
 * host's subclass is used everywhere.
 *
 * @internal
 */
final class TagModel
{
    /**
     * @return class-string<Tag>
     */
    public static function class(): string
    {
        return ModelResolver::for('tags.models.tag', Tag::class);
    }

    /**
     * A query on the configured tag model.
     *
     * @return Builder<Tag>
     */
    public static function query(): Builder
    {
        return self::class()::query();
    }

    public static function make(): Tag
    {
        $class = self::class();

        return new $class;
    }
}
