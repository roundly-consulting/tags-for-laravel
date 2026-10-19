<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\NotGiven;

/**
 * A new tag. Names, descriptions and manual slugs are locale maps; a string is the current
 * locale. A manual slug is for curated types only. `parent` left as {@see NotGiven} makes a new
 * tag a root, and leaves a deleted tag this brings back where it was; `null` asks for a root.
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
        public NotGiven|Tag|null $parent = new NotGiven,
        public ?int $position = null,
    ) {}
}
