<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use RoundlyConsulting\Tags\Actions\Internal\RewriteSubtreePathsAction;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Support\TreeLock;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Bring a deleted tag back, without its old taggings: deletion detached them, and whatever a plain
 * `$tag->delete()` left behind is removed first, so a restore never relabels old models. A child
 * of a deleted parent is refused; its path and depth are recomputed from the parent's current
 * place, because the parent may have moved meanwhile. Restoring a parent restores only that tag.
 */
final readonly class RestoreTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
        private RewriteSubtreePathsAction $rewrite,
    ) {}

    public function execute(Tag $tag): Tag
    {
        $type = $this->registry->resolve($tag->type);
        $rewrite = $this->rewrite;

        return TagTransaction::run(static function () use ($tag, $type, $rewrite): Tag {
            if ($type->hierarchical) {
                TreeLock::lock($tag->type, $tag->scope_key);
            }

            $fresh = TagModel::query()->withTrashed()->whereKey($tag->getKey())->lockForUpdate()->first()
                ?? throw TagNotFoundException::generic();

            if (! $fresh->trashed()) {
                return $fresh;
            }

            $oldPrefix = TagPath::subtreePrefix($fresh);
            $oldDepth = $fresh->depth;

            if ($fresh->parent_id !== null) {
                $parent = TagModel::query()->withTrashed()->whereKey($fresh->parent_id)->first();

                if ($parent === null || $parent->trashed()) {
                    throw InvalidTagHierarchyException::parentTrashed();
                }

                $fresh->forceFill(['path' => TagPath::under($parent), 'depth' => TagPath::depthUnder($parent)]);
            }

            Taggings::purge($fresh);
            $fresh->restore();
            $rewrite->execute($fresh, $oldPrefix, $fresh->depth - $oldDepth);

            event(new TagRestored($fresh));

            return $fresh;
        });
    }
}
