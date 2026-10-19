<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

/**
 * Translated package strings, always as a string: a missing line comes back as its key, never
 * an array.
 *
 * @internal
 */
final class Messages
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $line = trans('tags::'.$key, $replace, $locale);

        return is_string($line) ? $line : 'tags::'.$key;
    }

    /**
     * A plural line through `trans_choice()`.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        return trans_choice('tags::'.$key, $count, $replace);
    }
}
