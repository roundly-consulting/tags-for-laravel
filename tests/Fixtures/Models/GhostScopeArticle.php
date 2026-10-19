<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * An article whose `tagScope()` has gone wrong in the host: an unsaved team.
 */
final class GhostScopeArticle extends Model implements Taggable
{
    use FixtureKeys;
    use HasTags;

    protected $table = 'articles';

    protected $guarded = [];

    public function tagScope(): ?Model
    {
        return new Team(['name' => 'Ghost']);
    }
}
