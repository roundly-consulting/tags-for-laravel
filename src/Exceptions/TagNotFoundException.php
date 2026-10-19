<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A tag reference (a key, a slug, a name) that matches no live tag the caller may use.
 */
final class TagNotFoundException extends TagsException
{
    public static function forKey(int|string $key): self
    {
        return new self(Messages::get('messages.tag_not_found_key', ['key' => (string) $key]));
    }

    public static function forName(string $name, string $type): self
    {
        return new self(Messages::get('messages.tag_not_found_name', ['name' => $name, 'type' => $type]));
    }

    public static function trashed(): self
    {
        return new self(Messages::get('messages.tag_trashed'));
    }

    public static function generic(): self
    {
        return new self(Messages::get('messages.tag_not_found'));
    }

    public function errorCode(): string
    {
        return 'tag_not_found';
    }

    public function isNotFound(): bool
    {
        return true;
    }
}
