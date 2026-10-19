<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A tag moved in its tree (a new parent, or a new position). Dispatched after commit.
 */
final readonly class TagMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Tag $tag,
        public ?Tag $from,
        public ?Tag $to,
    ) {}
}
