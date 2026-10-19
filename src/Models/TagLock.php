<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * The tree lock of one (type, scope): a row every tree write locks first, so moves, reorders and
 * subtree deletes never interleave. No soft deletes and no factory: a lock row is never deleted.
 *
 * @internal
 *
 * @property int $id
 * @property string $type
 * @property string $scope_key
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class TagLock extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TagsConfig::table('locks');
    }
}
