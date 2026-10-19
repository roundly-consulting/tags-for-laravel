<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Contracts;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Relations\TagsRelation;

/**
 * A model that can carry tags. Use the `HasTags` trait, which implements every method.
 */
interface Taggable
{
    /**
     * The model's tags — a read-only relation: write through `attachTags()`, `detachTags()`,
     * `syncTags()` and `clearTags()`.
     *
     * @return TagsRelation<Tag, covariant Model>
     */
    public function tags(): TagsRelation;

    /**
     * The owner of the model's free-form tags (a team, a tenant, a project), or null for global
     * tags only.
     */
    public function tagScope(): ?Model;

    /**
     * The tag types the model may carry, or null for any defined type.
     *
     * @return list<string|BackedEnum>|null
     */
    public function tagTypes(): ?array;
}
