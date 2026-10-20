<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;

/**
 * The index and constraint names the migrations derive from the configured table names, checked
 * before any DDL runs: MySQL refuses a name past 64 characters, and PostgreSQL cuts one past 63 —
 * two names that end up equal collide. A table name whose derived names cannot fit is refused,
 * naming its key, instead of failing halfway through the install. Other engines have no limit.
 *
 * @internal
 */
final class SchemaNames
{
    private const int MYSQL_MAX = 64;

    private const int PGSQL_MAX = 63;

    public static function ensureFit(string $driver): void
    {
        $tables = [
            'tags.tables.tags' => TagsConfig::table('tags'),
            'tags.tables.taggables' => TagsConfig::table('taggables'),
            'tags.tables.locks' => TagsConfig::table('locks'),
        ];

        match ($driver) {
            'mysql', 'mariadb' => self::ensureShortEnough($tables),
            'pgsql' => self::ensureDistinct($tables),
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $tables  config key => table name
     */
    private static function ensureShortEnough(array $tables): void
    {
        foreach (self::names($tables) as $key => $names) {
            $longest = 0;

            foreach ($names as $name) {
                $longest = max($longest, strlen($name));
            }

            if ($longest > self::MYSQL_MAX) {
                $suffix = $longest - strlen($tables[$key]);

                throw InvalidTagsConfigurationException::invalid($key, 'a table name of at most '.(self::MYSQL_MAX - $suffix).' characters on mysql, so every index name derived from it fits 64');
            }
        }
    }

    /**
     * Tables and indexes share one namespace on PostgreSQL; constraint names are unique per table.
     *
     * @param  array<string, string>  $tables  config key => table name
     */
    private static function ensureDistinct(array $tables): void
    {
        $relations = [];

        foreach ($tables as $key => $table) {
            $relations[self::truncated($table)] = $key;
        }

        foreach (self::indexes($tables) as $key => $names) {
            foreach ($names as $name) {
                if (isset($relations[self::truncated($name)])) {
                    throw self::collision($key);
                }

                $relations[self::truncated($name)] = $key;
            }
        }

        foreach (self::constraints($tables) as $key => $names) {
            $truncated = array_map(self::truncated(...), $names);

            if (count(array_unique($truncated)) !== count($truncated)) {
                throw self::collision($key);
            }
        }
    }

    private static function truncated(string $name): string
    {
        return substr($name, 0, self::PGSQL_MAX);
    }

    private static function collision(string $key): InvalidTagsConfigurationException
    {
        return InvalidTagsConfigurationException::invalid($key, 'a table name whose index names stay distinct when pgsql cuts them to 63 characters');
    }

    /**
     * Every name each table's migration gives.
     *
     * @param  array<string, string>  $tables
     * @return array<string, list<string>>
     */
    private static function names(array $tables): array
    {
        $names = self::indexes($tables);

        foreach (self::constraints($tables) as $key => $constraints) {
            $names[$key] = [...$names[$key], ...$constraints];
        }

        return $names;
    }

    /**
     * The indexes each table's migration creates, by the names Laravel gives them.
     *
     * @param  array<string, string>  $tables
     * @return array<string, list<string>>
     */
    private static function indexes(array $tables): array
    {
        $tags = $tables['tags.tables.tags'];
        $taggables = $tables['tags.tables.taggables'];

        return [
            'tags.tables.tags' => [
                $tags.'_scope_type_scope_id_index',
                $tags.'_parent_id_index',
                $tags.'_children_index',
                $tags.'_path_index',
                $tags.'_usage_index',
            ],
            'tags.tables.taggables' => [
                $taggables.'_taggable_type_taggable_id_index',
                $taggables.'_unique',
                $taggables.'_type_index',
            ],
            'tags.tables.locks' => [$tables['tags.tables.locks'].'_unique'],
        ];
    }

    /**
     * The constraints each table's migration creates (a unique index is one too).
     *
     * @param  array<string, string>  $tables
     * @return array<string, list<string>>
     */
    private static function constraints(array $tables): array
    {
        $taggables = $tables['tags.tables.taggables'];

        return [
            'tags.tables.tags' => [$tables['tags.tables.tags'].'_parent_id_foreign'],
            'tags.tables.taggables' => [$taggables.'_tag_id_foreign', $taggables.'_unique'],
            'tags.tables.locks' => [$tables['tags.tables.locks'].'_unique'],
        ];
    }
}
