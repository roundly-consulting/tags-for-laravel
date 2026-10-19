<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;
use ReflectionClass;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\Http\Resources\TagScopeResource;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Every read of `config/tags.php`, strict: a value that is not set takes the default, and a bad
 * one throws naming its key.
 *
 * @internal
 */
final class TagsConfig
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D';

    public static function primaryKeyType(): KeyType
    {
        return KeyType::fromConfig('tags.primary_key_type');
    }

    public static function keyType(): KeyType
    {
        return KeyType::fromConfig('tags.key_type');
    }

    /**
     * A table name: `tags`, `taggables` or `locks`.
     */
    public static function table(string $name): string
    {
        [$key, $value] = match ($name) {
            'tags' => ['tags.tables.tags', Config::string('tags.tables.tags', 'tags')],
            'taggables' => ['tags.tables.taggables', Config::string('tags.tables.taggables', 'taggables')],
            'locks' => ['tags.tables.locks', Config::string('tags.tables.locks', 'tag_locks')],
            default => throw new InvalidArgumentException("Unknown tags table [{$name}]."),
        };

        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            throw InvalidTagsConfigurationException::invalid($key, 'a table name of letters, digits and underscores');
        }

        return $value;
    }

    /**
     * The type a plain string names when a call gives none, or null when there is none.
     */
    public static function defaultType(): ?string
    {
        $value = config('tags.default_type', 'tag');

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value) || ! TagTypeRegistry::isValidName($value)) {
            throw InvalidTagsConfigurationException::invalid('tags.default_type', 'a tag type name, or blank for none');
        }

        return $value;
    }

    /**
     * The host's own types: name => rules.
     *
     * @return array<string, mixed>
     */
    public static function types(): array
    {
        return self::map('tags.types', config('tags.types'));
    }

    /**
     * Rules applied over any type, last: name => rules.
     *
     * @return array<string, mixed>
     */
    public static function overrides(): array
    {
        return self::map('tags.overrides', config('tags.overrides'));
    }

    public static function nameMaxLength(): int
    {
        return Config::integer('tags.names.max_length', 64, min: 1, max: 255);
    }

    public static function descriptionMaxLength(): int
    {
        return Config::integer('tags.descriptions.max_length', 500, min: 1, max: 10000);
    }

    public static function slugMaxLength(): int
    {
        return Config::integer('tags.slugs.max_length', 80, min: 1, max: 255);
    }

    public static function slugSeparator(): string
    {
        $separator = Config::string('tags.slugs.separator', '-');

        if (preg_match('/^[-._~]{1,3}$/D', $separator) !== 1) {
            throw InvalidTagsConfigurationException::invalid('tags.slugs.separator', 'one to three of - . _ ~');
        }

        return $separator;
    }

    public static function unicodeSlugs(): bool
    {
        return Config::boolean('tags.slugs.unicode', false);
    }

    /**
     * @return array<string, string>
     */
    public static function slugDictionary(): array
    {
        $value = config('tags.slugs.dictionary', []);

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidTagsConfigurationException::invalid('tags.slugs.dictionary', 'a map of character => word');
        }

        $dictionary = [];

        foreach ($value as $from => $to) {
            if (! is_string($from) || $from === '' || ! is_string($to)) {
                throw InvalidTagsConfigurationException::invalid('tags.slugs.dictionary', 'a map of character => word');
            }

            $dictionary[$from] = $to;
        }

        return $dictionary;
    }

    public static function hierarchyMaxDepth(): int
    {
        return Config::integer('tags.hierarchy.max_depth', 5, min: 1, max: 10);
    }

    public static function suggestLimit(): int
    {
        return Config::integer('tags.suggest.limit', 10, min: 1, max: self::suggestMaxLimit());
    }

    public static function suggestMaxLimit(): int
    {
        return Config::integer('tags.suggest.max_limit', 50, min: 1, max: 1000);
    }

    public static function popularLimit(): int
    {
        return Config::integer('tags.popular.limit', 10, min: 1, max: self::popularMaxLimit());
    }

    public static function popularMaxLimit(): int
    {
        return Config::integer('tags.popular.max_limit', 100, min: 1, max: 1000);
    }

    /**
     * API alias => scope model class.
     *
     * @return array<string, mixed>
     */
    public static function scopes(): array
    {
        return self::map('tags.scopes', config('tags.scopes'));
    }

    /**
     * API alias => taggable model class.
     *
     * @return array<string, mixed>
     */
    public static function taggables(): array
    {
        return self::map('tags.taggables', config('tags.taggables'));
    }

    /**
     * How a tag's scope owner renders: blank means the packaged resource; anything that is not
     * an instantiable JsonResource subclass throws naming the key.
     *
     * @return class-string<JsonResource>
     */
    public static function scopeResource(): string
    {
        $resource = config('tags.resources.scope');

        if ($resource === null || (is_string($resource) && trim($resource) === '')) {
            return TagScopeResource::class;
        }

        if (! is_string($resource)
            || ! is_subclass_of($resource, JsonResource::class)
            || ! (new ReflectionClass($resource))->isInstantiable()) {
            throw InvalidConfigurationException::notAnImplementation('tags.resources.scope', JsonResource::class, $resource);
        }

        return $resource;
    }

    /**
     * The gate ability the API checks before a read, or null for none.
     */
    public static function viewAbility(): ?string
    {
        return self::ability('tags.authorization.abilities.view', config('tags.authorization.abilities.view'));
    }

    /**
     * The gate ability the API checks before a write. Blank means the shipped `tags.manage`.
     */
    public static function manageAbility(): string
    {
        return Config::string('tags.authorization.abilities.manage', 'tags.manage');
    }

    /**
     * The gate ability the API checks on a `?scope=` model. Blank means the shipped `tags.scope`.
     */
    public static function scopeAbility(): string
    {
        return Config::string('tags.authorization.abilities.scope', 'tags.scope');
    }

    public static function routesPrefix(): string
    {
        $prefix = config('tags.routes.prefix', 'api');

        if ($prefix === null) {
            return '';
        }

        if (! is_string($prefix)) {
            throw InvalidTagsConfigurationException::invalid('tags.routes.prefix', 'a URL prefix');
        }

        return trim($prefix, '/');
    }

    /**
     * @return list<string>
     */
    public static function routesMiddleware(): array
    {
        $middleware = config('tags.routes.middleware', ['api', 'auth', 'throttle:tags-api']);

        if ($middleware === null) {
            return [];
        }

        if (! is_array($middleware)) {
            throw InvalidTagsConfigurationException::invalid('tags.routes.middleware', 'a list of middleware');
        }

        $list = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw InvalidTagsConfigurationException::invalid('tags.routes.middleware', 'a list of middleware');
            }

            $list[] = $entry;
        }

        return $list;
    }

    /**
     * The opt-in JSON API: off unless the switch says otherwise. A switch that is not set (absent,
     * `null`, blank) is off.
     */
    public static function routesEnabled(): bool
    {
        return Config::boolean('tags.routes.enabled', false);
    }

    public static function rateLimit(): int
    {
        return Config::integer('tags.routes.rate_limit', 120, min: 1, max: 100000);
    }

    public static function suggestRateLimit(): int
    {
        return Config::integer('tags.routes.suggest_rate_limit', 120, min: 1, max: 100000);
    }

    public static function maxPerPage(): int
    {
        return Config::integer('tags.routes.max_per_page', 100, min: 1, max: 1000);
    }

    public static function maxTreeNodes(): int
    {
        return Config::integer('tags.routes.max_tree_nodes', 1000, min: 1, max: 100000);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(string $key, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidTagsConfigurationException::invalid($key, 'a map');
        }

        $map = [];

        foreach ($value as $name => $entry) {
            if (! is_string($name)) {
                throw InvalidTagsConfigurationException::invalid($key, 'a map keyed by name');
            }

            $map[$name] = $entry;
        }

        return $map;
    }

    private static function ability(string $key, mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidTagsConfigurationException::invalid($key, 'a gate ability name, or null');
        }

        return $value;
    }
}
