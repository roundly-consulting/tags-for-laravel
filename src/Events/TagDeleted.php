<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A tag (and, with `TagChildren::Delete`, its subtree) was deleted and detached everywhere. No
 * per-model `Untagged` fires for it. Dispatched after commit.
 */
final readonly class TagDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param  int  $detached  how many taggings were removed
     * @param  list<Tag>  $subtree  every tag deleted with it (empty unless its children went too)
     */
    public function __construct(
        public Tag $tag,
        public int $detached,
        public array $subtree,
    ) {}
}
