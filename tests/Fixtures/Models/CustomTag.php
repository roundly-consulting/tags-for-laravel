<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host's tag subclass, swapped in through tags.models.tag.
 */
final class CustomTag extends Tag
{
    use CountsCreations;

    public function shout(): string
    {
        return strtoupper((string) $this->name);
    }
}
