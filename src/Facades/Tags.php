<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Tags\DataTransferObjects\ExampleTagsData;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Testing\TagsFake;

/**
 * Keep one `@method static` line per public manager method — FacadeTest's
 * `toDocumentItsRoot()` fails on a missing, stale or miscounted line.
 *
 * @method static string example(ExampleTagsData $data)
 * @method static void assertExampleCalled(\Closure|null $callback = null)
 * @method static void assertNothingCalled()
 *
 * @see TagsManager
 */
final class Tags extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected TagsManager is faked too.
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
