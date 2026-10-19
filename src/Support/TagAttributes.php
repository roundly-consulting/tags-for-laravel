<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Validation of a tag's optional attributes: colour, icon, description, manual slug, position.
 *
 * @internal
 */
final class TagAttributes
{
    public static function color(?string $color): ?string
    {
        if ($color === null) {
            return null;
        }

        if (preg_match('/^#[0-9a-f]{6}$/Di', $color) !== 1) {
            throw InvalidTagException::invalidColor();
        }

        return strtolower($color);
    }

    /**
     * An identifier (`music`, `fa-guitar`) or an emoji: at most 64 characters, no whitespace,
     * control characters or markup.
     */
    public static function icon(?string $icon): ?string
    {
        if ($icon === null) {
            return null;
        }

        if ($icon === '' || mb_strlen($icon) > 64 || ! mb_check_encoding($icon, 'UTF-8') || preg_match('/[\s\p{Cc}<>"\'`]/u', $icon) === 1) {
            throw InvalidTagException::invalidIcon();
        }

        return $icon;
    }

    /**
     * A plain-text description as a locale map (a string is the current locale): no markup, no
     * control characters other than line breaks and tabs, at most
     * `tags.descriptions.max_length` characters per locale. Blank values are dropped.
     *
     * @param  string|array<array-key, mixed>|null  $description
     * @return array<string, string>
     */
    public static function description(string|array|null $description): array
    {
        if ($description === null) {
            return [];
        }

        $input = is_string($description) ? [Translatable::currentLocale() => $description] : $description;
        $max = TagsConfig::descriptionMaxLength();
        $map = [];

        foreach ($input as $locale => $text) {
            TagName::ensureLocale($locale);

            if (! is_string($text)) {
                throw InvalidTagException::invalidDescription();
            }

            $text = trim(mb_scrub($text, 'UTF-8'));

            if ($text === '') {
                continue;
            }

            if (preg_match('/[^\P{Cc}\n\r\t]/u', $text) === 1 || strip_tags($text) !== $text) {
                throw InvalidTagException::invalidDescription();
            }

            if (mb_strlen($text) > $max) {
                throw InvalidTagException::descriptionTooLong($max);
            }

            $map[(string) $locale] = $text;
        }

        return Translatable::fromInput($map);
    }

    /**
     * Manual slugs as a locale map (a string is the current locale), each already in the shape
     * the slug pipeline produces.
     *
     * @param  string|array<array-key, mixed>|null  $slug
     * @return array<string, string>
     */
    public static function slug(string|array|null $slug): array
    {
        if ($slug === null) {
            return [];
        }

        $input = is_string($slug) ? [Translatable::currentLocale() => $slug] : $slug;
        $map = [];

        foreach ($input as $locale => $value) {
            $locale = TagName::ensureLocale($locale);

            if (! is_string($value) || ! TagSlugs::isNormalised($value, $locale) || mb_strlen($value) > TagsConfig::slugMaxLength()) {
                throw InvalidTagException::invalidSlug();
            }

            $map[$locale] = $value;
        }

        return Translatable::fromInput($map);
    }

    public static function position(?int $position): ?int
    {
        if ($position !== null && $position < 0) {
            throw InvalidTagException::invalidPosition();
        }

        return $position;
    }
}
