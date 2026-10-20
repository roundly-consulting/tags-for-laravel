<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Support\TagAttributes;
use RoundlyConsulting\Tags\Support\TagName;

test('names_are_normalised', function (string $input, string $stored): void {
    $tag = Tags::type('tag')->make($input)->create();

    expect($tag->name)->toBe($stored);
})->with([
    'trimmed' => ['  Rock  ', 'Rock'],
    'inner whitespace collapsed' => ["Late \t\n  night", 'Late night'],
    'control characters stripped' => ["Jazz\u{0007}Fusion", 'Jazz Fusion'],
    'zero-width characters removed' => ["Zero\u{200B}width", 'Zerowidth'],
]);

test('blank_or_unsluggable_names_are_refused', function (string $name, string $message): void {
    expect(fn () => Tags::type('tag')->make($name)->create())->toThrow(InvalidTagException::class, $message);
})->with([
    'empty' => ['', 'needs a name'],
    'whitespace' => ["  \n ", 'needs a name'],
    'punctuation only' => ['!!!', 'no letters or digits'],
    'too long' => [str_repeat('a', 65), 'at most 64'],
]);

test('name_maps_need_one_supported_locale', function (): void {
    expect(fn () => Tags::type('tag')->make(['de' => 'Rock', 'fr' => 'Roche'])->create())->toThrow(InvalidTagException::class, 'needs a name')
        ->and(fn () => Tags::type('tag')->make(['en' => ' ', 'sk' => ''])->create())->toThrow(InvalidTagException::class, 'needs a name');

    $tag = Tags::type('tag')->make(['en' => 'Rock', 'de' => 'Fels', 'sk' => 'Skala'])->create();

    expect($tag->getTranslations('name'))->toBe(['en' => 'Rock', 'sk' => 'Skala']);
});

test('a_string_in_an_unsupported_app_locale_is_refused_clearly', function (): void {
    app()->setLocale('de');

    expect(fn () => Tags::type('tag')->make('Rock')->create())
        ->toThrow(InvalidTagException::class, 'current locale [de] is not a supported locale');
});

test('unicode_slugs_switch_admits_non_ascii_names', function (): void {
    expect(fn () => Tags::type('tag')->make('音楽')->create())->toThrow(InvalidTagException::class, 'no letters or digits');

    config()->set('tags.slugs.unicode', true);

    $music = Tags::type('tag')->make('音楽')->create();
    $russian = Tags::type('tag')->make('Музыка')->create();

    expect($music->slug)->toBe('音楽')
        ->and($russian->slug)->toBe('музыка')
        ->and(Tags::type('tag')->find('音楽')?->is($music))->toBeTrue()
        ->and(Tags::type('tag')->find('МУЗЫКА')?->is($russian))->toBeTrue();
});

test('descriptions_are_validated', function (): void {
    $tag = Tags::type('tag')->make('Rock')->description(['en' => '  Loud guitars.  ', 'sk' => "Hlasné\ngitary."])->create();

    expect($tag->getTranslations('description'))->toBe(['en' => 'Loud guitars.', 'sk' => "Hlasné\ngitary."])
        ->and(fn () => TagAttributes::description(str_repeat('a', 501)))->toThrow(InvalidTagException::class, 'at most 500')
        ->and(fn () => TagAttributes::description('<b>bold</b>'))->toThrow(InvalidTagException::class, 'plain text')
        ->and(fn () => TagAttributes::description("bell\u{0007}"))->toThrow(InvalidTagException::class, 'plain text')
        ->and(fn () => TagAttributes::description(['en' => 5]))->toThrow(InvalidTagException::class, 'plain text')
        ->and(TagAttributes::description(['en' => ' ']))->toBe([])
        ->and(TagAttributes::description(null))->toBe([]);
});

// Plain text may hold a bare "<"; only something shaped like a tag is markup.
test('a_description_with_a_bare_less_than_is_plain_text', function (): void {
    expect(TagAttributes::description(['en' => 'I <3 jazz', 'sk' => 'x<y']))->toBe(['en' => 'I <3 jazz', 'sk' => 'x<y'])
        ->and(TagAttributes::description('a < b'))->toBe(['en' => 'a < b'])
        ->and(fn () => TagAttributes::description('<b>x</b>'))->toThrow(InvalidTagException::class, 'plain text')
        ->and(fn () => TagAttributes::description('x</b>'))->toThrow(InvalidTagException::class, 'plain text')
        ->and(fn () => TagAttributes::description('<!-- x -->'))->toThrow(InvalidTagException::class, 'plain text')
        ->and(fn () => TagAttributes::description('<?php x ?>'))->toThrow(InvalidTagException::class, 'plain text')
        ->and(fn () => TagAttributes::description('<img src=x onerror=alert(1)>'))->toThrow(InvalidTagException::class, 'plain text');
});

test('colors_and_icons_are_validated', function (): void {
    $tag = Tags::type('tag')->make('Rock')->color('#F24E29')->icon('🎸')->create();

    expect($tag->color)->toBe('#f24e29')
        ->and($tag->icon)->toBe('🎸')
        ->and(TagAttributes::icon('fa-guitar'))->toBe('fa-guitar')
        ->and(TagAttributes::color(null))->toBeNull();

    foreach (['f24e29', '#f24e2', '#f24e299', '#gggggg', 'red'] as $color) {
        expect(fn () => TagAttributes::color($color))->toThrow(InvalidTagException::class, 'hex colour');
    }

    foreach (['', 'two words', '<svg>', '"quoted"', "it's", str_repeat('a', 65), "tab\t"] as $icon) {
        expect(fn () => TagAttributes::icon($icon))->toThrow(InvalidTagException::class, 'identifier or an emoji');
    }

    expect(fn () => TagAttributes::position(-1))->toThrow(InvalidTagException::class, 'zero or more');
});

test('malformed_locales_are_refused', function (): void {
    expect(fn () => TagName::map(["en'); drop table tags; --" => 'Rock']))->toThrow(InvalidTagException::class, 'malformed locale')
        ->and(fn () => TagAttributes::description(['EN US' => 'x']))->toThrow(InvalidTagException::class, 'malformed locale')
        ->and(fn () => TagAttributes::slug(['en"' => 'rock']))->toThrow(InvalidTagException::class, 'malformed locale');
});
