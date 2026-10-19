<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use RoundlyConsulting\Tags\Models\Tag;

/**
 * A host's tag subclass, swapped in through tags.models.tag.
 */
final class CustomTag extends Tag
{
    public function shout(): string
    {
        return strtoupper((string) $this->name);
    }
}
