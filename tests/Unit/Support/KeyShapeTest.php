<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Rules\TagsRule;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

test('bigint_keys_stay_within_the_signed_64_bit_range', function (mixed $key, bool $fits): void {
    expect(KeyShape::fits($key, KeyType::BigInt))->toBe($fits);
})->with([
    'an int' => [42, true],
    'digits' => ['42', true],
    '18 nines' => [str_repeat('9', 18), true],
    'the bigint max' => ['9223372036854775807', true],
    'leading zeros' => ['0000000000000000042', true],
    'the bigint max plus one' => ['9223372036854775808', false],
    '19 nines' => [str_repeat('9', 19), false],
    '20 digits' => [str_repeat('1', 20), false],
    'a sign' => ['-1', false],
]);

test('canonical_keys_have_one_spelling_per_key_type', function (string $type, mixed $key, string $canonical): void {
    config()->set('tags.primary_key_type', $type);

    expect(KeyShape::canonical($key))->toBe($canonical);
})->with([
    'a bigint' => ['bigint', 42, '42'],
    'leading zeros' => ['bigint', '0042', '42'],
    'zeros only' => ['bigint', '000', '0'],
    'not a bigint' => ['bigint', 'abc', 'abc'],
    'a float' => ['bigint', 1.5, '1.5'],
    'null' => ['bigint', null, ''],
    'an upper-case uuid' => ['uuid', '01A1583B-6A99-7231-97E5-7FE9CD84E981', '01a1583b-6a99-7231-97e5-7fe9cd84e981'],
    'a lower-case ulid' => ['ulid', '01hzxq7m8b6v1kq4p3r2s5t6v7', '01HZXQ7M8B6V1KQ4P3R2S5T6V7'],
]);

// A key with leading zeros is the same key: the database finds the row, so every lookup in PHP
// must find it too.
test('non_canonical_keys_resolve_like_canonical_ones', function (): void {
    Tags::define('label');
    $live = Tags::type('label')->make('Live')->create();
    $loud = Tags::type('label')->make('Loud')->create();
    $padded = static fn (Tag $tag): string => '0'.$tag->getKey();
    $article = Article::query()->create(['title' => 'tagged']);
    $article->attachTags($live, 'label');

    expect(Tags::type('label')->findMany([$padded($live)])->modelKeys())->toBe([$live->getKey()])
        ->and(Tags::type('label')->findMany([$padded($live), (string) $live->getKey()])->modelKeys())->toBe([$live->getKey()])
        ->and(Validator::make(['labels' => [$padded($live)]], ['labels' => [TagsRule::for('label')->byId()]])->errors()->toArray())->toBe([])
        ->and(Validator::make(['labels' => [$padded($live), $live->getKey()]], ['labels' => [TagsRule::for('label')->byId()->max(1)]])->errors()->toArray())->toBe([])
        ->and(Tags::type('label')->reorder(null, [$padded($loud)])->pluck('slug')->all())->toBe(['loud', 'live'])
        ->and(QueryBuilder::for(Article::class, Request::create('/', 'GET', ['filter' => ['labels' => $padded($live)]]))
            ->allowedFilters(AllowedFilter::custom('labels', TagsFilter::any('label')->byId()))
            ->pluck('title')
            ->all())->toBe(['tagged']);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');
