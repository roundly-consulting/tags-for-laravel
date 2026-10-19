<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagTypeException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagSlugs;

test('a_string_resolves_by_slug_along_the_locale_chain', function (): void {
    $rock = Tags::type('tag')->make(['en' => 'Rock', 'sk' => 'Skala'])->create();

    app()->setLocale('sk');

    expect(Tags::type('tag')->find('skala')?->is($rock))->toBeTrue()
        // sk has no "rock" slug, so the chain falls back to en.
        ->and(Tags::type('tag')->find('Rock')?->is($rock))->toBeTrue()
        ->and(Tags::type('tag')->find('nothing'))->toBeNull();
});

test('case_and_punctuation_variants_are_one_tag', function (): void {
    $rock = Tags::type('tag')->findOrCreate('Rock');

    foreach (['rock', 'ROCK', 'Rock!', '  rock  ', 'rock.'] as $variant) {
        expect(Tags::type('tag')->findOrCreate($variant)->is($rock))->toBeTrue($variant);
    }

    expect(Tag::query()->count())->toBe(1)
        ->and(Tags::type('tag')->find('rock')?->is($rock))->toBeTrue();
});

test('identity_uses_each_locales_transliteration', function (): void {
    config()->set('translatable.locales', ['en', 'sk', 'de']);

    expect(TagSlugs::slugify('Über', 'de'))->toBe('ueber')
        ->and(TagSlugs::slugify('Über', 'en'))->toBe('uber');

    app()->setLocale('de');
    $tag = Tags::type('tag')->findOrCreate('Über');

    expect($tag->slugFor('de'))->toBe('ueber')
        ->and(Tags::type('tag')->find('über')?->is($tag))->toBeTrue()
        ->and(Tags::type('tag')->findOrCreate('ÜBER')->is($tag))->toBeTrue()
        ->and(Tag::query()->count())->toBe(1);
});

test('same_slug_in_two_locales_resolves_by_chain', function (): void {
    // "gift" is English for a present and German for poison: two tags, one slug, two locales.
    config()->set('translatable.locales', ['en', 'sk', 'de']);
    $present = Tags::type('tag')->make(['en' => 'Gift'])->create();
    $poison = Tags::type('tag')->make(['de' => 'Gift', 'en' => 'Poison'])->create();

    expect(Tags::type('tag')->find('gift')?->is($present))->toBeTrue();

    app()->setLocale('de');

    expect(Tags::type('tag')->find('gift')?->is($poison))->toBeTrue();
});

test('find_or_create_with_a_locale_map_matches_one_locale', function (): void {
    $music = Tags::type('tag')->findOrCreate(['en' => 'Music', 'sk' => 'Hudba']);
    $again = Tags::type('tag')->findOrCreate(['en' => 'music', 'sk' => 'Something else']);

    app()->setLocale('sk');
    $bySk = Tags::type('tag')->findOrCreate(['en' => 'Other', 'sk' => 'Hudba']);

    expect($again->is($music))->toBeTrue()
        ->and($again->getTranslation('name', 'sk'))->toBe('Hudba')
        ->and($bySk->is($music))->toBeTrue()
        // A new tag whose other locale's slug is taken: a duplicate naming that locale.
        ->and(fn () => Tags::type('tag')->findOrCreate(['sk' => 'Nová', 'en' => 'Music']))
        ->toThrow(DuplicateTagException::class, '[music] v jazyku [en]');
});

test('slug_dictionary_keeps_c_cpp_and_csharp_apart', function (): void {
    $c = Tags::type('tag')->findOrCreate('C');
    $cpp = Tags::type('tag')->findOrCreate('C++');
    $csharp = Tags::type('tag')->findOrCreate('C#');

    expect([$c->slug, $cpp->slug, $csharp->slug])->toBe(['c', 'c-plus-plus', 'c-sharp'])
        ->and(Tag::query()->count())->toBe(3);
});

test('taken_slug_is_a_duplicate_never_a_suffix', function (): void {
    Tags::type('tag')->make('Rock')->create();

    expect(fn () => Tags::type('tag')->make('rock!')->create())->toThrow(DuplicateTagException::class, '[rock] in [en]')
        ->and(Tag::query()->get()->pluck('slug')->all())->toBe(['rock']);
});

test('rename_onto_a_taken_slug_is_refused', function (): void {
    Tags::type('tag')->make('Rock')->create();
    $pop = Tags::type('tag')->make('Pop')->create();

    expect(fn () => Tags::type('tag')->rename($pop, 'ROCK'))->toThrow(DuplicateTagException::class, '[rock] in [en]')
        ->and($pop->fresh()?->name)->toBe('Pop');
});

