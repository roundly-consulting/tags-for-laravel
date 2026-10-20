<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use RoundlyConsulting\Tags\Actions\Internal\RewriteSubtreePathsAction;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Events\TagDeleted;
use RoundlyConsulting\Tags\Exceptions\TagHasChildrenException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Support\TreeLock;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Soft-delete a tag and detach it everywhere, so a re-created tag of the same name never relabels
 * old records. With live children it refuses by default; `Reparent` moves them to its parent,
 * `Delete` takes the whole subtree. Locks the tree, then the deleted rows in key order — the order
 * a tagging write locks them in — before touching the pivot. No per-model `Untagged` fires.
 */
final readonly class DeleteTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
        private RewriteSubtreePathsAction $rewrite,
    ) {}

    public function execute(Tag $tag, TagChildren $children = TagChildren::Refuse): Tag
    {
        $type = $this->registry->resolve($tag->type);
        $rewrite = $this->rewrite;

        return TagTransaction::run(static function () use ($tag, $type, $children, $rewrite): Tag {
            if ($type->hierarchical) {
                TreeLock::lock($tag->type, $tag->scope_key);

                // The tag, its parent and its whole subtree, in key order, before any locking read.
                $known = TagModel::query()->withTrashed()->whereKey($tag->getKey())->first();
                TreeLock::rows(
                    [$tag->getKey(), $known?->parent_id],
                    ...($known === null ? [] : [TagModel::query()->withTrashed()->descendantsOf($known)]),
                );
            }

            // A flat type takes no tree lock: its one tag row is locked here, so a second delete
            // waits for the first and then sees the row deleted (as a restore does).
            $query = TagModel::query()->withTrashed()->whereKey($tag->getKey());
            $fresh = ($type->hierarchical ? TreeLock::read($query) : $query->lockForUpdate())->first()
                ?? throw TagNotFoundException::generic();

            if ($fresh->trashed()) {
                return $fresh;
            }

            $kids = TreeLock::read(TagModel::query()->where('parent_id', $fresh->getKey())->ordered())->get();
            $subtree = [];

            if ($kids->isNotEmpty() && $children === TagChildren::Refuse) {
                throw TagHasChildrenException::forTag((string) $fresh->slug);
            }

            if ($children === TagChildren::Delete) {
                $subtree = TreeLock::read(TagModel::query()->descendantsOf($fresh))->get()->all();
            }

            $ids = [$fresh->getKey(), ...array_map(static fn (Tag $descendant): int|string => $descendant->getKey(), $subtree)];
            $locked = TagModel::query()->whereKey([...$ids, ...$kids->modelKeys()])->orderBy($fresh->getKeyName())->lockForUpdate()->get();

            if ($children === TagChildren::Reparent) {
                $parent = $fresh->parent_id === null ? null : TreeLock::read(TagModel::query()->whereKey($fresh->parent_id))->first();

                foreach ($kids as $kid) {
                    $oldPrefix = TagPath::subtreePrefix($kid);
                    $kid->forceFill([
                        'parent_id' => $parent?->getKey(),
                        'path' => TagPath::under($parent),
                        'depth' => TagPath::depthUnder($parent),
                        'position' => TagStore::position($kid->type, $kid->scope_key, $parent, null, $kid),
                    ])->save();

                    $rewrite->execute($kid, $oldPrefix, -1);
                }
            }

            $deletedKeys = array_map(strval(...), $ids);
            $deleted = $locked->filter(static fn (Tag $row): bool => in_array((string) $row->getKey(), $deletedKeys, true));

            foreach ($deleted as $row) {
                $row->delete();
            }

            $detached = Tagging::query()->whereIn('tag_id', $ids)->delete();
            TagModel::query()->withTrashed()->whereKey($ids)->toBase()->update(['usage_count' => 0]);

            $result = $deleted->first(static fn (Tag $row): bool => $row->getKey() === $fresh->getKey()) ?? $fresh;
            $result->usage_count = 0;
            $result->syncOriginalAttribute('usage_count');

            event(new TagDeleted($result, $detached, array_values($deleted->reject(static fn (Tag $row): bool => $row->is($result))->all())));

            return $result;
        });
    }
}
