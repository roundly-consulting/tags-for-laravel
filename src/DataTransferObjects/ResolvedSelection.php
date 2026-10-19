<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * A tagging call's input, resolved for one model: per type, the existing tags it names and the
 * free-form names still to create.
 *
 * @internal
 */
final readonly class ResolvedSelection
{
    /**
     * @param  array<string, TagType>  $types  every type the call touches, by name
     * @param  array<string, list<Tag>>  $tags  type name => existing tags, keys unique
     * @param  array<string, array<string, array<string, string>>>  $missing  type name => slug => name map
     */
    public function __construct(
        public array $types,
        public array $tags,
        public array $missing,
    ) {}
}
