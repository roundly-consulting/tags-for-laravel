<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * One tag on one model: a row of the taggables pivot. Written only by the package's tagging
 * actions, under their locks; it has no soft deletes and no factory, because the unique pivot
 * would refuse a trashed row's re-tag (a recorded deviation from the model rules).
 *
 * @property int|string $tag_id
 * @property string $taggable_type
 * @property int|string $taggable_id
 * @property string $type
 * @property CarbonInterface|null $created_at
 * @property-read Tag|null $tag
 */
final class Tagging extends MorphPivot
{
    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return TagsConfig::table('taggables');
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(TagModel::class(), 'tag_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
