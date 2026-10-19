<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Maintenance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Tags\DataTransferObjects\RepairReport;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Support\TreeLock;

/**
 * Put derived data back in line with the facts: paths and depths from the parent chain (a cycle
 * written past the package is reported, not placed), scope keys from the scope morph, taggings
 * whose taggable row is gone (a mass delete fires no model event) or whose tag is deleted (a
 * plain `$tag->delete()` detaches nothing), then usage counts from the taggings. A dry run counts
 * without writing.
 */
final readonly class RepairTagsAction
{
    private const int CHUNK = 500;

    public function execute(RepairOptions $options): RepairReport
    {
        [$paths, $cycles] = $options->paths ? $this->paths($options) : [0, 0];
        $scopeKeys = $options->scopeKeys ? $this->scopeKeys($options) : 0;
        [$orphans, $unresolvable] = $options->orphans ? $this->orphans($options) : [0, []];
        $trashedTaggings = $options->orphans ? $this->trashedTaggings($options) : 0;
        $counts = $options->counts ? $this->counts($options) : 0;

        return new RepairReport($paths, $cycles, $scopeKeys, $counts, $orphans, $unresolvable, $options->dryRun, $trashedTaggings);
    }

    /**
     * One tree — one (type, scope) — at a time, each inside its tree lock and a transaction, so a
     * tree write never interleaves with the fix (a dry run takes neither).
     *
     * @return array{0: int, 1: int}
     */
    private function paths(RepairOptions $options): array
    {
        $fixed = 0;
        $cycles = 0;

        $trees = $this->tags($options)->reorder()->select(['type', 'scope_key'])->distinct()->orderBy('type')->orderBy('scope_key')->toBase()->get();

        foreach ($trees as $tree) {
            $type = (string) $tree->type;
            $scopeKey = (string) $tree->scope_key;
            $repair = fn (): array => $this->tree($type, $scopeKey, $options->dryRun);

            [$treeFixed, $treeCycles] = $options->dryRun ? $repair() : TagTransaction::run(static function () use ($type, $scopeKey, $repair): array {
                TreeLock::lock($type, $scopeKey);

                return $repair();
            });

            $fixed += $treeFixed;
            $cycles += $treeCycles;
        }

        return [$fixed, $cycles];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function tree(string $type, string $scopeKey, bool $dryRun): array
    {
        $query = TagModel::query()->withTrashed()
            ->where('type', $type)
            ->where('scope_key', $scopeKey)
            ->orderBy(TagModel::make()->getKeyName());
        if (! $dryRun) {
            TreeLock::rows([], $query);
        }

        $tags = ($dryRun ? $query : TreeLock::read($query))->get(['id', 'type', 'scope_key', 'parent_id', 'path', 'depth']);
        $byKey = $tags->keyBy(static fn (Tag $tag): string => (string) $tag->getKey());
        $fixed = 0;
        $cycles = 0;

        foreach ($tags as $tag) {
            $chain = [];
            $current = $tag;
            $cyclic = false;

            while ($current->parent_id !== null) {
                $parentKey = (string) $current->parent_id;

                if (in_array($parentKey, $chain, true) || $parentKey === (string) $tag->getKey()) {
                    $cyclic = true;

                    break;
                }

                $parent = $byKey->get($parentKey);

                if ($parent === null) {
                    break;
                }

                array_unshift($chain, $parentKey);
                $current = $parent;
            }

            if ($cyclic) {
                $cycles++;

                continue;
            }

            $path = $chain === [] ? TagPath::ROOT : '/'.implode('/', $chain).'/';
            $depth = count($chain) + 1;

            if ($tag->path !== $path || $tag->depth !== $depth) {
                $fixed++;

                if (! $dryRun) {
                    TagModel::query()->withTrashed()->whereKey($tag->getKey())->toBase()->update(['path' => $path, 'depth' => $depth]);
                }
            }
        }

        return [$fixed, $cycles];
    }

    private function scopeKeys(RepairOptions $options): int
    {
        $fixed = 0;

        $this->tags($options)->reorder()->select(['id', 'scope_type', 'scope_id', 'scope_key'])->chunkById(self::CHUNK, static function (Collection $tags) use (&$fixed, $options): void {
            foreach ($tags as $tag) {
                $expected = ScopeKey::fromColumns($tag->scope_type, $tag->scope_id);

                if ($tag->scope_key !== $expected) {
                    $fixed++;

                    if (! $options->dryRun) {
                        TagModel::query()->withTrashed()->whereKey($tag->getKey())->toBase()->update(['scope_key' => $expected]);
                    }
                }
            }
        });

        return $fixed;
    }

    /**
     * Taggings whose taggable row is gone: removed, and the usage counts of their tags recounted,
     * so the documented recipe after a mass delete (`--orphans` alone) leaves exact counts.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function orphans(RepairOptions $options): array
    {
        $types = Tagging::query()->distinct()->pluck('taggable_type')->filter(static fn (mixed $type): bool => is_string($type))->values()->all();
        $removed = 0;
        $unresolvable = [];
        $affected = [];

        foreach ($types as $morph) {
            $class = Relation::getMorphedModel($morph) ?? $morph;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                $unresolvable[] = $morph;

                continue;
            }

            $model = new $class;
            $orphans = Tagging::query()
                ->where('taggable_type', $morph)
                ->whereNotIn('taggable_id', $model->newQueryWithoutScopes()->select($model->getQualifiedKeyName()));

            if ($options->type !== null) {
                $orphans->where('type', $options->type);
            }

            if ($options->dryRun) {
                $removed += $orphans->count();

                continue;
            }

            foreach ((clone $orphans)->distinct()->pluck('tag_id') as $key) {
                if (is_int($key) || is_string($key)) {
                    $affected[(string) $key] = $key;
                }
            }

            $removed += $orphans->delete();
        }

        foreach (array_chunk(array_values($affected), self::CHUNK) as $keys) {
            TagTransaction::run(static fn (): int => self::recount($keys, false));
        }

        return [$removed, $unresolvable];
    }

    /**
     * Taggings of deleted tags: removed, and those tags' usage counts zeroed.
     */
    private function trashedTaggings(RepairOptions $options): int
    {
        $tags = TagModel::make();
        $trashed = TagModel::query()->onlyTrashed()->select($tags->getQualifiedKeyName());

        if ($options->type !== null) {
            $trashed->where('type', $options->type);
        }

        $taggings = Tagging::query()->whereIn('tag_id', $trashed->toBase());

        if ($options->dryRun) {
            return $taggings->count();
        }

        $removed = $taggings->delete();

        if ($removed > 0) {
            (clone $trashed)->where('usage_count', '>', 0)->toBase()->update(['usage_count' => 0]);
        }

        return $removed;
    }

    /**
     * Usage counts from the taggings, a chunk of tags at a time. Each chunk locks its tag rows
     * first (key order, the order every tagging write locks them in, after its taggable row — so
     * no cycle), then counts their taggings: a concurrent attach either committed before the lock
     * (counted) or waits and applies its delta after (exact). A dry run locks nothing.
     */
    private function counts(RepairOptions $options): int
    {
        $fixed = 0;

        $this->tags($options)->reorder()->select(['id'])->chunkById(self::CHUNK, static function (Collection $chunk) use (&$fixed, $options): void {
            $keys = array_values($chunk->modelKeys());
            $recount = static fn (): int => self::recount($keys, $options->dryRun);

            $fixed += $options->dryRun ? $recount() : TagTransaction::run($recount);
        });

        return $fixed;
    }

    /**
     * @param  list<int|string>  $keys
     */
    private static function recount(array $keys, bool $dryRun): int
    {
        $query = TagModel::query()->withTrashed()->whereKey($keys)->orderBy(TagModel::make()->getKeyName());
        $tags = ($dryRun ? $query : $query->lockForUpdate())->get(['id', 'usage_count']);

        $actual = Tagging::query()
            ->whereIn('tag_id', $keys)
            ->selectRaw('tag_id, count(*) as aggregate')
            ->groupBy('tag_id')
            ->toBase()
            ->pluck('aggregate', 'tag_id')
            ->all();

        $fixed = 0;

        foreach ($tags as $tag) {
            $raw = $actual[(string) $tag->getKey()] ?? $actual[$tag->getKey()] ?? 0;
            $count = is_numeric($raw) ? (int) $raw : 0;

            if ($tag->usage_count !== $count) {
                $fixed++;

                if (! $dryRun) {
                    TagModel::query()->withTrashed()->whereKey($tag->getKey())->toBase()->update(['usage_count' => $count]);
                }
            }
        }

        return $fixed;
    }

    /**
     * Every tag (trashed included), optionally of one type.
     *
     * @return Builder<Tag>
     */
    private function tags(RepairOptions $options): Builder
    {
        $query = TagModel::query()->withTrashed();

        if ($options->type !== null) {
            $query->where('type', $options->type);
        }

        return $query->orderBy(TagModel::make()->getKeyName());
    }
}
