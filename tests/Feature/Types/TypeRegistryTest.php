<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\Tags\Enums\TypeSource;
use RoundlyConsulting\Tags\Exceptions\InvalidTagTypeException;
use RoundlyConsulting\Tags\Exceptions\TagTypeAlreadyDefinedException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;
use RoundlyConsulting\Tags\Rules\TagsRule;
use RoundlyConsulting\Tags\Tests\Fixtures\FixtureTypesServiceProvider;
use RoundlyConsulting\Tags\Tests\Fixtures\HostExtendServiceProvider;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ArticleTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\IntTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeBuilder;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

test('type_names_are_validated', function (string $name, bool $valid): void {
    expect(TagTypeRegistry::isValidName($name))->toBe($valid);

    if ($valid) {
        expect(Tags::define($name)->name)->toBe($name);
    } else {
        expect(fn () => Tags::define($name))->toThrow(InvalidTagTypeException::class);
    }
})->with([
    'bare' => ['genre', true],
    'with package prefix' => ['events:category', true],
    'digits, dash and underscore' => ['a1_b-c', true],
    'backslash' => ['App\\Genre', false],
    'dot' => ['events.category', false],
    'uppercase' => ['Genre', false],
    'two colons' => ['a:b:c', false],
    'leading digit' => ['1genre', false],
    'empty' => ['', false],
    'space' => ['my genre', false],
    '64 characters' => [str_repeat('a', 31).':'.str_repeat('b', 32), false],
    'longest allowed' => [str_repeat('a', 31).':'.str_repeat('b', 31), true],
]);

test('defining_a_type_twice_throws_naming_both_definers', function (): void {
    $this->app->register(FixtureTypesServiceProvider::class);

    try {
        Tags::define('fixture:label');
        $this->fail('A second definition must throw.');
    } catch (TagTypeAlreadyDefinedException $exception) {
        expect($exception->getMessage())
            ->toContain('fixture:label')
            ->toContain('FixtureTypesServiceProvider:')
            ->toContain('TypeRegistryTest')
            ->not->toContain('\\')
            ->and($exception->errorCode())->toBe('type_already_defined')
            ->and($exception->status())->toBe(500);
    }
});

test('a_config_type_colliding_with_a_code_type_throws', function (): void {
    config()->set('tags.types', ['skill' => ['hierarchical' => true]]);

    expect(fn () => Tags::define('skill'))->toThrow(TagTypeAlreadyDefinedException::class, 'config/tags.php');
});

it('throws at resolution when config defines a name code already defined', function (): void {
    Tags::define('skill');
    config()->set('tags.types', ['skill' => ['hierarchical' => true]]);

    expect(fn () => Tags::types())->toThrow(TagTypeAlreadyDefinedException::class);
});

test('extend_applies_regardless_of_provider_order', function (array $providers): void {
    foreach ($providers as $provider) {
        $this->app->register($provider);
    }

    $type = app(TagTypeRegistry::class)->resolve('fixture:label');

    expect($type->max)->toBe(3)
        ->and($type->on)->toBe([Article::class, Note::class])
        ->and($type->applied)->toBe(['extend #1']);
})->with([
    'consumer first' => [[FixtureTypesServiceProvider::class, HostExtendServiceProvider::class]],
    'host first' => [[HostExtendServiceProvider::class, FixtureTypesServiceProvider::class]],
]);

test('config_overrides_win_over_extend_and_code', function (): void {
    config()->set('tags.overrides', ['fixture:label' => ['max' => 7, 'curated' => true, 'on' => [Note::class]]]);
    $this->app->register(FixtureTypesServiceProvider::class);
    $this->app->register(HostExtendServiceProvider::class);

    $type = app(TagTypeRegistry::class)->resolve('fixture:label');

    expect($type->max)->toBe(7)
        ->and($type->curated)->toBeTrue()
        ->and($type->on)->toBe([Note::class])
        ->and($type->applied)->toBe(['extend #1', 'config overrides']);
});

