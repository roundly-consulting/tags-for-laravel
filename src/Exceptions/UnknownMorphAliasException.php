<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * An API morph reference the allow-lists (`tags.scopes`, `tags.taggables`) do not hold: an
 * unknown alias, a class name, a malformed key or a key that names no row.
 */
final class UnknownMorphAliasException extends TagsException
{
    public static function alias(string $kind): self
    {
        return new self(Messages::get('messages.unknown_alias', ['kind' => $kind]));
    }

    public static function missing(string $kind): self
    {
        return new self(Messages::get('messages.unknown_alias_missing', ['kind' => $kind]));
    }

    public function errorCode(): string
    {
        return 'unknown_alias';
    }
}
