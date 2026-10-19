<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use LogicException;
use RoundlyConsulting\Tags\Support\Messages;

/**
 * A write through the read-only `tags()` relation (or `Tag::taggedModels()`). A programming error:
 * the relation's writes would skip the type rules, scopes, usage counts, events and the fake, so
 * every write throws before writing anything and names the verb to use instead.
 */
final class ReadOnlyTagsRelationException extends LogicException
{
    public static function for(string $group): self
    {
        return new self(Messages::get('messages.read_only_relation.'.$group));
    }
}