test('an_override_of_an_unknown_type_is_reported', function (): void {
    config()->set('tags.overrides', ['lable' => ['max' => 2]]);
    Tags::extend('genere', static fn (TagTypeBuilder $type): TagTypeBuilder => $type->single());

    expect(fn () => Tags::type('lable'))->toThrow(UnknownTagTypeException::class)
        ->and(fn () => Tags::type('genere'))->toThrow(UnknownTagTypeException::class)
        ->and(app(TagTypeRegistry::class)->orphanAdjustments())->toBe(['genere', 'lable']);
});

test('default_type_is_implicit_unless_defined', function (): void {
    $implicit = app(TagTypeRegistry::class)->resolve('tag');

    expect($implicit->source)->toBe(TypeSource::Default)
        ->and($implicit->hierarchical)->toBeFalse()
        ->and($implicit->curated)->toBeFalse()
        ->and($implicit->max)->toBeNull()
        ->and($implicit->private)->toBeFalse()
        ->and($implicit->label())->toBe('Tag')
        ->and($implicit->label('sk'))->toBe('Štítok');

    // An explicit definition replaces the implicit one without a collision.
    Tags::define('tag')->curated()->max(4);

    $explicit = app(TagTypeRegistry::class)->resolve('tag');

    expect($explicit->source)->toBe(TypeSource::Code)
        ->and($explicit->curated)->toBeTrue()
        ->and($explicit->max)->toBe(4);
});

test('blank_default_type_requires_a_type_for_strings', function (?string $blank): void {
    config()->set('tags.default_type', $blank);

    expect(app(TagTypeRegistry::class)->defaultType())->toBeNull()
        ->and(app(TagTypeRegistry::class)->has('tag'))->toBeFalse()
        ->and(fn () => Tags::type('tag'))->toThrow(UnknownTagTypeException::class);
})->with(['empty' => [''], 'whitespace' => ['  '], 'null' => [null]]);

test('builders_freeze_after_resolution', function (): void {
    $builder = Tags::define('genre')->hierarchical();

    app(TagTypeRegistry::class)->resolve('genre');

    expect(fn () => $builder->single())->toThrow(InvalidTagTypeException::class, 'already in use')
        ->and(fn () => $builder->label('x'))->toThrow(InvalidTagTypeException::class);
});

test('re_resolution_applies_each_extend_once', function (): void {
    Tags::define('label')->on(Article::class);
    Tags::extend('label', static fn (TagTypeBuilder $type): TagTypeBuilder => $type->on(Note::class));

    $first = app(TagTypeRegistry::class)->resolve('label');

    // A later extend invalidates the cached type; the first extend still applies once.
    Tags::extend('label', static fn (TagTypeBuilder $type): TagTypeBuilder => $type->max(2));

    $second = app(TagTypeRegistry::class)->resolve('label');

    expect($first->on)->toBe([Article::class, Note::class])
        ->and($first->max)->toBeNull()
        ->and($second->on)->toBe([Article::class, Note::class])
        ->and($second->max)->toBe(2)
        ->and($second->applied)->toBe(['extend #1', 'extend #2']);
});

test('defining_and_resolving_types_runs_no_queries', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->app->register(FixtureTypesServiceProvider::class);
    Tags::extend('fixture:label', static fn (TagTypeBuilder $type): TagTypeBuilder => $type->max(2));
    Tags::types();
    app(TagTypeRegistry::class)->resolve('fixture:category')->label('sk');

    expect(DB::getQueryLog())->toBe([]);
});

