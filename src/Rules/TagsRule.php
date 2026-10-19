<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Rules;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Support\Messages;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Validates tag input for a type, side-effect free — it never creates a tag:
 *
 * ```php
 * 'category' => ['nullable', TagsRule::for(EventTagType::Category)],
 * 'labels' => ['array', TagsRule::for(EventTagType::Label)->taggable($this->route('event'))],
 * ```
 *
 * Every entry must be a valid name or slug (a key with `byId()`). For a curated type every entry
 * must name a live tag in the candidate scopes; for a free-form type unknown names pass. Keys of
 * another scope or type fail, and the distinct entries may not exceed the max. A list, or a single
 * value for a single type.
 *
 * Validated keys reach a write as strings, and a string is a name to the tagging verbs: pass
 * `Tags::type($type)->findMany($validated)` to the verb, never the raw array.
 */
final class TagsRule implements ValidationRule
{
    private ?Model $within = null;

    private bool $withinGiven = false;

    private (Model&Taggable)|null $taggable = null;

    private bool $byId = false;

    private ?int $max = null;

    private function __construct(
        private readonly string|BackedEnum $type,
    ) {}

    public static function for(string|BackedEnum $type): self
    {
        return new self($type);
    }

    /**
     * Resolve in `$scope` and the global tags (null: global tags only). Overrides the scope a
     * `taggable()` model would give.
     */
    public function within(?Model $scope): self
    {
        $this->within = $scope;
        $this->withinGiven = true;

        return $this;
    }

    /**
     * Validate as tagging this model will: its candidate scopes, the type's `globalOnly` and the
     * allowed types. An unsaved model works — only its class, `tagScope()` and `tagTypes()` are read.
     */
    public function taggable(Model&Taggable $taggable): self
    {
        $this->taggable = $taggable;

        return $this;
    }

    /**
     * Entries are primary keys (numeric strings accepted), not names.
     */
    public function byId(): self
    {
        $this->byId = true;

        return $this;
    }

    /**
     * At most this many tags (never more than the type allows).
     */
    public function max(?int $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = app(TagTypeRegistry::class)->resolve($this->type);

        if (! is_array($value) && ! $type->isSingle()) {
            $fail(Messages::get('validation.list'));

            return;
        }

        $entries = is_array($value) ? array_values($value) : [$value];

        if ($this->taggable !== null && ! $type->allows($this->taggable)) {
            $fail(Messages::get('validation.not_allowed'));

            return;
        }

        $scopeKeys = $this->scopeKeys($type);
        $distinct = $this->byId ? $this->keys($type, $entries, $scopeKeys) : $this->names($type, $entries, $scopeKeys);

        if (is_string($distinct)) {
            $fail(Messages::get('validation.'.$distinct));

            return;
        }

        $max = $type->max === null ? $this->max : min($type->max, $this->max ?? $type->max);

        if ($max !== null && $distinct > $max) {
            $fail(Messages::choice('validation.max', $max, ['max' => $max]));
        }
    }

    /**
     * @return list<string>
     */
    private function scopeKeys(TagType $type): array
    {
        if ($this->withinGiven) {
            return $this->within === null || $type->globalOnly
                ? [ScopeKey::GLOBAL]
                : [ScopeKey::for($this->within), ScopeKey::GLOBAL];
        }

        return $this->taggable === null ? [ScopeKey::GLOBAL] : Taggings::candidateScopeKeys($this->taggable, $type);
    }

    /**
     * The number of distinct tags the keys name, or the failure.
     *
     * @param  list<mixed>  $entries
     * @param  list<string>  $scopeKeys
     */
    private function keys(TagType $type, array $entries, array $scopeKeys): int|string
    {
        $keys = [];

        foreach ($entries as $entry) {
            if ((! is_int($entry) && ! is_string($entry)) || ! KeyShape::isTagKey($entry)) {
                return 'invalid';
            }

            $keys[(string) $entry] = $entry;
        }

        if ($keys === []) {
            return 0;
        }

        $found = TagModel::query()
            ->where('type', $type->name)
            ->whereIn('scope_key', $scopeKeys)
            ->whereKey(array_values($keys))
            ->count();

        return $found === count($keys) ? $found : 'not_found';
    }

    /**
     * The number of distinct tags the names name (unknown free-form names counted by slug), or
     * the failure.
     *
     * @param  list<mixed>  $entries
     * @param  list<string>  $scopeKeys
     */
    private function names(TagType $type, array $entries, array $scopeKeys): int|string
    {
        $names = [];

        foreach ($entries as $entry) {
            $name = is_string($entry) ? TagName::normalizeOrNull($entry) : null;

            if ($name === null || TagSlugs::slugify($name, Translatable::currentLocale()) === '' || mb_strlen($name) > TagsConfig::nameMaxLength()) {
                return 'invalid';
            }

            $names[] = (string) $entry;
        }

        $found = TagLookup::byStrings($type, $scopeKeys, $names);
        $distinct = [];

        foreach ($names as $name) {
            if (isset($found[$name])) {
                $distinct['tag:'.$found[$name]->getKey()] = true;

                continue;
            }

            if ($type->curated) {
                return 'not_found';
            }

            $distinct['new:'.TagSlugs::slugify($name, Translatable::currentLocale())] = true;
        }

        return count($distinct);
    }
}
