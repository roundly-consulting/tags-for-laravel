<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Handles;

use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * One model's tags: `Tags::for($event)`. Writes go through the manager's flat verbs (so the fake
 * records them, and the model's loaded `tags` relation is dropped, so a chained read sees the
 * change) and return the handle for chaining.
 */
final readonly class TaggableTags
{
    public function __construct(
        private TagsManager $manager,
        private Model&Taggable $taggable,
    ) {}

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function attach(mixed $tags, string|BackedEnum|null $type = null): self
    {
        $this->manager->attach($this->taggable, $tags, $type);

        return $this;
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function detach(mixed $tags, string|BackedEnum|null $type = null): self
    {
        $this->manager->detach($this->taggable, $tags, $type);

        return $this;
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function sync(mixed $tags, string|BackedEnum|null $type = null): self
    {
        $this->manager->sync($this->taggable, $tags, $type);

        return $this;
    }

    public function clear(string|BackedEnum|null $type = null): self
    {
        $this->manager->clear($this->taggable, $type);

        return $this;
    }

    /**
     * Every tag of the model, by position.
     *
     * @return Collection<int, Tag>
     */
    public function all(): Collection
    {
        if ($this->taggable->relationLoaded('tags')) {
            $tags = [];

            foreach ($this->taggable->getRelation('tags') as $tag) {
                if ($tag instanceof Tag) {
                    $tags[] = $tag;
                }
            }

            return new Collection($tags);
        }

        return $this->taggable->tags()->get();
    }

    /**
     * The model's tags of one type: the loaded relation filtered in memory, else one query.
     *
     * @return Collection<int, Tag>
     */
    public function of(string|BackedEnum $type): Collection
    {
        $name = $this->registry()->resolve($type)->name;

        if ($this->taggable->relationLoaded('tags')) {
            return $this->all()->filter(static fn (Tag $tag): bool => $tag->type === $name)->values();
        }

        return $this->taggable->tags()->where(TagModel::make()->qualifyColumn('type'), $name)->get();
    }

    /**
     * The model's one tag of a type (a category), or null.
     */
    public function one(string|BackedEnum $type): ?Tag
    {
        return $this->of($type)->first();
    }

    /**
     * Whether the model carries the tag: a model or a key, or a name or slug (along the chain).
     */
    public function has(Tag|int|string $tag, string|BackedEnum|null $type = null): bool
    {
        $tags = $type === null ? $this->all() : $this->of($type);

        if ($tag instanceof Tag || is_int($tag)) {
            $key = (string) ($tag instanceof Tag ? $tag->getKey() : $tag);

            return $tags->contains(static fn (Tag $carried): bool => (string) $carried->getKey() === $key);
        }

        $name = TagName::normalizeOrNull($tag);

        if ($name === null) {
            return false;
        }

        $chain = TagSlugs::chain();
        $definition = TagSlugs::resolved();

        return $tags->contains(static function (Tag $carried) use ($name, $chain, $definition): bool {
            foreach ($chain as $locale) {
                $slug = $carried->slugFor($locale);

                if ($slug !== null && $slug === TagSlugs::slugify($name, $locale, $definition)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function hasAny(mixed $tags, string|BackedEnum|null $type = null): bool
    {
        foreach (TagSelection::of($tags)->tags as $tag) {
            if ($this->has($tag, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function hasAll(mixed $tags, string|BackedEnum|null $type = null): bool
    {
        foreach (TagSelection::of($tags)->tags as $tag) {
            if (! $this->has($tag, $type)) {
                return false;
            }
        }

        return true;
    }

    public function model(): Model&Taggable
    {
        return $this->taggable;
    }

    private function registry(): TagTypeRegistry
    {
        return $this->manager->registry();
    }
}
