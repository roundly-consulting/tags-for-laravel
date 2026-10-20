<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * An article behind a host global scope (a "published" or tenancy scope): unlisted rows are
 * invisible to every query.
 */
final class ListedArticle extends Model implements Taggable
{
    use FixtureKeys;
    use HasTags;
    use SoftDeletes;

    protected $table = 'articles';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::addGlobalScope('listed', static function (Builder $query): void {
            $query->where('listed', true);
        });
    }
}
