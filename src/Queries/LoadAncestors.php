<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Queries;

use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagPath;

/**
 * Load the ancestors of many tags in one query — a list rendering each row's category path —
 * so `$tag->ancestors()` and `TagSummaryResource` read them without a query. Each tag gets an
 * `ancestors` relation, root first (empty for a root); ancestors shared by several tags are one
 * instance. A tag whose relation is already loaded is left alone, and nulls are skipped (an
 * uncategorised row). Resolved from the container, so a host may override it.
 */
final readonly class LoadAncestors
{
    /**
     * @param  iterable<mixed>  $tags  Tag models or nulls
     */
    public function load(iterable $tags): void
    {
        $pending = [];
        $keys = [];

        foreach ($tags as $tag) {
            if ($tag === null) {
                continue;
            }

            if (! $tag instanceof Tag) {
                throw new InvalidArgumentException('Tags::loadAncestors() takes Tag models or nulls.');
            }

            if ($tag->relationLoaded('ancestors')) {
                continue;
            }

            $pending[] = $tag;

            foreach (TagPath::ancestorKeys($tag->path) as $key) {
                $keys[$key] = $key;
            }
        }

        if ($pending === []) {
            return;
        }

        $found = $keys === []
            ? new Collection
            : TagModel::query()->whereKey(array_values($keys))->get()->keyBy(static fn (Tag $tag): string => (string) $tag->getKey());

        foreach ($pending as $tag) {
            /** @var Collection<int, Tag> $ancestors */
            $ancestors = new Collection;

            foreach (TagPath::ancestorKeys($tag->path) as $key) {
                $ancestor = $found->get($key);

                if ($ancestor instanceof Tag) {
                    $ancestors->push($ancestor);
                }
            }

            $tag->setRelation('ancestors', $ancestors);
        }
    }
}
