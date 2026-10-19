<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Builders;

use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Handles\TypeTags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\NotGiven;
use RoundlyConsulting\Tags\Support\TagAttributes;

/**
 * Changes to a tag, fluently: `Tags::type('genre')->edit($jazz)->name('Jazz music')->color(null)->save()`.
 * Only what you call changes; `null` clears a description, colour or icon. `save()` builds an
 * {@see UpdateTagData} and calls the handle.
 */
final class TagEditor
{
    /** @var NotGiven|string|array<string, string> */
    private NotGiven|string|array $name;

    /** @var NotGiven|string|array<string, string>|null */
    private NotGiven|string|array|null $description;

    /** @var NotGiven|string|array<string, string> */
    private NotGiven|string|array $slug;

    private NotGiven|string|null $color;

    private NotGiven|string|null $icon;

    public function __construct(
        private readonly TypeTags $handle,
        private readonly Tag $tag,
    ) {
        $this->name = new NotGiven;
        $this->description = new NotGiven;
        $this->slug = new NotGiven;
        $this->color = new NotGiven;
        $this->icon = new NotGiven;
    }

    /**
     * @param  string|array<string, string>  $name  a string is the current locale
     */
    public function name(string|array $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @param  string|array<string, string>|null  $description
     */
    public function description(string|array|null $description): self
    {
        TagAttributes::description($description);
        $this->description = $description;

        return $this;
    }

    /**
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

    public function save(): Tag
    {
        return $this->handle->update($this->tag, new UpdateTagData(
            name: $this->name,
            description: $this->description,
            slug: $this->slug,
            color: $this->color,
            icon: $this->icon,
        ));
    }
}
