<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A tag was created. Dispatched after the transaction commits.
 */
final readonly class TagCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Tag $tag,
    ) {}
}
