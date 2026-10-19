<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Translatable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Tag names: trimmed, one space between words, no control or invisible characters, at most
 * `tags.names.max_length` characters per locale, and sluggable in every locale they are given in.
 *
 * @internal
 */
final class TagName
{
    /**
     * One name, normalised. A blank result throws.
     */
    public static function normalize(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = (string) preg_replace('/\p{Cf}+/u', '', $name);
        $name = (string) preg_replace('/[\p{Cc}\s]+/u', ' ', $name);
        $name = trim($name);

        if ($name === '') {
            throw InvalidTagException::blankName();
        }

        $max = TagsConfig::nameMaxLength();

        if (mb_strlen($name) > $max) {
            throw InvalidTagException::nameTooLong($max);
        }

        return $name;
    }

    /**
     * A name, or null when it would be refused (for lookups, which never throw on a bad string).
     */
    public static function normalizeOrNull(string $name): ?string
    {
        try {
            return self::normalize($name);
        } catch (InvalidTagException) {
            return null;
        }
    }

    /**
     * A name as a locale map: a string is the current locale; a map's malformed locale keys throw
     * and unsupported ones are dropped. Every kept name must have a slug in its locale.
     *
     * @param  string|array<array-key, mixed>  $input
     * @return array<string, string>
     */
    public static function map(string|array $input): array
    {
        if (is_string($input)) {
            $name = self::normalize($input);
            $map = Translatable::fromInput($name);

            if ($map === []) {
                throw InvalidTagException::unsupportedLocale(Translatable::currentLocale());
            }
        } else {
            $normalised = [];

            foreach ($input as $locale => $value) {
                self::ensureLocale($locale);

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $normalised[(string) $locale] = self::normalize($value);
            }

            $map = Translatable::fromInput($normalised);
        }

        if ($map === []) {
            throw InvalidTagException::blankName();
        }

        foreach ($map as $locale => $name) {
            if (TagSlugs::slugify($name, $locale) === '') {
                throw InvalidTagException::unsluggable();
            }
        }

        return $map;
    }

    /**
     * The entry of a name map a lookup matches on: the current locale's, else the first.
     *
     * @param  array<string, string>  $map
     */
    public static function lookup(array $map): string
    {
        return $map[Translatable::currentLocale()] ?? (string) reset($map);
    }

    /**
     * A locale key from outside: malformed keys never reach a JSON path.
     */
    public static function ensureLocale(int|string $locale): string
    {
        try {
            return Translatable::ensureLocale((string) $locale);
        } catch (InvalidLocaleException) {
            throw InvalidTagException::invalidLocale();
        }
    }
}
