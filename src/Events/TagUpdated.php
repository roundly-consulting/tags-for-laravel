<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A tag's name, description, slug, colour or icon changed. Dispatched after commit.
 */
final readonly class TagUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changed  the attributes that changed
     */
    public function __construct(
        public Tag $tag,
        public array $changed,
    ) {}
}
