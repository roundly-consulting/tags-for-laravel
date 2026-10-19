<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A tree write the hierarchy refuses: a cycle, a tree deeper than the type allows, a parent on a
 * flat type, or a trashed parent.
 */
final class InvalidTagHierarchyException extends TagsException
{
    public static function cycle(): self
    {
        return new self(Messages::get('messages.hierarchy.cycle'));
    }

    public static function tooDeep(int $max): self
    {
        return new self(Messages::choice('messages.hierarchy.too_deep', $max, ['max' => $max]));
    }

    public static function flatType(string $type): self
    {
        return new self(Messages::get('messages.hierarchy.flat_type', ['type' => $type]));
    }

    public static function parentTrashed(): self
    {
        return new self(Messages::get('messages.hierarchy.parent_trashed'));
    }

    public function errorCode(): string
    {
        return 'invalid_hierarchy';
    }
}
