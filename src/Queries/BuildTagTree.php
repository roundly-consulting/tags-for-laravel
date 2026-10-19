<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Queries;

use RoundlyConsulting\Tags\DataTransferObjects\TagNode;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * A tree of one type, in one query: every live tag of the readable scopes (or `$root` and its
 * subtree), ordered by depth, position and key, nested in memory from `parent_id`. Resolved from
 * the container, so a host may override it.
 */
final readonly class BuildTagTree
{
    /**
     * @param  list<string>  $scopeKeys
     * @return list<TagNode>
     */
    public function build(TagType $type, array $scopeKeys, ?Tag $root = null): array
    {
        $instance = TagModel::make();
        $query = TagModel::query()->where('type', $type->name)->whereIn('scope_key', $scopeKeys);

        if ($root !== null) {
            $query->descendantsOf($root, self: true);
        }

        $tags = $query->orderBy('depth')->orderBy('position')->orderBy($instance->getKeyName())->get();

        /** @var array<string, list<Tag>> $children */
        $children = [];
        $keys = [];

        foreach ($tags as $tag) {
            $keys[(string) $tag->getKey()] = true;
        }

        $tops = [];

        foreach ($tags as $tag) {
            $parent = $tag->parent_id === null ? null : (string) $tag->parent_id;

            if ($parent !== null && isset($keys[$parent]) && ($root === null || (string) $tag->getKey() !== (string) $root->getKey())) {
                $children[$parent][] = $tag;
            } elseif ($root === null || (string) $tag->getKey() === (string) $root->getKey()) {
                $tops[] = $tag;
            }
        }

        return array_map(static fn (Tag $tag): TagNode => self::node($tag, $children), $tops);
    }

    /**
     * @param  array<string, list<Tag>>  $children
     */
    private static function node(Tag $tag, array $children): TagNode
    {
        return new TagNode(
            $tag,
            array_map(static fn (Tag $child): TagNode => self::node($child, $children), $children[(string) $tag->getKey()] ?? []),
        );
    }
}
