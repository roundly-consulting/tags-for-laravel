<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A tag attribute the package refuses: a blank, too long or unsluggable name, a name in an
 * unsupported locale, a bad colour, icon, description, slug or position, or a write to an
 * immutable attribute.
 */
final class InvalidTagException extends TagsException
{
    public static function blankName(): self
    {
        return new self(Messages::get('messages.invalid.blank_name'));
    }

    public static function nameTooLong(int $max): self
    {
        return new self(Messages::get('messages.invalid.name_too_long', ['max' => $max]));
    }

    public static function unsluggable(): self
    {
        return new self(Messages::get('messages.invalid.unsluggable'));
    }

    public static function unsupportedLocale(string $locale): self
    {
        return new self(Messages::get('messages.invalid.unsupported_locale', ['locale' => $locale]));
    }

    public static function invalidLocale(): self
    {
        return new self(Messages::get('messages.invalid.invalid_locale'));
    }

    public static function invalidColor(): self
    {
        return new self(Messages::get('messages.invalid.color'));
    }

    public static function invalidIcon(): self
    {
        return new self(Messages::get('messages.invalid.icon'));
    }

    public static function descriptionTooLong(int $max): self
    {
        return new self(Messages::get('messages.invalid.description_too_long', ['max' => $max]));
    }

    public static function invalidDescription(): self
    {
        return new self(Messages::get('messages.invalid.description'));
    }

    public static function invalidSlug(): self
    {
        return new self(Messages::get('messages.invalid.slug'));
    }

    public static function manualSlugOnFreeForm(string $type): self
    {
        return new self(Messages::get('messages.invalid.manual_slug_free_form', ['type' => $type]));
    }

    public static function invalidPosition(): self
    {
        return new self(Messages::get('messages.invalid.position'));
    }

    public static function globalOnlyType(string $type): self
    {
        return new self(Messages::get('messages.invalid.global_only', ['type' => $type]));
    }

    public static function immutable(string $attribute): self
    {
        return new self(Messages::get('messages.invalid.immutable', ['attribute' => $attribute]));
    }

    public function errorCode(): string
    {
        return 'invalid_tag';
    }
}
