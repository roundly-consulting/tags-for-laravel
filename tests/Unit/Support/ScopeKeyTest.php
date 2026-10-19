<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagPath;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\UuidArticle;

it('keys a global tag with the empty string and a scoped one with its morph', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);

    expect(ScopeKey::for(null))->toBe('')
        ->and(ScopeKey::for($team))->toBe($team->getMorphClass().'#'.$team->getKey())
        ->and(ScopeKey::fromColumns(null, 5))->toBe('')
        ->and(ScopeKey::fromColumns('team', null))->toBe('')
        ->and(ScopeKey::fromColumns('team', 5))->toBe('team#5');
});

it('refuses an unsaved scope', function (): void {
    ScopeKey::for(new Team(['name' => 'Draft']));
})->throws(InvalidArgumentException::class, 'saved model');

it('refuses a scope reference longer than the column', function (): void {
    ScopeKey::fromColumns(str_repeat('a', 300), 1);
})->throws(InvalidArgumentException::class, 'shorter morph alias');

test('a_mismatched_key_type_fails_fast_naming_the_config_key', function (): void {
    $article = UuidArticle::query()->create(['title' => 'Uuid']);

    try {
        ScopeKey::for($article);
        $this->fail('A uuid scope on a bigint install must throw.');
    } catch (InvalidTagsConfigurationException $exception) {
        expect($exception->getMessage())->toContain('tags.key_type')->toContain('UuidArticle')->not->toContain('\\');
    }
})->skip(fn (): bool => config('tags.key_type') !== 'bigint', 'the bigint leg');

it('matches key shapes per key type', function (mixed $key, KeyType $type, bool $fits): void {
    expect(KeyShape::fits($key, $type))->toBe($fits);
})->with([
    [5, KeyType::BigInt, true],
    ['5', KeyType::BigInt, true],
    ['abc', KeyType::BigInt, false],
    ['01J9Z8Y7X6W5V4T3S2R1Q0P9N8', KeyType::Ulid, true],
    ['018f3a2e-7b1c-7d4e-9f00-0123456789ab', KeyType::Uuid, true],
    ['018f3a2e', KeyType::Uuid, false],
    [5, KeyType::Uuid, false],
]);

it('builds paths and spots cycles', function (): void {
    expect(TagPath::ancestorKeys('/1/5/'))->toBe(['1', '5'])
        ->and(TagPath::ancestorKeys('/'))->toBe([]);
});
