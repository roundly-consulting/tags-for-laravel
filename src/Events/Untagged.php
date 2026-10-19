<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * Tags were detached from a model. Fires only when something changed, after commit.
 */
final readonly class Untagged implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<Tag>  $tags  the tags detached
     */
    public function __construct(
        public Model $taggable,
        public array $tags,
    ) {}
}
