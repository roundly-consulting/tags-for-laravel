<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A second definition of one type name. A host adjusts another package's type through
 * `Tags::extend()` or `tags.overrides`, never by defining it again.
 */
final class TagTypeAlreadyDefinedException extends TagsException
{
    public static function twice(string $type, string $first, string $second): self
    {
        return new self(Messages::get('messages.type_already_defined', ['type' => $type, 'first' => $first, 'second' => $second]));
    }

    public function errorCode(): string
    {
        return 'type_already_defined';
    }

    public function status(): int
    {
        return 500;
    }
}
