<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * A taggable host model without soft deletes.
 */
final class Note extends Model implements Taggable
{
    use FixtureKeys;
    use HasTags;

    protected $table = 'notes';

    protected $guarded = [];
}