test('invalid_type_rules_throw_at_resolution', function (Closure $define, string $rule): void {
    $define();

    expect(fn () => app(TagTypeRegistry::class)->resolve('broken'))->toThrow(InvalidTagTypeException::class, $rule);
})->with([
    'max depth on a flat type' => [function (): void {
        config()->set('tags.types', ['broken' => ['hierarchical' => false, 'max_depth' => 2]]);
    }, 'max depth on a flat type'],
    'max depth below 1' => [fn () => Tags::define('broken')->hierarchical(0), 'max depth below 1'],
    'max below 1' => [fn () => Tags::define('broken')->max(0), 'max below 1'],
    'stable slugs on a free-form type' => [fn () => Tags::define('broken')->freeForm()->stableSlugs(), 'stable slugs on a free-form type'],
    'an on class that is no taggable model' => [fn () => Tags::define('broken')->on(Team::class), 'not a taggable model'],
    'a malformed label locale' => [fn () => Tags::define('broken')->label(['EN US' => 'x']), 'label locale keys must be locales'],
    'an unknown override key' => [function (): void {
        Tags::define('broken');
        config()->set('tags.overrides', ['broken' => ['colour' => 'red']]);
    }, 'unknown rule colour'],
    'a non-boolean flag' => [function (): void {
        config()->set('tags.types', ['broken' => ['curated' => 'sometimes']]);
    }, 'curated must be a boolean'],
    'a non-integer max' => [function (): void {
        config()->set('tags.types', ['broken' => ['max' => 'five']]);
    }, 'max must be an integer or null'],
    'a non-map config entry' => [function (): void {
        config()->set('tags.types', ['broken' => 'flat']);
    }, 'must be a map of rules'],
    'a non-map override entry' => [function (): void {
        Tags::define('broken');
        config()->set('tags.overrides', ['broken' => true]);
    }, 'must be a map of rules'],
    'a bad label value' => [function (): void {
        config()->set('tags.types', ['broken' => ['label' => 5]]);
    }, 'label must be a string or a locale map'],
    'a bad on value' => [function (): void {
        config()->set('tags.types', ['broken' => ['on' => 5]]);
    }, 'on must be a list of model classes'],
    'a missing on class' => [function (): void {
        config()->set('tags.types', ['broken' => ['on' => ['Nope\\Missing']]]);
    }, 'on must be a list of model classes'],
]);

it('reads config types with every rule key', function (): void {
    config()->set('tags.types', ['skill' => [
        'label' => ['en' => 'Skill', 'sk' => 'Zručnosť'],
        'hierarchical' => 'yes',
        'max_depth' => '2',
        'max' => 3,
        'curated' => true,
        'stable_slugs' => 'on',
        'global_only' => 1,
        'on' => Article::class,
        'private' => false,
    ]]);

    $type = app(TagTypeRegistry::class)->resolve('skill');

    expect($type->source)->toBe(TypeSource::Config)
        ->and($type->definer)->toBe('config/tags.php')
        ->and($type->hierarchical)->toBeTrue()
        ->and($type->maxDepth)->toBe(2)
        ->and($type->max)->toBe(3)
        ->and($type->stableSlugs)->toBeTrue()
        ->and($type->globalOnly)->toBeTrue()
        ->and($type->on)->toBe([Article::class])
        ->and($type->private)->toBeFalse()
        ->and($type->label('sk'))->toBe('Zručnosť')
        ->and($type->applied)->toBe(['config']);
});

it('turns a tree into a flat type through an override', function (): void {
    Tags::define('genre')->hierarchical(maxDepth: 3);
    config()->set('tags.overrides', ['genre' => ['hierarchical' => false]]);

    $type = app(TagTypeRegistry::class)->resolve('genre');

    expect($type->hierarchical)->toBeFalse()->and($type->maxDepth)->toBeNull();
});

test('label_maps_drop_unsupported_locales', function (): void {
    $this->app->register(FixtureTypesServiceProvider::class);

    $type = app(TagTypeRegistry::class)->resolve('fixture:label');

    expect($type->label)->toBe(['en' => 'Label', 'sk' => 'Označenie'])
        ->and($type->label('de'))->toBe('Label');
});

it('falls back to the headline when no label locale is supported', function (): void {
    Tags::define('events:age_group')->label(['de' => 'Altersgruppe']);

    expect(app(TagTypeRegistry::class)->resolve('events:age_group')->label())->toBe('Age Group');
});

test('max_depth_above_the_cap_is_clamped', function (): void {
    config()->set('tags.hierarchy.max_depth', 2);
    $this->app->register(FixtureTypesServiceProvider::class);
    Tags::define('genre')->hierarchical();

    $category = app(TagTypeRegistry::class)->resolve('fixture:category');
    $genre = app(TagTypeRegistry::class)->resolve('genre');

    expect($category->maxDepth)->toBe(2)
        ->and($category->declaredMaxDepth)->toBe(3)
        ->and($category->isClamped())->toBeTrue()
        ->and($genre->maxDepth)->toBe(2)
        ->and($genre->isClamped())->toBeFalse();
});

