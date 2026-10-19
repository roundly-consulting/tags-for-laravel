<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * What a tagging write really changed on one model: the tags it attached and the tags it
 * detached — plus, among the attached ones, the tags this write created (`$created`) or brought
 * back from deleted (`$restored`) for free-form names. A write that attached or detached nothing
 * is empty.
 */
final readonly class TagChanges
{
    /**
     * @param  list<Tag>  $attached
     * @param  list<Tag>  $detached
     * @param  list<Tag>  $created  attached tags this write created
     * @param  list<Tag>  $restored  attached tags this write restored
     */
    public function __construct(
        public Model $taggable,
        public array $attached = [],
        public array $detached = [],
        public array $created = [],
        public array $restored = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->attached === [] && $this->detached === [];
    }

    /**
     * The changes to tags of one type.
     */
    public function ofType(string|BackedEnum|TagType $type): self
    {
        $name = TagTypeRegistry::nameOf($type);
        $filter = static fn (array $tags): array => array_values(array_filter($tags, static fn (Tag $tag): bool => $tag->type === $name));

        return new self($this->taggable, $filter($this->attached), $filter($this->detached), $filter($this->created), $filter($this->restored));
    }
}
