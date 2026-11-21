<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A delete that would orphan live children. Pass `TagChildren::Reparent` or `TagChildren::Delete`.
 */
final class TagHasChildrenException extends TagsException
{
    public static function forTag(string $slug): self
    {
        return new self(Messages::get('messages.has_children', ['tag' => $slug]));
    }

    /**
     * A merge that would orphan live children. Pass `TagChildren::Reparent` (under the tag it
     * merges into) or `TagChildren::Delete`.
     */
    public static function forMerge(string $slug): self
    {
        return new self(Messages::get('messages.has_children_merge', ['tag' => $slug]));
    }

    public function errorCode(): string
    {
        return 'tag_has_children';
    }

    public function status(): int
    {
        return 409;
    }
}
