<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Rules\TagsRule;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Support\KeyTypeScenarios;
use RoundlyConsulting\Testing\Database\DriverMatrix;

test('uuid_installation_works', function (): void {
    KeyTypeScenarios::catalog(static fn (mixed $key): bool => is_string($key) && Str::isUuid($key));
});

it('serves the API on uuid keys', function (): void {
    KeyTypeScenarios::api($this);
});

// A uuid in upper case is the same key: the database finds the row, so every lookup in PHP must
// find it too.
test('non_canonical_keys_resolve_like_canonical_ones', function (): void {
    Tags::define('label');
    $live = Tags::type('label')->make('Live')->create();
    $loud = Tags::type('label')->make('Loud')->create();
    $upper = static fn (Tag $tag): string => strtoupper((string) $tag->getKey());
    $article = Article::query()->create(['title' => 'tagged']);
    $article->attachTags($live, 'label');

    expect(Tags::type('label')->findMany([$upper($live)])->modelKeys())->toBe([$live->getKey()])
        ->and(Tags::type('label')->findMany([$upper($live), (string) $live->getKey()])->modelKeys())->toBe([$live->getKey()])
        ->and(Validator::make(['labels' => [$upper($live)]], ['labels' => [TagsRule::for('label')->byId()]])->errors()->toArray())->toBe([])
        ->and(Tags::type('label')->reorder(null, [$upper($loud)])->pluck('slug')->all())->toBe(['loud', 'live'])
        ->and(QueryBuilder::for(Article::class, Request::create('/', 'GET', ['filter' => ['labels' => $upper($live)]]))
            ->allowedFilters(AllowedFilter::custom('labels', TagsFilter::any('label')->byId()))
            ->pluck('title')
            ->all())->toBe(['tagged']);
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'sqlite compares uuid strings case-sensitively');
