<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Models\Tag;

/**
 * What storing a draft did: created a tag, restored a deleted one, or found the existing one.
 *
 * @internal
 */
final readonly class StoredTag
{
    public function __construct(
        public Tag $tag,
        public bool $created = false,
        public bool $restored = false,
    ) {}
}
