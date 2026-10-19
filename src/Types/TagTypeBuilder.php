<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Types;

use RoundlyConsulting\Tags\Enums\TypeSource;
use RoundlyConsulting\Tags\Exceptions\InvalidTagTypeException;

/**
 * The rules of one tag type, as code (`Tags::define()`, `Tags::extend()`) or config writes them.
 * Every method returns the builder. Nothing is validated until the type is first used: then the
 * definition, every extend callback and the host's config overrides become one immutable
 * {@see TagType}, and this builder is frozen.
 */
final class TagTypeBuilder
{
    /** @var string|array<array-key, mixed>|null */
    private string|array|null $label = null;

    private ?bool $hierarchical = null;

    private ?int $maxDepth = null;

    private ?int $max = null;

    private ?bool $curated = null;

    private ?bool $stableSlugs = null;

    private ?bool $globalOnly = null;

    /** @var list<string> */
    private array $on = [];

    private ?bool $private = null;

    private bool $frozen = false;

    /** @var list<string> */
    private array $applied = [];

    /**
     * @internal built by {@see TagTypeRegistry}
     */
    public function __construct(
        public readonly string $name,
        public readonly TypeSource $source,
        public readonly ?string $definer = null,
    ) {}

    /**
     * The display label: a translation key (recommended for packages: `'events::tags.category'`),
     * a literal, or a locale map for a host's own types. Translated on every read.
     *
     * @param  string|array<string, string>  $label
     */
    public function label(string|array $label): self
    {
        $this->guard();
        $this->label = $label;

        return $this;
    }

    /**
     * A tree. `$maxDepth` counts levels (a root is 1); null means the host's cap
     * (`tags.hierarchy.max_depth`), and a larger value is clamped to it.
     */
    public function hierarchical(?int $maxDepth = null): self
    {
        $this->guard();
        $this->hierarchical = true;
        $this->maxDepth = $maxDepth;

        return $this;
    }

    public function flat(): self
    {
        $this->guard();
        $this->hierarchical = false;
        $this->maxDepth = null;

        return $this;
    }

    /**
     * The most tags of this type one model may carry; null = unlimited.
     */
    public function max(?int $max): self
    {
        $this->guard();
        $this->max = $max;

        return $this;
    }

    /**
     * One tag per model: a category.
     */
    public function single(): self
    {
        return $this->max(1);
    }

    public function unlimited(): self
    {
        return $this->max(null);
    }

    /**
     * Whether tagging by a string may create a missing tag (curated: never). Flat types default
     * to free-form, hierarchical types to curated.
     */
    public function curated(bool $on = true): self
    {
        $this->guard();
        $this->curated = $on;

        return $this;
    }

    public function freeForm(): self
    {
        return $this->curated(false);
    }

    /**
     * Slugs never regenerate on rename, so URLs never move. Curated types only.
     */
    public function stableSlugs(bool $on = true): self
    {
        $this->guard();
        $this->stableSlugs = $on;

        return $this;
    }

    /**
     * Tags of this type are always global: a model's `tagScope()` is ignored.
     */
    public function globalOnly(bool $on = true): self
    {
        $this->guard();
        $this->globalOnly = $on;

        return $this;
    }

    /**
     * Add taggable classes allowed to carry the type (subclasses included). Accumulates across
     * `define()` and `extend()`, so a host can widen a package's type to its own model.
     *
     * @param  class-string  ...$classes
     */
    public function on(string ...$classes): self
    {
        $this->guard();
        $this->on = array_values(array_unique([...$this->on, ...$classes]));

        return $this;
    }

    /**
     * Replace the allowed taggable classes.
     *
     * @param  class-string  ...$classes
     */
    public function onlyOn(string ...$classes): self
    {
        $this->guard();
        $this->on = array_values(array_unique($classes));

        return $this;
    }

    /**
     * Hidden from the JSON API for everyone who may not manage the type.
     */
    public function private(bool $on = true): self
    {
        $this->guard();
        $this->private = $on;

        return $this;
    }

