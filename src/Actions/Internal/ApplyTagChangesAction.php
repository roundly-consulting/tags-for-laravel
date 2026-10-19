<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Tags\DataTransferObjects\StoredTag;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\Events\Tagged;
use RoundlyConsulting\Tags\Events\Untagged;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\TagModel;

/**
 * Write a tagging change that has been decided: lock every tag row it touches (key order, the
 * order a tag delete locks them in), re-check that the tags to attach are still live and that the
 * tags to detach are still carried, write the pivot rows, adjust the counts by exactly the rows
 * written and announce the change after commit.
 *
 * Inserts are plain inserts: under the taggable's lock no duplicate can race, and MySQL's
 * `INSERT IGNORE` would also turn a foreign-key or truncation error into a silent warning.
 *
 * @internal
 */
final readonly class ApplyTagChangesAction
{
    public function __construct(
        private AdjustUsageCountsAction $counts,
    ) {}

    /**
     * @param  list<int|string>  $attach  tag keys to attach
     * @param  list<int|string>  $detach  tag keys to detach (currently attached)
     * @param  list<StoredTag>  $stored  the tags this write created or restored for free-form names
     */
    public function execute(Model $taggable, array $attach, array $detach, array $stored = []): TagChanges
    {
        $keys = array_values(array_unique(array_map(strval(...), [...$attach, ...$detach])));

        if ($keys === []) {
            return new TagChanges($taggable);
        }

        $instance = TagModel::make();
        $rows = TagModel::query()->withTrashed()
            ->whereKey($keys)
            ->orderBy($instance->getKeyName())
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (Tag $tag): string => (string) $tag->getKey());

        $attached = [];

        foreach ($attach as $key) {
            $tag = $rows->get((string) $key);

            // Deleted by a concurrent writer after this call resolved it: write nothing.
            if ($tag === null || $tag->trashed()) {
                throw TagNotFoundException::trashed();
            }

            $attached[] = $tag;
        }

        $detached = self::stillAttached($taggable, array_values(array_filter(array_map(static fn (int|string $key): ?Tag => $rows->get((string) $key), $detach))));

        if ($attached !== []) {
            $now = Carbon::now();

            Tagging::query()->insert(array_map(static fn (Tag $tag): array => [
                'tag_id' => $tag->getKey(),
                'taggable_type' => $taggable->getMorphClass(),
                'taggable_id' => $taggable->getKey(),
                'type' => $tag->type,
                'created_at' => $now,
            ], $attached));
        }

        if ($detached !== []) {
            Tagging::query()
                ->where('taggable_type', $taggable->getMorphClass())
                ->where('taggable_id', $taggable->getKey())
                ->whereIn('tag_id', array_map(static fn (Tag $tag): int|string => $tag->getKey(), $detached))
                ->delete();
        }

        $this->counts->execute(
            array_map(static fn (Tag $tag): int|string => $tag->getKey(), $attached),
            array_map(static fn (Tag $tag): int|string => $tag->getKey(), $detached),
        );

        foreach ($attached as $tag) {
            $tag->usage_count++;
            $tag->syncOriginalAttribute('usage_count');
        }

        foreach ($detached as $tag) {
            $tag->usage_count = max(0, $tag->usage_count - 1);
            $tag->syncOriginalAttribute('usage_count');
        }

        if ($attached !== []) {
            event(new Tagged($taggable, $attached));
        }

        if ($detached !== []) {
            event(new Untagged($taggable, $detached));
        }

        [$created, $restored] = [self::matching($attached, $stored, created: true), self::matching($attached, $stored, created: false)];

        return new TagChanges($taggable, $attached, $detached, $created, $restored);
    }

    /**
     * The tags among `$tags` the model still carries, read with a locking read now that their rows
     * are locked: the caller decided from a read taken before it waited for them, and a delete (and
     * a restore) may have removed a tagging meanwhile. Only these are detached, counted and
     * announced.
     *
     * @param  list<Tag>  $tags
     * @return list<Tag>
     */
    private static function stillAttached(Model $taggable, array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $carried = Tagging::query()
            ->where('taggable_type', $taggable->getMorphClass())
            ->where('taggable_id', $taggable->getKey())
            ->whereIn('tag_id', array_map(static fn (Tag $tag): int|string => $tag->getKey(), $tags))
            ->lockForUpdate()
            ->pluck('tag_id')
            ->map(static fn (mixed $key): string => is_scalar($key) ? (string) $key : '')
            ->all();

        return array_values(array_filter($tags, static fn (Tag $tag): bool => in_array((string) $tag->getKey(), $carried, true)));
    }

    /**
     * The attached instances this write created (or restored), flagged like the stored ones.
     *
     * @param  list<Tag>  $attached
     * @param  list<StoredTag>  $stored
     * @return list<Tag>
     */
    private static function matching(array $attached, array $stored, bool $created): array
    {
        $keys = [];

        foreach ($stored as $result) {
            if ($created ? $result->created : $result->restored) {
                $keys[(string) $result->tag->getKey()] = true;
            }
        }

        $matching = [];

        foreach ($attached as $tag) {
            if (isset($keys[(string) $tag->getKey()])) {
                $created ? $tag->wasRecentlyCreated = true : $tag->wasRecentlyRestored = true;
                $matching[] = $tag;
            }
        }

        return $matching;
    }
}
