<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Types;

use BackedEnum;
use Closure;
use RoundlyConsulting\Tags\Enums\TypeSource;
use RoundlyConsulting\Tags\Exceptions\InvalidTagTypeException;
use RoundlyConsulting\Tags\Exceptions\TagTypeAlreadyDefinedException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * Every tag type of the application, wherever it was declared: a package's `Tags::define()`, the
 * host's `tags.types` config, or the implicit default type. Bound as a container singleton, so
 * resolution caches live on this instance — never in a static.
 *
 * Precedence is deterministic and independent of provider order: the definition (one per name —
 * a second one throws), then every `Tags::extend()` callback in registration order, then the
 * host's `tags.overrides` entry. Resolution is lazy and runs no query, so a consumer may define
 * its types in `boot()` before tags is migrated.
 *
 * @internal reached through `Tags::define()`, `Tags::extend()`, `Tags::types()` and `Tags::type()`
 */
final class TagTypeRegistry
{
    private const NAME = '/^[a-z][a-z0-9_-]{0,30}(?::[a-z][a-z0-9_-]{0,30})?$/D';

    /** @var array<string, TagTypeBuilder> */
    private array $definitions = [];

    /** @var array<string, list<Closure(TagTypeBuilder): void>> */
    private array $extensions = [];

    /** @var array<string, TagType> */
    private array $resolved = [];

    /**
     * Whether a string is a well-formed type name: `genre`, `events:category`.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1;
    }

    /**
     * The type name a string or string-backed enum spells. An int-backed enum is refused.
     */
    public static function nameOf(string|BackedEnum|TagType $type): string
    {
        if ($type instanceof TagType) {
            return $type->name;
        }

        if ($type instanceof BackedEnum) {
            if (! is_string($type->value)) {
                throw InvalidTagTypeException::invalidName();
            }

            return $type->value;
        }

        return $type;
    }

    /**
     * Declare a type. A second definition of the name — in code or in `tags.types` — throws,
     * naming both definers.
     */
    public function define(string|BackedEnum $type): TagTypeBuilder
    {
        $name = self::nameOf($type);

        if (! self::isValidName($name)) {
            throw InvalidTagTypeException::invalidName();
        }

        $definer = self::caller();

        if (isset($this->definitions[$name])) {
            throw TagTypeAlreadyDefinedException::twice($name, $this->definitions[$name]->definer ?? 'unknown', $definer);
        }

        if (array_key_exists($name, TagsConfig::types())) {
            throw TagTypeAlreadyDefinedException::twice($name, 'config/tags.php', $definer);
        }

        unset($this->resolved[$name]);

        return $this->definitions[$name] = new TagTypeBuilder($name, TypeSource::Code, $definer);
    }

    /**
     * Adjust a type from host code. Deferred until resolution, so it applies whichever provider
     * boots first; an extend of an already resolved type re-resolves it on the next use.
     *
     * @param  Closure(TagTypeBuilder): mixed  $callback
     */
    public function extend(string|BackedEnum $type, Closure $callback): void
    {
        $name = self::nameOf($type);

        if (! self::isValidName($name)) {
            throw InvalidTagTypeException::invalidName();
        }

        $this->extensions[$name][] = static function (TagTypeBuilder $builder) use ($callback): void {
            $callback($builder);
        };

        unset($this->resolved[$name]);
    }

    /**
     * The resolved type. Unknown names throw.
     */
    public function resolve(string|BackedEnum|TagType $type): TagType
    {
        if ($type instanceof TagType) {
            return $type;
        }

        $name = self::nameOf($type);

        return $this->resolved[$name] ??= $this->build($name);
    }

    /**
     * The resolved type, or null for a name nobody defines.
     */
    public function find(string|BackedEnum|TagType|null $type): ?TagType
    {
        if ($type === null) {
            return null;
        }

        $name = self::nameOf($type);

        return $this->has($name) ? $this->resolve($name) : null;
    }

