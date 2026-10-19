<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A scope (tenant) model.
 */
final class Team extends Model
{
    use FixtureKeys;

    protected $table = 'teams';

    protected $guarded = [];
}