test('rename_onto_a_trashed_tags_slug_points_to_restore', function (): void {
    $rock = Tags::type('tag')->make('Rock')->create();
    $pop = Tags::type('tag')->make('Pop')->create();
    Tags::type('tag')->delete($rock);

    expect(fn () => Tags::type('tag')->rename($pop, 'Rock'))->toThrow(DuplicateTagException::class, 'restore that tag instead');
});

test('slug_follows_the_name_per_locale', function (): void {
    $tag = Tags::type('tag')->make(['en' => 'Rock', 'sk' => 'Skala'])->create();

    app()->setLocale('sk');
    $renamed = Tags::type('tag')->rename($tag, 'Kameň');

    expect($renamed->slugFor('sk'))->toBe('kamen')
        ->and($renamed->slugFor('en'))->toBe('rock')
        ->and($renamed->getTranslation('name', 'en'))->toBe('Rock');
});

test('stable_slugs_never_change', function (): void {
    Tags::define('genre')->hierarchical()->stableSlugs();
    $jazz = Tags::type('genre')->make('Jazz')->create();

    $renamed = Tags::type('genre')->rename($jazz, 'Jazz music');

    expect($renamed->name)->toBe('Jazz music')
        ->and($renamed->slug)->toBe('jazz')
        // The slug still names it; so does its new name's slug only once a manual slug says so.
        ->and(Tags::type('genre')->find('jazz')?->is($jazz))->toBeTrue();
});

test('stable_slugs_require_a_curated_type', function (): void {
    Tags::define('label')->freeForm()->stableSlugs();

    expect(fn () => Tags::type('label'))->toThrow(InvalidTagTypeException::class, 'stable slugs on a free-form type');
});

it('takes manual slugs on curated types only', function (): void {
    Tags::define('genre')->hierarchical();

    $jazz = Tags::type('genre')->make('Jazz music')->slug('jazz')->create();

    expect($jazz->slug)->toBe('jazz')
        ->and(fn () => Tags::type('tag')->make('Rock')->slug('rock')->create())->toThrow(InvalidTagException::class, 'only curated types')
        ->and(fn () => Tags::type('genre')->make('Blues')->slug('Not A Slug')->create())->toThrow(InvalidTagException::class, 'lowercase letters')
        ->and(fn () => Tags::type('genre')->make('Bebop')->slug('jazz')->create())->toThrow(DuplicateTagException::class, '[jazz]')
        ->and(Tags::type('genre')->update($jazz, new UpdateTagData(slug: 'jazz-music'))->slug)->toBe('jazz-music');
});

test('untranslated_locales_resolve_through_the_chain', function (): void {
    $tag = Tags::type('tag')->make(['en' => 'Rock'])->create();

    app()->setLocale('sk');

    expect($tag->slugFor('sk'))->toBeNull()
        ->and($tag->getTranslations('slug'))->toBe(['en' => 'rock'])
        ->and(Tags::type('tag')->find('rock')?->is($tag))->toBeTrue()
        ->and($tag->name)->toBe('Rock');
});

test('trashed_tag_keeps_its_slug_and_comes_back_on_recreate', function (): void {
    $rock = Tags::type('tag')->make('Rock')->color('#000000')->create();
    Tags::type('tag')->delete($rock);

    expect(Tags::type('tag')->find('rock'))->toBeNull()
        ->and(Tag::query()->withTrashed()->find($rock->id)?->slug)->toBe('rock');

    $again = Tags::type('tag')->create(new TagData(name: 'Rock', color: '#ffffff'));

    expect($again->is($rock))->toBeTrue()
        ->and($again->trashed())->toBeFalse()
        ->and($again->color)->toBe('#ffffff')
        ->and(Tag::query()->withTrashed()->count())->toBe(1)
        ->and(Tags::type('tag')->findOrCreate('Rock')->is($rock))->toBeTrue();
});

it('restores a trashed tag on find-or-create', function (): void {
    $rock = Tags::type('tag')->findOrCreate('Rock');
    Tags::type('tag')->delete($rock);

    expect(Tags::type('tag')->findOrCreate('rock')->is($rock))->toBeTrue()
        ->and($rock->fresh()?->trashed())->toBeFalse();
});

it('refuses a create whose locales are held by two deleted tags', function (): void {
    $rock = Tags::type('tag')->make(['en' => 'Rock'])->create();
    $skala = Tags::type('tag')->make(['sk' => 'Skala'])->create();
    Tags::type('tag')->delete($rock);
    Tags::type('tag')->delete($skala);

    expect(fn () => Tags::type('tag')->make(['en' => 'Rock', 'sk' => 'Skala'])->create())
        ->toThrow(DuplicateTagException::class, 'restore that tag instead');
});
