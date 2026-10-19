<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Models\Tag;

/**
 * A new tag. Names, descriptions and manual slugs are locale maps; a string is the current
 * locale. A manual slug is for curated types only.
 */
final readonly class TagData
{
    /**
     * @param  string|array<string, string>  $name
     * @param  string|array<string, string>  $description
     * @param  string|array<string, string>  $slug
     */
    public function __construct(
        public string|array $name,
        public string|array $description = [],
        public string|array $slug = [],
        public ?string $color = null,
        public ?string $icon = null,
        public ?Tag $parent = null,
        public ?int $position = null,
    ) {}
}
