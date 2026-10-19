<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Relations\TagsRelation;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * Tags on an Eloquent model: the read-only `tags()` relation and type-aware reads.
 *
 * @phpstan-require-extends Model
 */
trait HasTags
{
    /**
     * The model's tags, ordered by position. Read-only: every write method throws and names the
     * verb to use (`attachTags()`, `detachTags()`, `syncTags()`, `clearTags()`).
     *
     * @return TagsRelation<Tag, $this>
     */
    public function tags(): TagsRelation
    {
        $instance = $this->newRelatedInstance(TagModel::class());

        $relation = new TagsRelation(
            $instance->newQuery(),
            $this,
            'taggable',
            TagsConfig::table('taggables'),
            'taggable_id',
            'tag_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'tags',
            false,
        );

        $relation->using(Tagging::class)->withPivot('type', 'created_at');
        $relation->getQuery()->orderBy($instance->qualifyColumn('position'))->orderBy($instance->getQualifiedKeyName());

        return $relation;
    }

    /**
     * The owner of this model's free-form tags, or null for global tags only.
     */
    public function tagScope(): ?Model
    {
        return null;
    }

    /**
     * The tag types this model may carry, or null for any defined type.
     *
     * @return list<string|BackedEnum>|null
     */
    public function tagTypes(): ?array
    {
        return null;
    }
}
