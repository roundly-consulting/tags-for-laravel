<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Builders;

use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\Handles\TypeTags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagAttributes;
use RoundlyConsulting\Tags\Support\TagDrafts;

/**
 * A new tag, fluently: `Tags::type('genre')->make('Jazz')->under($music)->color('#f24e29')->create()`.
 * Validates eagerly where it can; `create()` builds a {@see TagData} and calls the handle, so the
 * builder and the DTO run the same code.
 */
final class TagBuilder
{
    /** @var string|array<string, string> */
    private string|array $description = [];

    /** @var string|array<string, string> */
    private string|array $slug = [];

    private ?string $color = null;

    private ?string $icon = null;

    private ?Tag $parent = null;

    private ?int $position = null;

    /**
     * @param  string|array<string, string>  $name
     */
    public function __construct(
        private readonly TypeTags $handle,
        private readonly string|array $name,
    ) {}

    /**
     * @param  string|array<string, string>  $description  a string is the current locale
     */
    public function description(string|array $description): self
    {
        TagAttributes::description($description);
        $this->description = $description;

        return $this;
    }

    /**
     * A manual slug (curated types only); a string is the current locale.
     *
     * @param  string|array<string, string>  $slug
     */
    public function slug(string|array $slug): self
    {
        TagAttributes::slug($slug);
        $this->slug = $slug;

        return $this;
    }

    public function color(?string $color): self
    {
        $this->color = TagAttributes::color($color);

        return $this;
    }

    public function icon(?string $icon): self
    {
        $this->icon = TagAttributes::icon($icon);

        return $this;
    }

    public function under(?Tag $parent): self
    {
        if ($parent !== null) {
            TagDrafts::ensureParent($this->handle->definition(), $this->handle->writeScopeKey(), $parent);
        }

        $this->parent = $parent;

        return $this;
    }

    public function position(?int $position): self
    {
        $this->position = TagAttributes::position($position);

        return $this;
    }

    public function create(): Tag
    {
        return $this->handle->create(new TagData(
            name: $this->name,
            description: $this->description,
            slug: $this->slug,
            color: $this->color,
            icon: $this->icon,
            parent: $this->parent,
            position: $this->position,
        ));
    }
}
