<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use RoundlyConsulting\Tags\Actions\Internal\RewriteSubtreePathsAction;
use RoundlyConsulting\Tags\Events\TagMoved;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagAttributes;
use RoundlyConsulting\Tags\Support\TagDrafts;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Support\TreeLock;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Move a tag under another parent (`under: null` makes it a root), optionally to a position
 * among its new siblings. Refuses itself and its own descendants (a cycle), a parent of another
 * type or scope, and a move that would make any node of its subtree deeper than the type allows.
 * Runs under the tree lock and rewrites the subtree's paths and depths.
 */
final readonly class MoveTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
        private RewriteSubtreePathsAction $rewrite,
    ) {}

    public function execute(Tag $tag, ?Tag $under = null, ?int $position = null): Tag
    {
        $type = $this->registry->resolve($tag->type);
        $position = TagAttributes::position($position);
        $rewrite = $this->rewrite;

        if ($under !== null) {
            TagDrafts::ensureParent($type, $tag->scope_key, $under);
        }

        return TagTransaction::run(static function () use ($tag, $under, $position, $type, $rewrite): Tag {
            TreeLock::lock($tag->type, $tag->scope_key);

            $fresh = TreeLock::read(TagModel::query()->whereKey($tag->getKey()))->first() ?? throw TagNotFoundException::trashed();
            $parent = null;

            if ($under !== null) {
                $parent = TreeLock::read(TagModel::query()->whereKey($under->getKey()))->first() ?? throw InvalidTagHierarchyException::parentTrashed();

                if (TagPath::isWithin($parent, $fresh)) {
                    throw InvalidTagHierarchyException::cycle();
                }
            }

            if ($type->maxDepth !== null) {
                // The live subtree: a deleted descendant moves along, and is refused at its restore
                // if that would put it deeper than the cap.
                $deepest = TreeLock::max($fresh->descendants(), 'depth');
                $height = $deepest === null ? 0 : $deepest - $fresh->depth;

                if (TagPath::depthUnder($parent) + $height > $type->maxDepth) {
                    throw InvalidTagHierarchyException::tooDeep($type->maxDepth);
                }
            }

            $from = $fresh->parent_id === null ? null : TreeLock::read(TagModel::query()->whereKey($fresh->parent_id))->first();
            $oldPrefix = TagPath::subtreePrefix($fresh);
            $oldDepth = $fresh->depth;

            $fresh->forceFill([
                'parent_id' => $parent?->getKey(),
                'path' => TagPath::under($parent),
                'depth' => TagPath::depthUnder($parent),
                'position' => TagStore::position($fresh->type, $fresh->scope_key, $parent, $position, $fresh),
            ])->save();

            $rewrite->execute($fresh, $oldPrefix, $fresh->depth - $oldDepth);

            event(new TagMoved($fresh, $from, $parent));

            return $fresh;
        });
    }
}
