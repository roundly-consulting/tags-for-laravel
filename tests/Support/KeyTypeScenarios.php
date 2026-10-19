<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Support;

use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

/**
 * The end-to-end path a uuid or ulid install must survive: keys, the self foreign key, morphs,
 * slugs and lookups.
 */
final class KeyTypeScenarios
{
    /**
     * @param  Closure(mixed): bool  $isKey
     */
    public static function catalog(Closure $isKey): void
    {
        Tags::define('genre')->hierarchical();
        $team = Team::query()->create(['name' => 'Acme']);

        $music = Tags::type('genre')->within($team)->make('Music')->create();
        $jazz = Tags::type('genre')->within($team)->make('Jazz')->under($music)->create();

        Assert::assertTrue($isKey($music->getKey()));
        Assert::assertTrue($isKey($jazz->parent_id));
        Assert::assertSame('/'.$music->getKey().'/', $jazz->path);
        Assert::assertTrue(Tags::find((string) $jazz->getKey())?->is($jazz) ?? false);
        Assert::assertTrue(Tags::type('genre')->within($team)->find('jazz')?->is($jazz) ?? false);
        Assert::assertSame([(string) $jazz->getKey()], array_map(strval(...), Tags::type('genre')->within($team)->findMany([(string) $jazz->getKey(), 'junk', 42])->modelKeys()));
        Assert::assertSame(1, $music->descendants()->count());
        Assert::assertSame('Music', $jazz->ancestors()->first()?->name);
        Assert::assertNull(Tags::find(42));
        Assert::assertNull(Tags::find(Str::random(10)));
        Assert::assertSame($team->getKey(), Tag::query()->findOrFail($music->getKey())->scope_id);
    }
}
