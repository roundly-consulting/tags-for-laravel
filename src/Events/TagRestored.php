<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A deleted tag came back: restored, or re-created under a name it held. It carries no
 * taggings. Dispatched after commit.
 */
final readonly class TagRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Tag $tag,
    ) {}
}
