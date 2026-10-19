<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A write that would leave a model with more tags of a type than the type allows. Nothing is
 * written, single types included: `syncTags($tag, $type)` is the verb that replaces a category.
 */
final class TagLimitExceededException extends TagsException
{
    public static function forType(string $type, int $max): self
    {
        return new self(Messages::choice('messages.limit_exceeded', $max, ['type' => $type, 'max' => $max]));
    }

    public function errorCode(): string
    {
        return 'tag_limit_exceeded';
    }
}
