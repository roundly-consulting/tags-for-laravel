<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The query scopes of a taggable model (`withAnyTags`, `withAllTags`, `withoutTags`,
 * `withTagsOf`) and the query-builder filter, as one implementation — so PHP and HTTP filter
 * identically.
 *
 * Strings resolve without creating, in every scope of the type unless `$within` narrows them to
 * that scope plus the global tags (the host's own query already narrows the models). An unknown
 * string matches nothing. With `$descendants`, a tag of a hierarchical type also matches every
 * tag below it; on a flat type that is a no-op.
 *
 * @internal
 */
final class TagScopes
{
    /**
     * Models carrying at least one of the tags.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function any(Builder $query, mixed $tags, string|BackedEnum|null $type, bool $descendants = false, ?Model $within = null, bool $byId = false): void
    {
        $groups = self::groups($tags, $type, $descendants, $within, $byId);
        $keys = $groups === [] ? [] : array_values(array_unique(array_merge(...$groups)));

        if ($keys === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($query->getModel()->getQualifiedKeyName(), self::taggedWith($query->getModel(), $keys, $type));
    }

    /**
     * Models carrying every one of the tags (each satisfied by itself or, with `$descendants`, by
     * a tag below it). A string that names no tag matches no model.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function all(Builder $query, mixed $tags, string|BackedEnum|null $type, bool $descendants = false, ?Model $within = null, bool $byId = false): void
    {
        $groups = self::groups($tags, $type, $descendants, $within, $byId);

        if ($groups === []) {
            return;
        }

        foreach ($groups as $keys) {
            if ($keys === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn($query->getModel()->getQualifiedKeyName(), self::taggedWith($query->getModel(), $keys, $type));
        }
    }

    /**
     * Models carrying none of the tags; with no tags, models carrying no tag of the type (or no
     * tag at all, with no type).
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function none(Builder $query, mixed $tags, string|BackedEnum|null $type, bool $descendants = false, ?Model $within = null, bool $byId = false): void
    {
        $model = $query->getModel();

        if (TagSelection::of($tags)->isEmpty()) {
            $sub = self::taggings($model);

            if ($type !== null) {
                $sub->where('type', app(TagTypeRegistry::class)->resolve($type)->name);
            }

            $query->whereNotIn($model->getQualifiedKeyName(), $sub);

            return;
        }

        $groups = self::groups($tags, $type, $descendants, $within, $byId);
        $keys = $groups === [] ? [] : array_values(array_unique(array_merge(...$groups)));

        if ($keys !== []) {
            $query->whereNotIn($model->getQualifiedKeyName(), self::taggedWith($model, $keys, $type));
        }
    }

    /**
     * Models carrying at least one tag of the type.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function ofType(Builder $query, string|BackedEnum $type): void
    {
        $model = $query->getModel();

        $query->whereIn(
            $model->getQualifiedKeyName(),
            self::taggings($model)->where('type', app(TagTypeRegistry::class)->resolve($type)->name),
        );
    }

    /**
     * The tag keys each input names (several when a name names tags in several scopes, plus
     * descendants), in input order. With `$byId`, strings are primary keys (a uuid, or a number
     * from a query string), never names.
     *
     * @return list<list<string>>
     */
    public static function groups(mixed $tags, string|BackedEnum|null $type, bool $descendants, ?Model $within, bool $byId = false): array
    {
        $registry = app(TagTypeRegistry::class);
        $selection = TagSelection::of($tags);
        $explicit = $type === null ? null : $registry->resolve($type);

        $byInput = [];
        $strings = [];
        $keys = [];

        foreach ($selection->tags as $index => $input) {
            if ($input instanceof Tag) {
                if ($explicit !== null && $input->type !== $explicit->name) {
                    throw TagTypeMismatchException::expected($explicit->name, $input->type);
                }

                $byInput[$index] = [$input];
            } elseif (is_int($input) || $byId) {
                $keys[$index] = $input;
            } else {
                $strings[$index] = $input;
            }
        }

        if ($keys !== []) {
            $valid = array_values(array_filter($keys, KeyShape::isTagKey(...)));
            $query = TagModel::query()->whereKey($valid);

            if ($explicit !== null) {
                $query->where('type', $explicit->name);
            }

            if ($within !== null && $explicit?->globalOnly !== true) {
                $query->whereIn('scope_key', [ScopeKey::for($within), ScopeKey::GLOBAL]);
            }

            $loaded = $query->get()
                ->keyBy(static fn (Tag $tag): string => (string) $tag->getKey());

            foreach ($keys as $index => $key) {
                $tag = $loaded->get((string) $key);
                $byInput[$index] = $tag === null ? [] : [$tag];
            }
        }

        if ($strings !== []) {
            $stringType = $explicit ?? $registry->defaultType() ?? throw UnknownTagTypeException::typeRequired();
            $scopeKeys = $within === null || $stringType->globalOnly ? null : [ScopeKey::for($within), ScopeKey::GLOBAL];
            $found = TagLookup::allByStrings($stringType, $scopeKeys, array_values(array_unique($strings)));

            foreach ($strings as $index => $string) {
                $byInput[$index] = $found[$string] ?? [];
            }
        }

        ksort($byInput);

        $groups = [];

        foreach ($byInput as $matched) {
            $groups[] = self::keys($matched, $descendants ? $registry : null);
        }

        return $groups;
    }

    /**
     * @param  list<Tag>  $tags
     * @return list<string>
     */
    private static function keys(array $tags, ?TagTypeRegistry $descendantsOf): array
    {
        $keys = array_map(static fn (Tag $tag): string => (string) $tag->getKey(), $tags);

        if ($descendantsOf === null) {
            return $keys;
        }

        $trees = array_values(array_filter($tags, static fn (Tag $tag): bool => $descendantsOf->find($tag->type)->hierarchical ?? false));

        if ($trees === []) {
            return $keys;
        }

        $below = TagModel::query()
            ->where(static function (Builder $group) use ($trees): void {
                foreach ($trees as $tag) {
                    $group->orWhere(static fn (Builder $tree) => $tree->descendantsOf($tag));
                }
            })
            ->pluck(TagModel::make()->getKeyName())
            ->map(static fn (mixed $key): string => is_scalar($key) ? (string) $key : '')
            ->all();

        return array_values(array_unique([...$keys, ...$below]));
    }

    /**
     * The keys of `$model`'s class carrying any of the tags.
     *
     * @param  list<string>  $keys
     */
    private static function taggedWith(Model $model, array $keys, string|BackedEnum|null $type): QueryBuilder
    {
        $sub = self::taggings($model)->whereIn('tag_id', $keys);

        if ($type !== null) {
            $sub->where('type', TagTypeRegistry::nameOf($type));
        }

        return $sub;
    }

    private static function taggings(Model $model): QueryBuilder
    {
        return Tagging::query()
            ->where('taggable_type', $model->getMorphClass())
            ->select('taggable_id')
            ->toBase();
    }
}
