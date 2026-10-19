<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * The siblings under a parent (the roots, with no parent) were reordered. Dispatched after commit.
 */
final readonly class TagsReordered implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<Tag>  $tags  the siblings, in their new order
     */
    public function __construct(
        public string $type,
        public ?Tag $parent,
        public array $tags,
    ) {}
}
