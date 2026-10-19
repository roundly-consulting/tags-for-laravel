<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;

/**
 * HasTags beside sluggable's and translatable's traits on one model: no name collides.
 */
final class TwoTraitsArticle extends Model implements Sluggable, Taggable, Translatable
{
    use FixtureKeys, HasSlug {
        HasSlug::resolveRouteBindingQuery insteadof FixtureKeys;
    }
    use HasTags;
    use HasTranslations;

    protected $table = 'pages';

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'slug'];
}