    public function has(string|BackedEnum $type): bool
    {
        $name = self::nameOf($type);

        return isset($this->definitions[$name])
            || array_key_exists($name, TagsConfig::types())
            || $name === TagsConfig::defaultType();
    }

    /**
     * The default type, or null when `tags.default_type` is blank.
     */
    public function defaultType(): ?TagType
    {
        $name = TagsConfig::defaultType();

        return $name === null ? null : $this->resolve($name);
    }

    /**
     * Every defined name, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [...array_keys($this->definitions), ...array_keys(TagsConfig::types())];
        $default = TagsConfig::defaultType();

        if ($default !== null) {
            $names[] = $default;
        }

        $names = array_values(array_unique(array_map(strval(...), $names)));
        sort($names);

        return $names;
    }

    /**
     * Every type, resolved, keyed by name.
     *
     * @return array<string, TagType>
     */
    public function all(): array
    {
        $types = [];

        foreach ($this->names() as $name) {
            $types[$name] = $this->resolve($name);
        }

        return $types;
    }

    /**
     * Names an `extend()` or a `tags.overrides` entry adjusts but nothing defines — a typo
     * `tags:doctor` reports.
     *
     * @return list<string>
     */
    public function orphanAdjustments(): array
    {
        $names = [...array_keys($this->extensions), ...array_keys(TagsConfig::overrides())];
        $orphans = [];

        foreach ($names as $name) {
            $name = (string) $name;

            if (! $this->has($name) && ! in_array($name, $orphans, true)) {
                $orphans[] = $name;
            }
        }

        sort($orphans);

        return $orphans;
    }

    private function build(string $name): TagType
    {
        if (! self::isValidName($name) || ! $this->has($name)) {
            throw UnknownTagTypeException::named($name);
        }

        $definition = $this->definitions[$name] ?? null;
        $configured = TagsConfig::types()[$name] ?? null;

        if ($definition !== null && $configured !== null) {
            throw TagTypeAlreadyDefinedException::twice($name, $definition->definer ?? 'unknown', 'config/tags.php');
        }

        if ($definition !== null) {
            $builder = $definition->copy();
        } elseif ($configured !== null) {
            if (! is_array($configured)) {
                throw InvalidTagTypeException::rule($name, 'a tags.types entry must be a map of rules');
            }

            $builder = (new TagTypeBuilder($name, TypeSource::Config, 'config/tags.php'))->applyRules($configured, 'config');
        } else {
            $builder = new TagTypeBuilder($name, TypeSource::Default, null);
        }

        foreach ($this->extensions[$name] ?? [] as $index => $extension) {
            $extension($builder);
            $builder->noteExtension('extend #'.($index + 1));
        }

        $override = TagsConfig::overrides()[$name] ?? null;

        if ($override !== null) {
            if (! is_array($override)) {
                throw InvalidTagTypeException::rule($name, 'a tags.overrides entry must be a map of rules');
            }

            $builder->applyRules($override, 'config overrides');
        }

        $type = $builder->build(TagsConfig::hierarchyMaxDepth());

        $definition?->freeze();

        return $type;
    }

    /**
     * The code that called `define()`: the first frame outside tags and the facade layer, as
     * `ClassBasename:line` — never a namespaced class name.
     */
    private static function caller(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);

        foreach ($frames as $index => $frame) {
            $class = $frame['class'] ?? null;

            if ($index === 0 || ($class !== null && self::isInternalFrame($class))) {
                continue;
            }

            $line = $frames[$index - 1]['line'] ?? 0;
            $where = $class !== null
                ? class_basename($class)
                : basename((string) ($frames[$index - 1]['file'] ?? 'unknown'), '.php');

            return $where.':'.$line;
        }

        return 'unknown';
    }

    private static function isInternalFrame(string $class): bool
    {
        if (str_starts_with($class, 'Illuminate\\Support\\Facades\\')) {
            return true;
        }

        return str_starts_with($class, 'RoundlyConsulting\\Tags\\') && ! str_starts_with($class, 'RoundlyConsulting\\Tags\\Tests\\');
    }
}
