<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tags\Tests\TestCase;

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
        Assert::assertTrue((new Tag)->resolveRouteBindingQuery(Tag::query(), (string) $jazz->getKey())->exists());

        try {
            (new Tag)->resolveRouteBindingQuery(Tag::query(), 'not-a-key');
            Assert::fail('A malformed key must never reach the database.');
        } catch (ModelNotFoundException) {
        }
        Assert::assertNull(Tags::find(Str::random(10)));
        Assert::assertSame($team->getKey(), Tag::query()->findOrFail($music->getKey())->scope_id);

        // Tagging, counts, scopes and the read-only relation on string keys.
        $article = ScopedArticle::query()->create(['title' => 'Uuid', 'team_id' => $team->getKey()]);
        $article->syncTags($jazz, 'genre')->attachTags(['Live']);

        Assert::assertTrue($isKey($article->getKey()));
        Assert::assertSame(['jazz'], $article->tagsOf('genre')->pluck('slug')->all());
        Assert::assertSame(1, ScopedArticle::query()->withAnyTags(['music'], 'genre', descendants: true)->count());
        Assert::assertSame(1, Tag::query()->findOrFail($jazz->getKey())->usage_count);
        Assert::assertSame(1, ScopedArticle::query()->withAnyTags([$jazz], 'genre')->count());
        Assert::assertSame(2, Tagging::query()->count());

        $article->forceDelete();
        Assert::assertSame(0, Tagging::query()->count());
    }

    /**
     * The API: string keys in paths and bodies, and a malformed key never reaches the database.
     */
    public static function api(TestCase $test): void
    {
        Gate::define('tags.manage', static fn (): bool => true);
        Gate::define('tags.scope', static fn (): bool => true);
        $test->actingAs(User::query()->create(['name' => 'Admin']));
        Tags::define('genre')->hierarchical();
        $team = Team::query()->create(['name' => 'Acme']);
        $scope = 'scope=team:'.$team->getKey();

        $music = $test->postJson("/api/tags/genre?{$scope}", ['name' => ['en' => 'Music']])->assertCreated()->json('data.id');
        $jazz = $test->postJson("/api/tags/genre?{$scope}", ['name' => ['en' => 'Jazz'], 'parent' => $music])->assertCreated()->json('data.id');

        $test->getJson("/api/tags/genre/{$jazz}?{$scope}")->assertOk()->assertJsonPath('data.ancestors.0.id', $music);
        $test->getJson("/api/tags/genre?{$scope}&filter[parent]={$music}")->assertOk()->assertJsonCount(1, 'data');
        $test->postJson("/api/tags/genre/{$jazz}/move?{$scope}", ['parent' => null])->assertOk();
        $test->getJson('/api/tags/genre?scope=team:42')->assertUnprocessable()->assertJson(['code' => 'unknown_alias']);
        $test->getJson("/api/tags/genre/42?{$scope}")->assertNotFound();
        $test->postJson("/api/tags/genre?{$scope}", ['name' => ['en' => 'X'], 'parent' => '42'])->assertUnprocessable()->assertJson(['code' => 'tag_not_found']);
    }
}
