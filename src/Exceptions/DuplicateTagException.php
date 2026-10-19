<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A name (or manual slug) whose slug another tag of the same type and scope already holds.
 * Slugs are never suffixed, so a taken slug is a duplicate, never "rock-2".
 */
final class DuplicateTagException extends TagsException
{
    public static function forSlug(string $slug, ?string $locale = null): self
    {
        return $locale === null
            ? new self(Messages::get('messages.duplicate', ['slug' => $slug]))
            : new self(Messages::get('messages.duplicate_locale', ['slug' => $slug, 'locale' => $locale]));
    }

    public static function heldByTrashed(string $slug, string $locale): self
    {
        return new self(Messages::get('messages.duplicate_trashed', ['slug' => $slug, 'locale' => $locale]));
    }

    public static function generic(): self
    {
        return new self(Messages::get('messages.duplicate_generic'));
    }

    public function errorCode(): string
    {
        return 'duplicate_tag';
    }

    public function status(): int
    {
        return 409;
    }
}
