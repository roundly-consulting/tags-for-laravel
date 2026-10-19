<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * An article that may only carry labels.
 */
final class RestrictedArticle extends Model implements Taggable
{
    use BelongsToTeam;
    use FixtureKeys;
    use HasTags;
    use SoftDeletes;

    protected $table = 'articles';

    protected $guarded = [];

    public function tagTypes(): ?array
    {
        return [ArticleTagType::Label];
    }
}
