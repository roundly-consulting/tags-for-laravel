<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Models\Tag;

/**
 * What a merge did: `$from`'s taggings moved to `$into`, `$from` deleted.
 */
final readonly class TagMerge
{
    /**
     * @param  Tag  $into  the tag that remains, re-read, its usage count recounted
     * @param  int  $moved  taggings moved from `$from` to `$into`
     * @param  int  $deduplicated  records that carried both: their `$from` tagging was removed
     * @param  Tag  $from  the merged tag, now deleted
     */
    public function __construct(
        public Tag $into,
        public int $moved,
        public int $deduplicated,
        public Tag $from,
    ) {}
}
