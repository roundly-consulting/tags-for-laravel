<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\AllowedSort;
use RoundlyConsulting\QueryBuilder\Contracts\Sort;
use RoundlyConsulting\QueryBuilder\Enums\SortDirection;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Translatable\DataTransferObjects\TranslationSearch;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * The tag list's allow-list: filters `search` (name contains, escaped), `parent` (a key or
 * `root`), `depth`, `ids`, `slug` and — for managers, over the scopes they manage — `trashed`; sorts `position` (default),
 * `name` (current locale), `usage_count` and `created_at`.
 *
 * @internal
 */
final class TagIndexQuery implements Sort
{
    /**
     * The largest depth the `depth` column holds (an unsigned tiny integer).
     */
    private const int MAX_DEPTH = 255;

    /**
     * The allow-listed list of one type in the readable scopes. `filter[trashed]` is allowed when
     * the caller manages one of them, and lists deleted tags of those scopes only.
     *
     * @param  list<string>  $scopeKeys
     * @param  list<string>  $managedScopeKeys
     */
    public static function for(string $type, array $scopeKeys, Request $request, array $managedScopeKeys = []): QueryBuilder
    {
        $filters = [
            AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                // query-builder splits a value on commas: the term is the value as sent, put back
                // together. A blank search filters nothing, as an empty search box should.
                $term = is_array($value) ? implode(',', array_filter($value, is_string(...))) : (is_string($value) ? $value : '');

                if (trim($term) !== '') {
                    Translatable::search($query, new TranslationSearch(['name'], $term));
                }
            }),
            AllowedFilter::callback('parent', static function (Builder $query, mixed $value): void {
                if ($value === 'root') {
                    $query->whereNull('parent_id');
                } elseif ((is_string($value) || is_int($value)) && KeyShape::isTagKey($value)) {
                    $query->where('parent_id', $value);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }),
            AllowedFilter::callback('depth', static function (Builder $query, mixed $value): void {
                // Within the column's range only: PostgreSQL answers a larger value with an error.
                $digits = is_string($value) && ctype_digit($value) ? ltrim($value, '0') : null;

                $digits !== null && strlen($digits) <= 3 && (int) $digits <= self::MAX_DEPTH
                    ? $query->where('depth', (int) $digits)
                    : $query->whereRaw('1 = 0');
            }),
            AllowedFilter::callback('ids', static function (Builder $query, mixed $value): void {
                $keys = array_values(array_filter(is_array($value) ? $value : [$value], static fn (mixed $key): bool => (is_string($key) || is_int($key)) && KeyShape::isTagKey($key)));

                $keys === [] ? $query->whereRaw('1 = 0') : $query->whereKey($keys);
            }),
            AllowedFilter::callback('slug', static function (Builder $query, mixed $value): void {
                $slugs = array_values(array_filter(is_array($value) ? $value : [$value], is_string(...)));

                $query->where(static function (Builder $group) use ($slugs): void {
                    foreach (TagSlugs::chain() as $locale) {
                        $group->orWhereIn('slug->'.TagName::ensureLocale($locale), $slugs);
                    }
                });
            }),
        ];

        if ($managedScopeKeys !== []) {
            $filters[] = AllowedFilter::callback('trashed', static function (Builder $query, mixed $value) use ($managedScopeKeys): void {
                $deletedAt = TagModel::make()->getQualifiedDeletedAtColumn();

                if ($value === 'with') {
                    $query->withoutGlobalScope(SoftDeletingScope::class)
                        ->where(static fn (Builder $rows) => $rows->whereNull($deletedAt)->orWhereIn('scope_key', $managedScopeKeys));
                } elseif ($value === 'only') {
                    $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull($deletedAt)->whereIn('scope_key', $managedScopeKeys);
                }
            });
        }

        // The allow-list first: the first call forwarded to the builder applies the request.
        $builder = QueryBuilder::for(TagModel::class(), $request)
            ->allowedFilters(...$filters)
            ->allowedSorts(
                AllowedSort::field('position'),
                AllowedSort::custom('name', new self),
                AllowedSort::field('usage_count'),
                AllowedSort::field('created_at'),
            )
            ->defaultSort('position');

        $builder->where('type', $type);
        $builder->whereIn('scope_key', $scopeKeys);

        return $builder;
    }

    /**
     * The `name` sort: the current locale's name.
     *
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, SortDirection $direction, string $property): void
    {
        $query->orderBy('name->'.TagName::ensureLocale(Translatable::currentLocale()), $direction->value);
    }
}
