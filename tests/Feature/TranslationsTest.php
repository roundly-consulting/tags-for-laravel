<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * English and Slovak ship the same keys and placeholders in every file, and the Slovak lines
 * resolve through the package namespace.
 */
$files = ['messages', 'validation', 'types', 'enums'];

/**
 * @return array<string, string>
 */
function tagLines(string $locale, string $file): array
{
    /** @var array<string, mixed> $lines */
    $lines = require __DIR__.'/../../resources/lang/'.$locale.'/'.$file.'.php';

    return array_map(strval(...), Arr::dot($lines));
}

/**
 * @return list<string>
 */
function tagPlaceholders(string $line): array
{
    preg_match_all('/:([a-z_]+)/', $line, $matches);
    $placeholders = array_values(array_unique($matches[1]));
    sort($placeholders);

    return $placeholders;
}

it('ships the same keys in english and slovak', function (string $file): void {
    expect(array_keys(tagLines('sk', $file)))->toBe(array_keys(tagLines('en', $file)));
})->with($files);

it('ships the same placeholders per key in english and slovak', function (string $file): void {
    $sk = tagLines('sk', $file);

    foreach (tagLines('en', $file) as $key => $line) {
        expect(tagPlaceholders($sk[$key]))->toBe(tagPlaceholders($line), "{$file}.{$key}");
    }
})->with($files);

it('resolves slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('tags::types.tag'))->toBe('Štítok')
        ->and(__('tags::messages.tag_not_found'))->toBe('Štítok neexistuje.');
});

it('pluralises counted lines for 0, 1, 3 and 5', function (string $locale, int $count, string $expected): void {
    app()->setLocale($locale);

    expect(trans_choice('tags::messages.limit_exceeded', $count, ['type' => 'label', 'max' => $count]))->toContain($expected)
        ->and(trans_choice('tags::validation.max', $count, ['attribute' => 'labels', 'max' => $count]))->not->toStartWith(' ');
})->with([
    ['en', 0, 'at most 0 tags'],
    ['en', 1, 'only one tag'],
    ['en', 3, 'at most 3 tags'],
    ['en', 5, 'at most 5 tags'],
    ['sk', 0, 'najviac 0 štítkov'],
    ['sk', 1, 'len jeden štítok'],
    ['sk', 3, 'najviac 3 štítky'],
    ['sk', 5, 'najviac 5 štítkov'],
]);
