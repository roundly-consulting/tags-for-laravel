<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A tag of one type used where another type was asked for (a tagging call, a parent, a handle).
 */
final class TagTypeMismatchException extends TagsException
{
    public static function expected(string $expected, string $actual): self
    {
        return new self(Messages::get('messages.type_mismatch', ['expected' => $expected, 'actual' => $actual]));
    }

    public function errorCode(): string
    {
        return 'tag_type_mismatch';
    }
}
