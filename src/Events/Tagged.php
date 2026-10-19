<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * Tags were attached to a model. Fires only when something changed, after commit.
 */
final readonly class Tagged implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<Tag>  $tags  the tags attached
     */
    public function __construct(
        public Model $taggable,
        public array $tags,
    ) {}
}
