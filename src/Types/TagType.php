<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Types;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\Enums\TypeSource;
use RoundlyConsulting\Tags\Exceptions\InvalidTagTypeException;
use RoundlyConsulting\Translatable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * One resolved tag type: the definition, every `Tags::extend()` callback and the host's config
 * overrides, validated and frozen. Its label keeps the raw key or locale map and is translated
 * on every read, so a cached type never pins the locale of the request that resolved it.
 */
final readonly class TagType
{
    /**
     * @param  string|array<string, string>  $label
     * @param  list<string>  $on
     * @param  list<string>  $applied
     */
    public function __construct(
        public string $name,
        public string|array $label,
        public bool $hierarchical,
        public ?int $maxDepth,
        public ?int $declaredMaxDepth,
        public ?int $max,
        public bool $curated,
        public bool $stableSlugs,
        public bool $globalOnly,
        public array $on,
        public bool $private,
        public TypeSource $source,
        public ?string $definer,
        public array $applied = [],
    ) {}

    /**
     * The display label in `$locale` (default: the current one).
     */
    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (is_array($this->label)) {
            return Translatable::resolve($this->label, $locale) ?? self::headline($this->name);
        }

        $line = __($this->label, [], $locale);

        return is_string($line) ? $line : $this->label;
    }

    /**
     * One tag per model.
     */
    public function isSingle(): bool
    {
        return $this->max === 1;
    }

    /**
     * Whether `maxDepth` asked for more levels than the host's cap allows.
     */
    public function isClamped(): bool
    {
        return $this->hierarchical && $this->declaredMaxDepth !== null && $this->maxDepth !== null
            && $this->declaredMaxDepth > $this->maxDepth;
    }

    /**
     * Whether the type's `on(...)` list lets `$taggable` carry it (no list: any taggable).
     */
    public function allowsClass(Model $taggable): bool
    {
        if ($this->on === []) {
            return true;
        }

        foreach ($this->on as $class) {
            if (is_a($taggable, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$taggable` may carry this type, from both sides: the type's `on(...)` list and the
     * model's `tagTypes()`.
     */
    public function allows(Model&Taggable $taggable): bool
    {
        if (! $this->allowsClass($taggable)) {
            return false;
        }

        $types = $taggable->tagTypes();

        if ($types === null) {
            return true;
        }

        foreach ($types as $type) {
            if (TagTypeRegistry::nameOf($type) === $this->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @internal
     */
    public static function isTaggableClass(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Model::class)
            && is_subclass_of($class, Taggable::class);
    }

    /**
     * A label as stored: a string as given, a locale map with malformed locale keys refused and
     * unsupported ones dropped (a package cannot know the host's locales), or the default.
     *
     * @internal
     *
     * @param  string|array<array-key, mixed>|null  $label
     * @return string|array<string, string>
     */
    public static function normaliseLabel(string $name, string|array|null $label): string|array
    {
        if ($label === null) {
            return $name === 'tag' ? 'tags::types.tag' : self::headline($name);
        }

        if (is_string($label)) {
            return $label;
        }

        foreach (array_keys($label) as $locale) {
            try {
                Translatable::ensureLocale((string) $locale);
            } catch (InvalidLocaleException) {
                throw InvalidTagTypeException::rule($name, 'label locale keys must be locales');
            }
        }

        $map = Translatable::fromInput($label);

        return $map === [] ? self::headline($name) : $map;
    }

    private static function headline(string $name): string
    {
        return Str::headline(Str::afterLast($name, ':'));
    }
}
