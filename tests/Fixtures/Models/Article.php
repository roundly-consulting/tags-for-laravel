<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * A taggable host model with soft deletes and global tags.
 */
final class Article extends Model implements Taggable
{
    use BelongsToTeam;
    use FixtureKeys;
    use HasTags;
    use SoftDeletes;

    protected $table = 'articles';

    protected $guarded = [];
}
