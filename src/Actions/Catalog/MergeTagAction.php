<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Tags\Actions\Internal\RewriteSubtreePathsAction;
use RoundlyConsulting\Tags\DataTransferObjects\TagMerge;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Events\TagMerged;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagMergeException;
use RoundlyConsulting\Tags\Exceptions\TagHasChildrenException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Support\TreeLock;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Merge one tag into another of the same type: every tagging of `$from` moves to `$into` —
 * whatever the taggable, whichever scope it belongs to — keeping the pivot row's own columns; a
 * record that carried both keeps its `$into` tagging and loses the `$from` one. `$into`'s usage
 * count is recounted from the pivot, then `$from` is soft-deleted the way a delete deletes it (a
 * restore brings back the tag, never its taggings). Its live children: refused by default,
 * `Reparent` moves them under `$into` (paths and depths rewritten), `Delete` deletes the subtree.
 *
 * Locks in the order every tagging write takes: `$from`'s tree, then the rows of the taggables
 * that carry `$from` (so a write on one of them never decides on a tagging this merge is moving),
 * then the tag rows — both tags and `$from`'s subtree — in one ascending sweep of their keys, then
 * the pivot rows. A concurrent attach of `$from` either commits first and is moved, or waits on
 * `$from`'s row and is refused once it is deleted. Fires only {@see TagMerged}.
 */
