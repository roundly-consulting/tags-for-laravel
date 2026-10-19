<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\MorphAliases;
use RoundlyConsulting\Tags\Support\NoSuffix;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

it('reads every config key strictly', function (string $key, mixed $value, Closure $read, string $message): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidTagsConfigurationException::class, $message);
})->with([
    'table name' => ['tags.tables.tags', 'tags; drop', fn () => TagsConfig::table('tags'), 'tags.tables.tags'],
    'default type' => ['tags.default_type', 'Not A Type', fn () => TagsConfig::defaultType(), 'tags.default_type'],
    'types map' => ['tags.types', 'flat', fn () => TagsConfig::types(), 'tags.types'],
    'types keys' => ['tags.types', ['flat'], fn () => TagsConfig::types(), 'tags.types'],
    'separator' => ['tags.slugs.separator', '+', fn () => TagsConfig::slugSeparator(), 'tags.slugs.separator'],
    'dictionary' => ['tags.slugs.dictionary', 'x', fn () => TagsConfig::slugDictionary(), 'tags.slugs.dictionary'],
    'dictionary entries' => ['tags.slugs.dictionary', ['+' => 5], fn () => TagsConfig::slugDictionary(), 'tags.slugs.dictionary'],
    'prefix' => ['tags.routes.prefix', 5, fn () => TagsConfig::routesPrefix(), 'tags.routes.prefix'],
    'middleware' => ['tags.routes.middleware', 'api', fn () => TagsConfig::routesMiddleware(), 'tags.routes.middleware'],
    'middleware entries' => ['tags.routes.middleware', [''], fn () => TagsConfig::routesMiddleware(), 'tags.routes.middleware'],
    'view ability' => ['tags.authorization.abilities.view', 5, fn () => TagsConfig::viewAbility(), 'tags.authorization.abilities.view'],
    'scope aliases' => ['tags.scopes', ['Team' => Team::class], fn () => MorphAliases::scope('Team:1'), 'tags.scopes'],
]);

it('reads blank and null config values as their defaults', function (): void {
    config()->set('tags.slugs.dictionary', null);
    config()->set('tags.routes.prefix', null);
    config()->set('tags.routes.middleware', null);
    config()->set('tags.types', null);

    expect(TagsConfig::slugDictionary())->toBe([])
        ->and(TagsConfig::routesPrefix())->toBe('')
        ->and(TagsConfig::routesMiddleware())->toBe([])
        ->and(TagsConfig::types())->toBe([])
        ->and(fn () => TagsConfig::table('nope'))->toThrow(InvalidArgumentException::class);
});

it('suffixes nothing: a taken slug is a duplicate', function (): void {
    expect(fn () => (new NoSuffix)->suffix('rock', 1, TagSlugs::resolved()))->toThrow(DuplicateTagException::class, '[rock]');
});

it('restores a deleted free-form tag through a sync', function (): void {
    $article = Article::query()->create();
    $live = Tags::type('tag')->findOrCreate('Live');
    Tags::type('tag')->delete($live);
    Event::fake([TagRestored::class]);

    $article->syncTags(['Live']);

    expect($article->tagOf('tag')?->is($live))->toBeTrue();
    Event::assertDispatched(TagRestored::class);
});

it('edits manual slugs and refuses them on free-form types', function (): void {
    Tags::define('genre')->hierarchical();
    $jazz = Tags::type('genre')->make('Jazz')->create();
    $rock = Tags::type('tag')->make('Rock')->create();

    expect(Tags::type('genre')->edit($jazz)->slug(['en' => 'jazz-music'])->save()->slug)->toBe('jazz-music')
        ->and(fn () => Tags::type('tag')->update($rock, new UpdateTagData(slug: 'rocks')))->toThrow(InvalidTagException::class, 'only curated types')
        ->and(Tags::type('tag')->update($rock, new UpdateTagData(color: null))->color)->toBeNull();
});

it('refuses to update a tag deleted meanwhile', function (): void {
    $rock = Tags::type('tag')->make('Rock')->create();
    Tag::query()->whereKey($rock->getKey())->delete();

    expect(fn () => Tags::type('tag')->rename($rock, 'Rock music'))->toThrow(TagNotFoundException::class);
});

it('skips inputs that name nothing in a lookup', function (): void {
    $type = Tags::type('tag')->definition();
    Tags::type('tag')->make('Rock')->create();

    expect(TagLookup::byStrings($type, [''], ['!!!', '   ']))->toBe([])
        ->and(TagLookup::allByStrings($type, null, ['!!!']))->toBe([])
        ->and(TagLookup::matchIn(Tag::query()->get(), [''], '!!!'))->toBeNull()
        ->and(TagLookup::holders($type, '', []))->toHaveCount(0);
});

it('takes integer and closure values in the filter', function (): void {
    $article = Article::query()->create(['title' => 'tagged']);
    $article->attachTags('2024');
    $tag = Tag::query()->firstOrFail();

    $byName = Article::query();
    TagsFilter::any('tag')->apply($byName, [2024], 'tags');

    $byId = Article::query();
    TagsFilter::any('tag')->byId()->within(static fn (): string => 'not a model')->apply($byId, [(int) $tag->getKey()], 'tags');

    expect($byName->pluck('title')->all())->toBe(['tagged'])
        ->and($byId->pluck('title')->all())->toBe(['tagged']);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

it('relates a tagging to its tag', function (): void {
    Article::query()->create()->attachTags('Live');

    expect(Tagging::query()->firstOrFail()->tag?->slug)->toBe('live')
        ->and(Tagging::query()->firstOrFail()->created_at)->not->toBeNull();
});

it('scopes tag queries by type, scope and tree', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    Tags::define('genre')->hierarchical();
    $music = Tags::type('genre')->make('Music')->create();
    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    $ours = Tags::type('tag')->within($team)->make('Ours')->create();

    expect(Tag::query()->ofType('genre')->count())->toBe(2)
        ->and(Tag::query()->global()->count())->toBe(2)
        ->and(Tag::query()->within($team)->count())->toBe(3)
        ->and(Tag::query()->within($team, includeGlobal: false)->pluck('id')->all())->toBe([$ours->getKey()])
        ->and(Tag::query()->within(null)->count())->toBe(2)
        ->and(Tag::query()->roots()->count())->toBe(2)
        ->and(Tag::query()->descendantsOf($music, self: true)->count())->toBe(2)
        ->and($jazz->definition()->name)->toBe('genre');
});

it('records the default type a sync named nothing for', function (): void {
    $fake = Tags::fake();
    $article = Article::query()->create();

    $article->syncTags(['live']);

    $fake->assertSynced($article, 'tag', ['live']);

    expect($fake->recorded()[0]->changesType())->toBeNull()
        ->and(TagSelection::of(collect(['a', 'b']))->tags)->toBe(['a', 'b']);
});