test('labels_translate_on_read_per_locale', function (): void {
    $this->app->register(FixtureTypesServiceProvider::class);
    $this->app['translator']->addLines(['tags.category' => 'Category'], 'en', 'fixture');
    $this->app['translator']->addLines(['tags.category' => 'Kategória'], 'sk', 'fixture');
    Tags::define('fixture:keyed')->label('fixture::tags.category');

    app()->setLocale('en');
    $resolved = app(TagTypeRegistry::class)->resolve('fixture:keyed');

    // The resolved type is cached on the singleton registry; the locale is read per call, as
    // a second request on the same app instance (Octane, a queue worker) would.
    expect($resolved->label())->toBe('Category');

    app()->setLocale('sk');

    expect(app(TagTypeRegistry::class)->resolve('fixture:keyed'))->toBe($resolved)
        ->and($resolved->label())->toBe('Kategória')
        ->and(app(TagTypeRegistry::class)->resolve('fixture:label')->label())->toBe('Označenie')
        ->and($resolved->label('en'))->toBe('Category');
});

test('unknown_types_throw_everywhere', function (Closure $use): void {
    expect($use)->toThrow(UnknownTagTypeException::class);
})->with([
    'registry' => [fn () => app(TagTypeRegistry::class)->resolve('nope')],
    'type handle' => [fn () => Tags::type('nope')],
    'malformed name' => [fn () => Tags::type('No Pe')],
    'trait' => [fn () => Article::query()->create()->attachTags('x', 'nope')],
    'scope' => [fn () => Article::query()->withAnyTags('x', 'nope')->get()],
    'rule' => [fn () => Validator::make(['t' => ['x']], ['t' => [TagsRule::for('nope')]])->passes()],
    'filter' => [fn () => TagsFilter::any('nope')->apply(Article::query(), 'x', 'tags')],
]);

test('enum_type_names_are_accepted_everywhere', function (): void {
    Tags::define(ArticleTagType::Genre)->hierarchical();
    Tags::extend(ArticleTagType::Genre, static fn (TagTypeBuilder $type): TagTypeBuilder => $type->single());

    expect(app(TagTypeRegistry::class)->resolve(ArticleTagType::Genre)->name)->toBe('genre')
        ->and(app(TagTypeRegistry::class)->resolve(ArticleTagType::Genre)->max)->toBe(1)
        ->and(Tags::type(ArticleTagType::Genre)->definition()->name)->toBe('genre')
        ->and(fn () => Tags::define(IntTagType::One))->toThrow(InvalidTagTypeException::class);
});

it('lists every type resolved and keyed by name', function (): void {
    Tags::define('genre')->hierarchical();
    config()->set('tags.types', ['skill' => []]);

    $types = Tags::types();

    expect($types->keys()->all())->toBe(['genre', 'skill', 'tag'])
        ->and($types->every(fn (mixed $type): bool => $type instanceof TagType))->toBeTrue()
        ->and(app(TagTypeRegistry::class)->find(null))->toBeNull()
        ->and(app(TagTypeRegistry::class)->find('nope'))->toBeNull()
        ->and(app(TagTypeRegistry::class)->find('genre')?->name)->toBe('genre');
});

it('keeps every builder rule chainable', function (): void {
    $type = Tags::define('kind')
        ->label('Kind')
        ->hierarchical(4)
        ->flat()
        ->max(3)
        ->unlimited()
        ->freeForm()
        ->curated(false)
        ->globalOnly()
        ->private()
        ->onlyOn(Note::class);

    $resolved = app(TagTypeRegistry::class)->resolve('kind');

    expect($type)->toBeInstanceOf(TagTypeBuilder::class)
        ->and($resolved->hierarchical)->toBeFalse()
        ->and($resolved->max)->toBeNull()
        ->and($resolved->curated)->toBeFalse()
        ->and($resolved->globalOnly)->toBeTrue()
        ->and($resolved->private)->toBeTrue()
        ->and($resolved->on)->toBe([Note::class])
        ->and($resolved->isSingle())->toBeFalse();
});
