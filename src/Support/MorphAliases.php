<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\Exceptions\UnknownMorphAliasException;

/**
 * The allow-lists that turn untrusted API input into a model class: `tags.scopes` for
 * `?scope=alias:key` and `tags.taggables` for `popular?on=alias`. A class name is never accepted,
 * and a key that cannot fit `tags.key_type` never reaches the database (PostgreSQL would answer it
 * with an error, not an empty result).
 *
 * @internal
 */
final class MorphAliases
{
    private const ALIAS = '/^[a-z][a-z0-9_]{0,63}$/D';

    /**
     * The scope model a `alias:key` reference names.
     */
    public static function scope(string $reference): Model
    {
        [$alias, $key] = str_contains($reference, ':') ? explode(':', $reference, 2) : [$reference, ''];
        $class = self::map('tags.scopes', TagsConfig::scopes())[$alias] ?? throw UnknownMorphAliasException::alias('scope');

        if (! KeyShape::fits($key, TagsConfig::keyType())) {
            throw UnknownMorphAliasException::missing('scope');
        }

        return $class::query()->find($key) ?? throw UnknownMorphAliasException::missing('scope');
    }

    /**
     * The taggable class an alias names.
     *
     * @return class-string<Model>
     */
    public static function taggable(string $alias): string
    {
        return self::map('tags.taggables', TagsConfig::taggables())[$alias] ?? throw UnknownMorphAliasException::alias('taggable');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, class-string<Model>>
     */
    private static function map(string $key, array $raw): array
    {
        $map = [];

        foreach ($raw as $alias => $class) {
            if (preg_match(self::ALIAS, $alias) !== 1 || ! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw InvalidTagsConfigurationException::invalid($key, 'a map of snake_case alias => model class');
            }

            $map[$alias] = $class;
        }

        return $map;
    }
}