final readonly class MergeTagAction
{
    private const int CHUNK = 1000;

    public function __construct(
        private TagTypeRegistry $registry,
        private RewriteSubtreePathsAction $rewrite,
    ) {}

    public function execute(Tag $from, Tag $into, TagChildren $children = TagChildren::Refuse): TagMerge
    {
        if ((string) $from->getKey() === (string) $into->getKey()) {
            throw InvalidTagMergeException::sameTag();
        }

        if ($from->type !== $into->type) {
            throw TagTypeMismatchException::expected($from->type, $into->type);
        }

        $type = $this->registry->resolve($from->type);
        $rewrite = $this->rewrite;

        return TagTransaction::run(static function () use ($from, $into, $children, $type, $rewrite): TagMerge {
            if ($type->hierarchical) {
                TreeLock::lock($from->type, $from->scope_key);
            }

            // The records carrying `$from`, before any tag row: the order a tagging write locks in.
            $locked = Taggings::lockTaggables(self::taggings($from->getKey()));

            // Both tags and `$from`'s subtree (deleted rows too: a reparented child's move rewrites
            // them), in key order, before anything is read with a lock.
            $known = $type->hierarchical ? TagModel::query()->withTrashed()->whereKey($from->getKey())->first() : null;
            TreeLock::rows(
                [$from->getKey(), $into->getKey()],
                ...($known === null ? [] : [TagModel::query()->withTrashed()->descendantsOf($known)]),
            );

            $rows = TagModel::query()->withTrashed()->whereKey([$from->getKey(), $into->getKey()])->lockForUpdate()->get()
                ->keyBy(static fn (Tag $tag): string => (string) $tag->getKey());
            $source = self::live($rows->get((string) $from->getKey()));
            $target = self::live($rows->get((string) $into->getKey()));

            if ($type->hierarchical && TagPath::isWithin($target, $source)) {
                throw InvalidTagMergeException::intoDescendant();
            }

            $kids = $type->hierarchical
                ? TreeLock::read(TagModel::query()->where('parent_id', $source->getKey())->ordered())->get()
                : new Collection;

            self::ensureChildrenCanGo($source, $target, $kids, $children, $type->maxDepth);

            // Every tagging of `$from` now, read with a lock: one committed after the first read (an
            // attach that won `$from`'s row) has its record locked here. None can follow: this
            // merge holds `$from`'s row, which every tagging write locks first.
            $moving = self::taggings($from->getKey(), locked: true);
            Taggings::lockTaggables(array_diff_key($moving, $locked));
            $carried = self::taggings($into->getKey(), locked: true);

            $deduplicated = self::removeDuplicates($source, array_intersect_key($moving, $carried));
            $moved = Tagging::query()->where('tag_id', $source->getKey())->toBase()->update(['tag_id' => $target->getKey()]);

            $usage = count($carried) + $moved;
            TagModel::query()->withTrashed()->whereKey($target->getKey())->toBase()->update(['usage_count' => $usage]);
            $target->usage_count = $usage;
            $target->syncOriginalAttribute('usage_count');

            $reparented = [];
            $deleted = [];

            if ($kids->isNotEmpty() && $children === TagChildren::Reparent) {
                foreach ($kids as $kid) {
                    $oldPrefix = TagPath::subtreePrefix($kid);
                    $oldDepth = $kid->depth;
                    $kid->forceFill([
                        'parent_id' => $target->getKey(),
                        'path' => TagPath::under($target),
                        'depth' => TagPath::depthUnder($target),
                        'position' => TagStore::position($kid->type, $kid->scope_key, $target, null, $kid),
                    ])->save();

                    $rewrite->execute($kid, $oldPrefix, $kid->depth - $oldDepth);
                    $reparented[] = $kid;
                }
            }

            if ($kids->isNotEmpty() && $children === TagChildren::Delete) {
                $deleted = array_values(TreeLock::read(TagModel::query()->descendantsOf($source)->orderBy($source->getKeyName()))->get()->all());
                $keys = array_map(static fn (Tag $tag): int|string => $tag->getKey(), $deleted);

                foreach ($deleted as $descendant) {
                    $descendant->delete();
                    $descendant->usage_count = 0;
                    $descendant->syncOriginalAttribute('usage_count');
                }

                Tagging::query()->whereIn('tag_id', $keys)->delete();
                TagModel::query()->withTrashed()->whereKey($keys)->toBase()->update(['usage_count' => 0]);
            }

            $source->delete();
            TagModel::query()->withTrashed()->whereKey($source->getKey())->toBase()->update(['usage_count' => 0]);
            $source->usage_count = 0;
            $source->syncOriginalAttribute('usage_count');

            event(new TagMerged($source, $target, $moved, $deduplicated, $reparented, $deleted));

            return new TagMerge($target, $moved, $deduplicated, $source);
        });
    }

    /**
     * The records a tag is on, keyed `type|key`: a plain read, or a locking one (the latest
     * committed rows on every engine).
     *
     * @return array<string, array{0: string, 1: int|string}>
     */
    private static function taggings(int|string $tag, bool $locked = false): array
    {
        $query = Tagging::query()->where('tag_id', $tag)->toBase();
        $records = [];

        foreach (($locked ? $query->lockForUpdate() : $query)->get(['taggable_type', 'taggable_id']) as $row) {
            $morph = is_string($row->taggable_type ?? null) ? $row->taggable_type : '';
            $key = $row->taggable_id ?? null;

            if (is_int($key) || is_string($key)) {
                $records[$morph.'|'.$key] = [$morph, $key];
            }
        }

        return $records;
    }

    /**
     * A tag the merge decides on, read under its lock: it must still be live.
     */
    private static function live(?Tag $tag): Tag
    {
        if ($tag === null) {
            throw TagNotFoundException::generic();
        }

        if ($tag->trashed()) {
            throw TagNotFoundException::trashed();
        }

        return $tag;
    }

    /**
     * `$from`'s live children may go: none, or `Delete`, or `Reparent` under a tag of the same
     * scope that keeps every moved node within the type's depth.
     *
     * @param  Collection<int, Tag>  $kids
     */
    private static function ensureChildrenCanGo(Tag $from, Tag $into, Collection $kids, TagChildren $children, ?int $maxDepth): void
    {
        if ($kids->isEmpty() || $children === TagChildren::Delete) {
            return;
        }

        if ($children === TagChildren::Refuse) {
            throw TagHasChildrenException::forMerge((string) $from->slug);
        }

        if ($from->scope_key !== $into->scope_key) {
            throw InvalidTagMergeException::childrenAcrossScopes();
        }

        if ($maxDepth !== null) {
            // The live subtree: a deleted descendant is refused at its restore if it would sit
            // deeper than the cap, as after a move.
            $deepest = TreeLock::max($from->descendants(), 'depth') ?? $from->depth;

            if ($deepest + $into->depth - $from->depth > $maxDepth) {
                throw InvalidTagHierarchyException::tooDeep($maxDepth);
            }
        }
    }

    /**
     * Remove `$from`'s tagging from the records that also carry `$into`.
     *
     * @param  array<string, array{0: string, 1: int|string}>  $records
     * @return int the taggings removed
     */
    private static function removeDuplicates(Tag $from, array $records): int
    {
        $byType = [];

        foreach ($records as [$morph, $key]) {
            $byType[$morph][] = $key;
        }

        $removed = 0;

        foreach ($byType as $morph => $keys) {
            foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                $removed += Tagging::query()
                    ->where('tag_id', $from->getKey())
                    ->where('taggable_type', $morph)
                    ->whereIn('taggable_id', $chunk)
                    ->delete();
            }
        }

        return $removed;
    }
}
