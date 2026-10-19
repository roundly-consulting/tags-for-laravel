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
    /**
     * The largest position the `position` column holds on every supported engine.
     */
    public const int MAX_POSITION = 2147483647;

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
     * `tags.descriptions.max_length` characters per locale. Blank and null values are dropped —
     * on an update they clear their locale ({@see self::clearedLocales()}).
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

            if ($text === null) {
                continue;
            }

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
     * The locales a description update clears: those given blank or null (a blank string is the
     * current locale).
     *
     * @param  string|array<array-key, mixed>|null  $description
     * @return list<string>
     */
    public static function clearedLocales(string|array|null $description): array
    {
        if ($description === null) {
            return [];
        }

        $input = is_string($description) ? [Translatable::currentLocale() => $description] : $description;
        $cleared = [];

        foreach ($input as $locale => $text) {
            if ($text === null || (is_string($text) && trim(mb_scrub($text, 'UTF-8')) === '')) {
                $cleared[] = TagName::ensureLocale($locale);
            }
        }

        return $cleared;
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
        if ($position !== null && ($position < 0 || $position > self::MAX_POSITION)) {
            throw InvalidTagException::invalidPosition();
        }

        return $position;
    }
}
