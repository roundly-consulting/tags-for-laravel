<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Support\TreeLock;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Bring a deleted tag back, without its old taggings: deletion detached them, and whatever a plain
 * `$tag->delete()` left behind is removed first, so a restore never relabels old models. A child
 * of a deleted parent is refused; its path and depth are recomputed from the parent's current
 * place, because the parent may have moved meanwhile, and must fit the type's depth cap. Restoring
 * a parent restores only that tag.
 */
final readonly class RestoreTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
    ) {}

    public function execute(Tag $tag): Tag
    {
        $type = $this->registry->resolve($tag->type);

        return TagTransaction::run(static function () use ($tag, $type): Tag {
            if ($type->hierarchical) {
                TreeLock::lock($tag->type, $tag->scope_key);

                // The tag, its parent and its subtree, in key order, before any locking read.
                $known = TagModel::query()->withTrashed()->whereKey($tag->getKey())->first();
                TreeLock::rows(
                    [$tag->getKey(), $known?->parent_id],
                    ...($known === null ? [] : [TagModel::query()->withTrashed()->descendantsOf($known)]),
                );
            }

            $fresh = TagModel::query()->withTrashed()->whereKey($tag->getKey())->lockForUpdate()->first()
                ?? throw TagNotFoundException::generic();

            if (! $fresh->trashed()) {
                return $fresh;
            }

            TagStore::restore($fresh, $type);

            event(new TagRestored($fresh));

            return $fresh;
        });
    }
}
