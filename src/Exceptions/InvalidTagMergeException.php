<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A merge the catalog refuses: a tag into itself or into one of its own descendants, or children
 * that would have to move into another scope.
 */
final class InvalidTagMergeException extends TagsException
{
    public static function sameTag(): self
    {
        return new self(Messages::get('messages.merge.same_tag'));
    }

    public static function intoDescendant(): self
    {
        return new self(Messages::get('messages.merge.into_descendant'));
    }

    public static function childrenAcrossScopes(): self
    {
        return new self(Messages::get('messages.merge.children_across_scopes'));
    }

    public function errorCode(): string
    {
        return 'invalid_merge';
    }
}
