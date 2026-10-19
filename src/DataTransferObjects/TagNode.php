<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Models\Tag;

/**
 * One tag of a tree, with its children in position order.
 */
final readonly class TagNode
{
    /**
     * @param  list<TagNode>  $children
     */
    public function __construct(
        public Tag $tag,
        public array $children = [],
    ) {}
}
