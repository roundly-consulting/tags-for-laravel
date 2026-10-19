<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * Which tags a string names. A string is normalised, then slugified per chain locale (current,
 * fallback, then any) with sluggable's own pipeline; it names the tag whose slug in that locale
 * equals the candidate. So "Rock", "rock" and "ROCK!" are one tag, and a slug names its tag.
 *
 * @internal
 */
final class TagLookup
{
    /**
     * One live tag per string, preferring the earlier scope key (the model's scope before the
     * global one), then the earlier chain locale. Strings that name no tag are left out. With
     * `$trashed`, the same identity rule over deleted tags only.
     *
     * @param  list<string>  $scopeKeys  in preference order
     * @param  list<string>  $inputs
     * @return array<string, Tag> input => tag
     */
    public static function byStrings(TagType $type, array $scopeKeys, array $inputs, bool $trashed = false): array
    {
        [$candidates, $chain] = self::candidates($inputs);

        if ($candidates === []) {
            return [];
        }

        $tags = self::query($type, $scopeKeys, $candidates, $chain, $trashed)->orderBy(TagModel::make()->getKeyName())->get();
        $found = [];

        foreach ($candidates as $input => $slugs) {
            $tag = self::pick($tags, $scopeKeys, $chain, $slugs);

            if ($tag !== null) {
                $found[$input] = $tag;
            }
        }

        return $found;
    }

    /**
     * Every live tag a string names at the first chain locale with any match (several when the
     * string names a tag in several scopes). Null scope keys mean every scope.
     *
     * @param  list<string>|null  $scopeKeys
     * @param  list<string>  $inputs
     * @return array<string, list<Tag>> input => tags
     */
    public static function allByStrings(TagType $type, ?array $scopeKeys, array $inputs): array
    {
        [$candidates, $chain] = self::candidates($inputs);

        if ($candidates === []) {
            return [];
        }

        $tags = self::query($type, $scopeKeys, $candidates, $chain)->get();
        $found = [];

        foreach ($candidates as $input => $slugs) {
            foreach ($chain as $locale) {
                $level = $tags->filter(static fn (Tag $tag): bool => isset($slugs[$locale]) && $tag->slugFor($locale) === $slugs[$locale]);

                if ($level->isNotEmpty()) {
                    $found[$input] = array_values($level->sortBy(static fn (Tag $tag): string => (string) $tag->getKey())->all());

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The tags of a type and scope holding any of the given slugs, trashed ones included.
     *
     * @param  array<string, string>  $slugs  locale => slug
     * @return Collection<int, Tag>
     */
    public static function holders(TagType $type, string $scopeKey, array $slugs, ?Tag $except = null, bool $lock = false): Collection
    {
        if ($slugs === []) {
            return new Collection;
        }

        $query = TagModel::query()->withTrashed()
            ->where('type', $type->name)
            ->where('scope_key', $scopeKey)
            ->where(static function (Builder $group) use ($slugs): void {
                foreach ($slugs as $locale => $slug) {
                    $group->orWhere('slug->'.TagName::ensureLocale($locale), $slug);
                }
            });

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy(TagModel::make()->getKeyName())->get();
    }

    /**
     * The live tag among `$tags` that `$input` names, by the same preference as
     * {@see self::byStrings()} — for rows a locking read already fetched.
     *
     * @param  Collection<int, Tag>  $tags
     * @param  list<string>  $scopeKeys
     */
    public static function matchIn(Collection $tags, array $scopeKeys, string $input): ?Tag
    {
        [$candidates, $chain] = self::candidates([$input]);

        if (! isset($candidates[$input])) {
            return null;
        }

        return self::pick($tags->filter(static fn (Tag $tag): bool => ! $tag->trashed())->values(), $scopeKeys, $chain, $candidates[$input]);
    }

    /**
     * The candidate slug of every valid input per chain locale.
     *
     * @param  list<string>  $inputs
     * @return array{0: array<string, array<string, string>>, 1: list<string>}
     */
    private static function candidates(array $inputs): array
    {
        $chain = TagSlugs::chain();
        $definition = TagSlugs::resolved();
        $candidates = [];

        foreach ($inputs as $input) {
            $name = TagName::normalizeOrNull($input);

            if ($name === null) {
                continue;
            }

            $slugs = [];

            foreach ($chain as $locale) {
                $slug = TagSlugs::slugify($name, $locale, $definition);

                if ($slug !== '') {
                    $slugs[$locale] = $slug;
                }
            }

            if ($slugs !== []) {
                $candidates[$input] = $slugs;
            }
        }

        return [$candidates, $chain];
    }

    /**
     * @param  list<string>|null  $scopeKeys
     * @param  array<string, array<string, string>>  $candidates
     * @param  list<string>  $chain
     * @return Builder<Tag>
     */
    private static function query(TagType $type, ?array $scopeKeys, array $candidates, array $chain, bool $trashed = false): Builder
    {
        $query = TagModel::query()->where('type', $type->name);

        if ($trashed) {
            $query->onlyTrashed();
        }

        if ($scopeKeys !== null) {
            $query->whereIn('scope_key', $scopeKeys);
        }

        return $query->where(static function (Builder $group) use ($candidates, $chain): void {
            foreach ($chain as $locale) {
                $slugs = array_values(array_unique(array_filter(array_map(
                    static fn (array $slugs): ?string => $slugs[$locale] ?? null,
                    $candidates,
                ))));

                if ($slugs !== []) {
                    $group->orWhereIn('slug->'.$locale, $slugs);
                }
            }
        });
    }

    /**
     * @param  Collection<int, Tag>  $tags
     * @param  list<string>  $scopeKeys
     * @param  list<string>  $chain
     * @param  array<string, string>  $slugs
     */
    private static function pick(Collection $tags, array $scopeKeys, array $chain, array $slugs): ?Tag
    {
        foreach ($scopeKeys as $scopeKey) {
            foreach ($chain as $locale) {
                if (! isset($slugs[$locale])) {
                    continue;
                }

                $match = $tags->first(static fn (Tag $tag): bool => $tag->scope_key === $scopeKey && $tag->slugFor($locale) === $slugs[$locale]);

                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }
}
