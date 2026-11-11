<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use BackedEnum;
use InvalidArgumentException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The tags a tagging call names, and the type it names them in (null: models and keys carry
 * their own type, strings use the default type). Each entry is a tag model, a primary key (an int
 * or a {@see TagKey}) or a name or slug (string). A string is never a key, unless
 * `tags.string_keys` is on and it fits the key type: resolution then reads it as one.
 */
final readonly class TagSelection
{
    /**
     * @param  list<Tag|TagKey|int|string>  $tags
     */
    public function __construct(
        public array $tags,
        public ?string $type = null,
    ) {}

    /**
     * @param  mixed  $tags  Tag|TagKey|int|string|iterable<Tag|TagKey|int|string>|null
     */
    public static function of(mixed $tags, string|BackedEnum|TagType|null $type = null): self
    {
        $type = $type === null ? null : TagTypeRegistry::nameOf($type);

        if ($tags === null) {
            return new self([], $type);
        }

        if ($tags instanceof Tag || $tags instanceof TagKey || is_int($tags) || is_string($tags)) {
            return new self([$tags], $type);
        }

        if (! is_iterable($tags)) {
            throw new InvalidArgumentException('Tags are a tag model, a key, a name, or a list of them.');
        }

        $list = [];

        foreach ($tags as $tag) {
            if (! $tag instanceof Tag && ! $tag instanceof TagKey && ! is_int($tag) && ! is_string($tag)) {
                throw new InvalidArgumentException('Tags are a tag model, a key, a name, or a list of them.');
            }

            $list[] = $tag;
        }

        return new self($list, $type);
    }

    public function isEmpty(): bool
    {
        return $this->tags === [];
    }
}
