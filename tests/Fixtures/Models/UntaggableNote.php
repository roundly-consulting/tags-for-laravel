<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Concerns\HasTags;

/**
 * A model that uses the trait but forgot the interface: the deletion hook leaves it alone.
 * Not analysed by Larastan (the trait requires the interface).
 */
final class UntaggableNote extends Model
{
    use FixtureKeys;
    use HasTags;

    protected $table = 'notes';

    protected $guarded = [];
}
