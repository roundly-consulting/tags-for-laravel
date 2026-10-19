<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Tags\Models\Tag;

/**
 * The materialised ancestor path: "/" for a root, "/{rootId}/…/{parentId}/" below it.
 *
 * @internal
 */
final class TagPath
{
    public const ROOT = '/';

    /**
     * The path a child of `$parent` gets (a root's when `$parent` is null).
     */
    public static function under(?Tag $parent): string
    {
        return $parent === null ? self::ROOT : $parent->path.$parent->getKey().'/';
    }

    public static function depthUnder(?Tag $parent): int
    {
        return $parent === null ? 1 : $parent->depth + 1;
    }

    /**
     * The prefix every descendant's path starts with.
     */
    public static function subtreePrefix(Tag $tag): string
    {
        return $tag->path.$tag->getKey().'/';
    }

    /**
     * Whether `$candidate` sits in `$tag`'s subtree (or is `$tag` itself) — the cycle check.
     */
    public static function isWithin(Tag $candidate, Tag $tag): bool
    {
        return str_contains($candidate->path.$candidate->getKey().'/', '/'.$tag->getKey().'/');
    }

    /**
     * The ancestor keys a path names, root first.
     *
     * @return list<string>
     */
    public static function ancestorKeys(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    }
}
