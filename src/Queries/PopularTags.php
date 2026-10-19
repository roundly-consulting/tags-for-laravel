<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Queries;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * The most used tags of a type in the readable scopes. Without `$on`, by the cached usage count
 * (every taggable class, soft-deleted taggables included). With `$on` (a taggable class), by a
 * live count of that class's taggings — `$where` restricts it to the taggables a consumer may
 * show (`fn (Builder $events) => $events->listed()`), so a tag used only on hidden records
 * neither counts nor appears. Unused tags are left out. Resolved from the container.
 */
final readonly class PopularTags
{
    /**
     * @param  list<string>  $scopeKeys
     * @param  class-string<Model>|null  $on
     * @param  (Closure(Builder<Model>): mixed)|null  $where
     * @return Collection<int, Tag>
     */
    public function popular(TagType $type, array $scopeKeys, ?int $limit = null, ?string $on = null, ?Closure $where = null): Collection
    {
        $limit = max(1, min($limit ?? TagsConfig::popularLimit(), TagsConfig::popularMaxLimit()));
        $model = TagModel::make();
        $query = TagModel::query()->where('type', $type->name)->whereIn('scope_key', $scopeKeys);

        if ($on === null) {
            if ($where !== null) {
                throw new InvalidArgumentException('popular(): a where constraint needs the taggable class it filters ($on).');
            }

            return $query->where('usage_count', '>', 0)
                ->orderByDesc('usage_count')
                ->orderBy($model->getKeyName())
                ->limit($limit)
                ->get();
        }

        if (! is_subclass_of($on, Model::class)) {
            throw new InvalidArgumentException('popular(): $on must be a taggable model class.');
        }

        $taggable = new $on;
        $constraint = static function (Builder $taggings) use ($taggable, $where): void {
            $taggings->where('taggable_type', $taggable->getMorphClass());

            if ($where !== null) {
                $visible = $taggable->newQuery();
                $where($visible);

                $taggings->whereIn('taggable_id', $visible->select($taggable->getQualifiedKeyName()));
            }
        };

        return $query->whereHas('taggings', $constraint)
            ->withCount(['taggings as taggables_count' => $constraint])
            ->orderByDesc('taggables_count')
            ->orderBy($model->getKeyName())
            ->limit($limit)
            ->get();
    }
}
