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
 * @method static void assertNothingHappened()
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
 * @method static void assertTagsReordered(string|null $type = null)
 * @method static void assertNoTagsReordered()
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
