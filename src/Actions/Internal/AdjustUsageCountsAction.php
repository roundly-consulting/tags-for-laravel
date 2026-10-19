<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Internal;

use RoundlyConsulting\Tags\Support\TagModel;

/**
 * Change usage counts by exactly the taggings inserted or deleted, in the write's transaction,
 * on tag rows the caller already locked. A count never drops below zero.
 *
 * @internal
 */
final readonly class AdjustUsageCountsAction
{
    /**
     * @param  list<int|string>  $increment  one tagging added per key
     * @param  list<int|string>  $decrement  one tagging removed per key
     */
    public function execute(array $increment, array $decrement): void
    {
        if ($increment !== []) {
            TagModel::query()->withTrashed()->whereKey($increment)->toBase()->increment('usage_count');
        }

        if ($decrement !== []) {
            TagModel::query()->withTrashed()->whereKey($decrement)->where('usage_count', '>', 0)->toBase()->decrement('usage_count');
        }
    }
}
