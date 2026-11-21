<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A tag was merged into another: its taggings moved to `$into` and `$from` was deleted. The only
 * event a merge fires — no `TagDeleted` for `$from` (or its subtree), no `TagMoved` for a
 * reparented child, no per-model `Tagged` or `Untagged`. Dispatched after commit.
 */
final readonly class TagMerged implements ShouldDispatchAfterCommit
{
    /**
     * @param  int  $moved  taggings moved from `$from` to `$into`
     * @param  int  $deduplicated  records that carried both: their `$from` tagging was removed
     * @param  list<Tag>  $reparented  `$from`'s children, now under `$into` (`TagChildren::Reparent`)
     * @param  list<Tag>  $deleted  `$from`'s descendants, deleted with it (`TagChildren::Delete`)
     */
    public function __construct(
        public Tag $from,
        public Tag $into,
        public int $moved,
        public int $deduplicated,
        public array $reparented = [],
        public array $deleted = [],
    ) {}
}
