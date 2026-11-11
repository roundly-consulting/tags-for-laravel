<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tags\DataTransferObjects\TagKey;
use RoundlyConsulting\Tags\Exceptions\UnknownTagKeyException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Rules\TagsRule;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
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

    /**
     * A key string on a string-keyed install: a name by default (unchanged), a key through
     * `TagKey` / `Tags::keys()`, and a key first under `tags.string_keys`. An unknown key never
     * creates a tag.
     *
     * @param  Closure(): string  $freshKey  a well-formed key no tag holds
     */
    public static function tagKeys(Closure $freshKey): void
    {
        Tags::define('label');
        Tags::define('topic')->curated();
        $live = Tags::type('label')->make('Live')->create();
        $news = Tags::type('topic')->make('News')->create();
        $liveKey = (string) $live->getKey();
        $article = Article::query()->create(['title' => 'Keyed']);
        $other = Article::query()->create(['title' => 'Other']);

        $count = static fn (): int => Tag::query()->withTrashed()->count();
        $throws = static function (Closure $call): void {
            try {
                $call();
                Assert::fail('An unknown key must throw.');
            } catch (UnknownTagKeyException) {
            }
        };

        // Default: a key-shaped string is a name, as in 2.0 — a free-form type creates it.
        $other->attachTags([$liveKey], 'label');
        Assert::assertSame([$liveKey], $other->tagsOf('label')->pluck('name')->all());
        Assert::assertSame(3, $count());

        // The explicit path resolves the existing tag, in every verb and scope.
        $article->attachTags(TagKey::of($liveKey), 'label');
        $article->syncTags(Tags::keys([$news->getKey()]), 'topic');
        Assert::assertSame([$liveKey], array_map(strval(...), $article->tagsOf('label')->modelKeys()));
        Assert::assertSame(['news'], $article->tagsOf('topic')->pluck('slug')->all());
        Assert::assertTrue($article->hasTag(TagKey::of($liveKey)));
        Assert::assertSame(['Keyed'], Article::query()->withAnyTags([TagKey::of($liveKey)])->pluck('title')->all());
        Assert::assertSame(['Keyed'], Article::query()->withAllTags(Tags::keys([$liveKey, $news->getKey()]))->pluck('title')->all());
        Assert::assertSame(['Other'], Article::query()->withoutTags([TagKey::of($liveKey)], 'label')->pluck('title')->all());

        // An unknown key throws and creates nothing, on a free-form and on a curated type.
        $before = $count();
        $throws(static fn () => $article->attachTags([TagKey::of($freshKey()), 'Fresh'], 'label'));
        $throws(static fn () => Tags::sync($article, Tags::keys([$freshKey()]), 'topic'));
        Assert::assertSame($before, $count());
        Assert::assertSame(0, Article::query()->withAnyTags([TagKey::of($freshKey())])->count());
        Assert::assertSame([], Tags::detach($article, TagKey::of($freshKey()), 'label')->detached);

        $article->detachTags(TagKey::of($liveKey), 'label');
        Assert::assertSame([], $article->tagsOf('label')->all());

        // Opt-in: under tags.string_keys a key-shaped string is a key first.
        config()->set('tags.string_keys', true);

        $article->attachTags([$liveKey, 'Outdoor'], 'label');
        Assert::assertSame(['live', 'outdoor'], $article->tagsOf('label')->pluck('slug')->all());
        Assert::assertTrue($article->hasTag($liveKey, 'label'));
        Assert::assertTrue($article->hasAnyTags([$liveKey]));
        Assert::assertSame(['Keyed'], Article::query()->withAnyTags([$liveKey], 'label')->pluck('title')->all());
        Assert::assertTrue(Tags::type('label')->find($liveKey)?->is($live) ?? false);
        Assert::assertSame(['Keyed'], QueryBuilder::for(Article::class, Request::create('/', 'GET', ['filter' => ['labels' => $liveKey]]))
            ->allowedFilters(AllowedFilter::custom('labels', TagsFilter::any('label')))
            ->pluck('title')
            ->all());

        $before = $count();
        $throws(static fn () => $article->attachTags([$freshKey()], 'label'));
        Assert::assertSame($before, $count());
        Assert::assertSame(0, Article::query()->withAnyTags([$freshKey()], 'label')->count());

        // The rule answers as the write will: a key-shaped entry must be a key of the type.
        $passes = static fn (array $labels, TagsRule $rule): bool => Validator::make(['labels' => $labels], ['labels' => ['array', $rule]])->passes();

        Assert::assertTrue($passes([$liveKey, 'Brand new'], TagsRule::for('label')));
        Assert::assertTrue($passes([(string) $news->getKey()], TagsRule::for('topic')));
        Assert::assertFalse($passes([$freshKey()], TagsRule::for('label')));
        Assert::assertFalse($passes([$liveKey], TagsRule::for('topic')));
        Assert::assertFalse($passes([$liveKey, 'live', 'Loud'], TagsRule::for('label')->max(1)));
        Assert::assertTrue($passes([$liveKey, 'live'], TagsRule::for('label')->max(1)));

        config()->set('tags.string_keys', false);
        Assert::assertTrue($passes([$freshKey()], TagsRule::for('label')));
    }
}
