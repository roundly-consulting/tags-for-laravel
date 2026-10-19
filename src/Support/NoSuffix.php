<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Sluggable\Contracts\SuffixGenerator;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;

/**
 * Tags never take a suffixed slug: "rock-2" would be a duplicate tag in disguise. sluggable asks
 * for a suffix only when the slug is taken, so this throws. The create and rename actions probe
 * every locale first (naming the locale); this, and the unique index, are the race backstops.
 *
 * @internal
 */
final class NoSuffix implements SuffixGenerator
{
    public function suffix(string $base, int $attempt, ResolvedSlugDefinition $definition): string
    {
        throw DuplicateTagException::forSlug($base);
    }
}
