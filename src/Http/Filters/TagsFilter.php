<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Filters;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\QueryBuilder\Contracts\Filter;
use RoundlyConsulting\Tags\Support\TagScopes;

/**
 * A query-builder filter on a taggable index, applying the model's tag scopes — so an HTTP
 * filter matches exactly what `withAnyTags()` / `withAllTags()` / `withoutTags()` match:
 *
 * ```php
 * AllowedFilter::custom('category', TagsFilter::any(EventTagType::Category)->withDescendants()),
 * AllowedFilter::custom('labels', TagsFilter::all(EventTagType::Label)),
 * ```
 *
 * Values are slugs (or names); with `byId()`, primary keys. `within()` takes a scope model, or a
 * closure resolved when the filter applies (for filters declared before the user is known).
 *
 * An empty value (`?filter[category]=`, a cleared select) adds no constraint, in every mode. For
 * "models with no tag of the type" use the model scope `withoutTags([], $type)` (or an
 * `AllowedFilter::callback`).
 */
final class TagsFilter implements Filter
{
    private bool $descendants = false;

    private Model|Closure|null $within = null;

    private bool $byId = false;

    private function __construct(
        private readonly string $mode,
        private readonly string|BackedEnum $type,
    ) {}

    public static function any(string|BackedEnum $type): self
    {
        return new self('any', $type);
    }

    public static function all(string|BackedEnum $type): self
    {
        return new self('all', $type);
    }

    public static function none(string|BackedEnum $type): self
    {
        return new self('none', $type);
    }

    public function withDescendants(): self
    {
        $this->descendants = true;

        return $this;
    }

    /**
     * @param  Model|(Closure(): ?Model)|null  $scope
     */
    public function within(Model|Closure|null $scope): self
    {
        $this->within = $scope;

        return $this;
    }

    public function byId(): self
    {
        $this->byId = true;

        return $this;
    }

    public function apply(Builder $query, mixed $value, string $property): void
    {
        $values = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $values[] = $item;
            } elseif (is_int($item)) {
                $values[] = $this->byId ? $item : (string) $item;
            }
        }

        if ($values === []) {
            return;
        }

        $within = $this->within instanceof Closure ? ($this->within)() : $this->within;

        if ($within !== null && ! $within instanceof Model) {
            $within = null;
        }

        match ($this->mode) {
            'all' => TagScopes::all($query, $values, $this->type, $this->descendants, $within, $this->byId),
            'none' => TagScopes::none($query, $values, $this->type, $this->descendants, $within, $this->byId),
            default => TagScopes::any($query, $values, $this->type, $this->descendants, $within, $this->byId),
        };
    }
}