    /**
     * Apply a `tags.types` / `tags.overrides` entry. Unknown keys and bad values throw, naming the
     * type and the rule.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $rules
     */
    public function applyRules(array $rules, string $origin): self
    {
        foreach ($rules as $rule => $value) {
            match ($rule) {
                'label' => $this->label(self::labelValue($this->name, $value)),
                'hierarchical' => self::boolValue($this->name, 'hierarchical', $value) ? $this->hierarchical($this->maxDepth) : $this->flat(),
                'max_depth' => $this->maxDepthRule(self::intValue($this->name, 'max_depth', $value)),
                'max' => $this->max(self::intValue($this->name, 'max', $value)),
                'curated' => $this->curated(self::boolValue($this->name, 'curated', $value)),
                'stable_slugs' => $this->stableSlugs(self::boolValue($this->name, 'stable_slugs', $value)),
                'global_only' => $this->globalOnly(self::boolValue($this->name, 'global_only', $value)),
                'on' => $this->onlyOn(...self::classList($this->name, $value)),
                'private' => $this->private(self::boolValue($this->name, 'private', $value)),
                default => throw InvalidTagTypeException::rule($this->name, 'unknown rule '.(is_string($rule) ? $rule : (string) $rule)),
            };
        }

        $this->applied[] = $origin;

        return $this;
    }

    /**
     * @internal
     */
    public function noteExtension(string $origin): void
    {
        $this->applied[] = $origin;
    }

    /**
     * @internal
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * A thawed copy to apply extends and overrides to, so a re-resolution never applies one twice.
     *
     * @internal
     */
    public function copy(): self
    {
        $copy = clone $this;
        $copy->frozen = false;

        return $copy;
    }

    /**
     * Validate the collected rules and build the immutable type.
     *
     * @internal
     */
    public function build(int $depthCap): TagType
    {
        $hierarchical = $this->hierarchical ?? false;

        if (! $hierarchical && $this->maxDepth !== null) {
            throw InvalidTagTypeException::rule($this->name, 'max depth on a flat type');
        }

        if ($this->maxDepth !== null && $this->maxDepth < 1) {
            throw InvalidTagTypeException::rule($this->name, 'max depth below 1');
        }

        if ($this->max !== null && $this->max < 1) {
            throw InvalidTagTypeException::rule($this->name, 'max below 1');
        }

        $curated = $this->curated ?? $hierarchical;
        $stableSlugs = $this->stableSlugs ?? false;

        if ($stableSlugs && ! $curated) {
            throw InvalidTagTypeException::rule($this->name, 'stable slugs on a free-form type');
        }

        foreach ($this->on as $class) {
            if (! TagType::isTaggableClass($class)) {
                throw InvalidTagTypeException::rule($this->name, 'on: '.class_basename($class).' is not a taggable model');
            }
        }

        return new TagType(
            name: $this->name,
            label: TagType::normaliseLabel($this->name, $this->label),
            hierarchical: $hierarchical,
            maxDepth: $hierarchical ? min($this->maxDepth ?? $depthCap, $depthCap) : null,
            declaredMaxDepth: $this->maxDepth,
            max: $this->max,
            curated: $curated,
            stableSlugs: $stableSlugs,
            globalOnly: $this->globalOnly ?? false,
            on: $this->on,
            private: $this->private ?? false,
            source: $this->source,
            definer: $this->definer,
            applied: $this->applied,
        );
    }

    private function maxDepthRule(?int $maxDepth): self
    {
        $this->guard();
        $this->maxDepth = $maxDepth;

        return $this;
    }

    private function guard(): void
    {
        if ($this->frozen) {
            throw InvalidTagTypeException::frozen($this->name);
        }
    }

    private static function boolValue(string $type, string $rule, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        return $parsed ?? throw InvalidTagTypeException::rule($type, $rule.' must be a boolean');
    }

    private static function intValue(string $type, string $rule, mixed $value): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]{1,9}$/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw InvalidTagTypeException::rule($type, $rule.' must be an integer or null');
    }

    /**
     * @return string|array<string, string>
     */
    private static function labelValue(string $type, mixed $value): string|array
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $map = [];

            foreach ($value as $locale => $text) {
                if (! is_string($locale) || ! is_string($text)) {
                    throw InvalidTagTypeException::rule($type, 'label must be a string or a locale map');
                }

                $map[$locale] = $text;
            }

            return $map;
        }

        throw InvalidTagTypeException::rule($type, 'label must be a string or a locale map');
    }

    /**
     * @return list<class-string>
     */
    private static function classList(string $type, mixed $value): array
    {
        $values = is_string($value) ? [$value] : $value;

        if (! is_array($values)) {
            throw InvalidTagTypeException::rule($type, 'on must be a list of model classes');
        }

        $classes = [];

        foreach ($values as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw InvalidTagTypeException::rule($type, 'on must be a list of model classes');
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
