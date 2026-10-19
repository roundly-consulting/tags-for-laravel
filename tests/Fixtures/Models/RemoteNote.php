<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * A taggable model on another database connection: `tags:doctor` warns about it. Never queried.
 */
final class RemoteNote extends Model implements Taggable
{
    use HasTags;

    protected $connection = 'remote';

    protected $table = 'notes';

    protected $guarded = [];
}
