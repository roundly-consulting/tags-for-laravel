<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\LikeClause;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Translatable\DataTransferObjects\TranslationSearch;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Autocomplete within one type and the readable scopes: tags whose slug in any supported locale
 * starts with the term's slug first, then tags whose name contains the term — each by usage. The
 * contains phase scans the type, so it runs only when the prefix phase leaves room. Resolved from
 * the container, so a host may override it.
 */
final readonly class SuggestTags
{
    /**
     * @param  list<string>  $scopeKeys
     * @return Collection<int, Tag>
     */
    public function suggest(TagType $type, array $scopeKeys, string $term, ?int $limit = null): Collection
    {
        $limit = max(1, min($limit ?? TagsConfig::suggestLimit(), TagsConfig::suggestMaxLimit()));
        $term = TagName::normalizeOrNull($term);

        if ($term === null) {
            return new Collection;
        }

        $prefixes = [];

        foreach (TagSlugs::locales() as $locale) {
            $prefix = TagSlugs::slugify($term, $locale);

            if ($prefix !== '') {
                $prefixes[$locale] = $prefix;
            }
        }

        if ($prefixes === []) {
            return new Collection;
        }

        $model = TagModel::make();
        $base = fn (): Builder => TagModel::query()
            ->where('type', $type->name)
            ->whereIn('scope_key', $scopeKeys)
            ->orderByDesc('usage_count')
            ->orderBy($model->getKeyName());

        $prefixed = $base()
            ->where(static function (Builder $group) use ($prefixes, $model): void {
                foreach ($prefixes as $locale => $prefix) {
                    LikeClause::startsWith($group, $model->qualifyColumn('slug').'->'.TagName::ensureLocale($locale), $prefix, 'or');
                }
            })
            ->limit($limit)
            ->get();

        if ($prefixed->count() >= $limit) {
            return $prefixed;
        }

        $containing = Translatable::search($base(), new TranslationSearch(['name'], $term))
            ->whereKeyNot($prefixed->modelKeys())
            ->limit($limit - $prefixed->count())
            ->get();

        return new Collection([...$prefixed->all(), ...$containing->filter(static fn (mixed $tag): bool => $tag instanceof Tag)->all()]);
    }
}
