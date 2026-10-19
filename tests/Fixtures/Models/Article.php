<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\Tests\Fixtures\Factories\ArticleFactory;

/**
 * A taggable host model with soft deletes and global tags.
 */
final class Article extends Model implements Taggable
{
    use BelongsToTeam;
    use FixtureKeys;

    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use HasTags;
    use SoftDeletes;

    protected $table = 'articles';

    protected $guarded = [];

    protected static function newFactory(): ArticleFactory
    {
        return ArticleFactory::new();
    }
}
