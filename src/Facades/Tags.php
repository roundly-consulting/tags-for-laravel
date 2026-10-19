<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Testing\TagsFake;

/**
 * @method static \RoundlyConsulting\Tags\Types\TagTypeBuilder define(\BackedEnum|string $type)
 * @method static void extend(\BackedEnum|string $type, \Closure $callback)
 * @method static \Illuminate\Support\Collection<string, \RoundlyConsulting\Tags\Types\TagType> types()
 * @method static \RoundlyConsulting\Tags\Handles\TypeTags type(\BackedEnum|string $type)
 * @method static \RoundlyConsulting\Tags\Models\Tag|null find(int|string $key)
 * @method static \RoundlyConsulting\Tags\Models\Tag findOrFail(int|string $key)
 * @method static void loadAncestors(iterable<mixed> $tags)
 * @method static \RoundlyConsulting\Tags\Handles\TaggableTags for(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Tags\Contracts\Taggable $taggable)
 * @method static \RoundlyConsulting\Tags\DataTransferObjects\TagChanges attach(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Tags\Contracts\Taggable $taggable, mixed $tags, \BackedEnum|string|null $type = null)
 * @method static \RoundlyConsulting\Tags\DataTransferObjects\TagChanges detach(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Tags\Contracts\Taggable $taggable, mixed $tags, \BackedEnum|string|null $type = null)
 * @method static \RoundlyConsulting\Tags\DataTransferObjects\TagChanges sync(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Tags\Contracts\Taggable $taggable, mixed $tags, \BackedEnum|string|null $type = null)
 * @method static \RoundlyConsulting\Tags\DataTransferObjects\TagChanges clear(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Tags\Contracts\Taggable $taggable, \BackedEnum|string|null $type = null)
 * @method static \RoundlyConsulting\Tags\DataTransferObjects\RepairReport repair(\RoundlyConsulting\Tags\DataTransferObjects\RepairOptions|null $options = null)
 * @method static void assertNothingHappened()
 * @method static void assertTagged(\Illuminate\Database\Eloquent\Model $model, list<\RoundlyConsulting\Tags\Models\Tag|string>|null $tags = null, \BackedEnum|string|null $type = null)
 * @method static void assertNothingTagged()
 * @method static void assertUntagged(\Illuminate\Database\Eloquent\Model $model, list<\RoundlyConsulting\Tags\Models\Tag|string>|null $tags = null, \BackedEnum|string|null $type = null)
 * @method static void assertNothingUntagged()
 * @method static void assertSynced(\Illuminate\Database\Eloquent\Model $model, \BackedEnum|string $type, list<\RoundlyConsulting\Tags\Models\Tag|string>|null $tags = null)
 * @method static void assertNothingSynced()
 * @method static void assertCleared(\Illuminate\Database\Eloquent\Model $model, \BackedEnum|string|null $type = null)
 * @method static void assertNothingCleared()
 * @method static void assertTagCreated(\Closure|null $callback = null)
 * @method static void assertNoTagCreated()
 * @method static void assertTagUpdated(\Closure|null $callback = null)
 * @method static void assertNoTagUpdated()
 * @method static void assertTagMoved(\Closure|null $callback = null)
 * @method static void assertNoTagMoved()
 * @method static void assertTagDeleted(\Closure|null $callback = null)
 * @method static void assertNoTagDeleted()
 * @method static void assertTagRestored(\Closure|null $callback = null)
 * @method static void assertNoTagRestored()
 * @method static void assertTagsReordered(\BackedEnum|string|null $type = null)
 * @method static void assertNoTagsReordered()
 * @method static void assertRepaired(\Closure|null $callback = null)
 * @method static void assertNothingRepaired()
 *
 * @see TagsManager
 */
final class Tags extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected TagsManager is faked too. Every call still runs for real.
     */
    public static function fake(): TagsFake
    {
        $fake = app(TagsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TagsManager::class;
    }
}
