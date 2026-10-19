<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * Always uuid-keyed: a key-type mismatch on a bigint install.
 */
final class UuidArticle extends Model implements Taggable
{
    use HasTags;
    use HasUuids;

    protected $table = 'uuid_articles';

    protected $guarded = [];
}
