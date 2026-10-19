<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagTypeException;
use RoundlyConsulting\Tags\Exceptions\ReadOnlyTagsRelationException;
use RoundlyConsulting\Tags\Exceptions\TagHasChildrenException;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagsException;
use RoundlyConsulting\Tags\Exceptions\TagTypeAlreadyDefinedException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeNotAllowedException;
use RoundlyConsulting\Tags\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Exceptions\UnsavedTaggableException;
use RoundlyConsulting\Tags\Http\Controllers\Controller;

/**
 * Every factory of every exception, with representative arguments.
 *
 * @return array<string, array{0: Closure(): Throwable}>
 */
function everyTagException(): array
{
    return [
        'unknown type' => [fn () => UnknownTagTypeException::named('events:label')],
        'unknown malformed type' => [fn () => UnknownTagTypeException::named('App\\Genre')],
        'type required' => [fn () => UnknownTagTypeException::typeRequired()],
        'not found key' => [fn () => TagNotFoundException::forKey(5)],
        'not found name' => [fn () => TagNotFoundException::forName('Polka', 'genre')],
        'trashed' => [fn () => TagNotFoundException::trashed()],
        'not found' => [fn () => TagNotFoundException::generic()],
        'scope mismatch' => [fn () => TagScopeMismatchException::forTag('fax')],
        'scope mismatch write' => [fn () => TagScopeMismatchException::forWrite('fax')],
        'duplicate' => [fn () => DuplicateTagException::forSlug('rock')],
        'duplicate locale' => [fn () => DuplicateTagException::forSlug('rock', 'en')],
        'duplicate trashed' => [fn () => DuplicateTagException::heldByTrashed('rock', 'en')],
        'duplicate generic' => [fn () => DuplicateTagException::generic()],
        'blank name' => [fn () => InvalidTagException::blankName()],
        'name too long' => [fn () => InvalidTagException::nameTooLong(64)],
        'unsluggable' => [fn () => InvalidTagException::unsluggable()],
        'unsupported locale' => [fn () => InvalidTagException::unsupportedLocale('de')],
        'invalid locale' => [fn () => InvalidTagException::invalidLocale()],
        'color' => [fn () => InvalidTagException::invalidColor()],
        'icon' => [fn () => InvalidTagException::invalidIcon()],
        'description too long' => [fn () => InvalidTagException::descriptionTooLong(500)],
        'description' => [fn () => InvalidTagException::invalidDescription()],
        'slug' => [fn () => InvalidTagException::invalidSlug()],
        'manual slug' => [fn () => InvalidTagException::manualSlugOnFreeForm('label')],
        'position' => [fn () => InvalidTagException::invalidPosition()],
        'global only' => [fn () => InvalidTagException::globalOnlyType('country')],
        'immutable' => [fn () => InvalidTagException::immutable('type')],
        'limit one' => [fn () => TagLimitExceededException::forType('genre', 1)],
        'limit many' => [fn () => TagLimitExceededException::forType('label', 5)],
        'not allowed' => [fn () => TagTypeNotAllowedException::onModel('genre', 'Article')],
        'type mismatch' => [fn () => TagTypeMismatchException::expected('genre', 'label')],
        'cycle' => [fn () => InvalidTagHierarchyException::cycle()],
        'too deep' => [fn () => InvalidTagHierarchyException::tooDeep(3)],
        'flat type' => [fn () => InvalidTagHierarchyException::flatType('label')],
        'parent trashed' => [fn () => InvalidTagHierarchyException::parentTrashed()],
        'has children' => [fn () => TagHasChildrenException::forTag('jazz')],
        'unknown alias' => [fn () => UnknownMorphAliasException::alias('scope')],
        'alias missing' => [fn () => UnknownMorphAliasException::missing('scope')],
        'unsaved' => [fn () => UnsavedTaggableException::make('Article')],
        'defined twice' => [fn () => TagTypeAlreadyDefinedException::twice('genre', 'EventsServiceProvider:42', 'config/tags.php')],
        'invalid type name' => [fn () => InvalidTagTypeException::invalidName()],
        'frozen' => [fn () => InvalidTagTypeException::frozen('genre')],
        'type rule' => [fn () => InvalidTagTypeException::rule('genre', 'max below 1')],
        'key type' => [fn () => InvalidTagsConfigurationException::keyTypeMismatch('Article')],
        'config' => [fn () => InvalidTagsConfigurationException::invalid('tags.tables.tags', 'a table name')],
        'read only attach' => [fn () => ReadOnlyTagsRelationException::for('attach')],
        'read only create' => [fn () => ReadOnlyTagsRelationException::for('create')],
        'read only pivot' => [fn () => ReadOnlyTagsRelationException::for('update_existing_pivot')],
    ];
}

test('exception_messages_name_no_classes', function (Closure $make): void {
    foreach (['en', 'sk'] as $locale) {
        app()->setLocale($locale);
        $message = $make()->getMessage();

        expect($message)->not->toContain('\\')->not->toStartWith('tags::')->not->toBe('');
    }
})->with(everyTagException());

test('every_exception_exposes_its_code_status_and_family', function (Closure $make, string $code, int $status, bool $notFound): void {
    $exception = $make();

    expect($exception)->toBeInstanceOf(TagsException::class)
        ->and($exception->errorCode())->toBe($code)
        ->and($exception->status())->toBe($status)
        ->and($exception->isNotFound())->toBe($notFound);
})->with([
    [fn () => UnknownTagTypeException::named('x'), 'unknown_tag_type', 422, true],
    [fn () => UnknownTagTypeException::typeRequired(), 'type_required', 422, false],
    [fn () => TagNotFoundException::generic(), 'tag_not_found', 422, true],
    [fn () => TagScopeMismatchException::forTag('x'), 'tag_not_found', 422, true],
    [fn () => DuplicateTagException::generic(), 'duplicate_tag', 409, false],
    [fn () => InvalidTagException::blankName(), 'invalid_tag', 422, false],
    [fn () => TagLimitExceededException::forType('x', 1), 'tag_limit_exceeded', 422, false],
    [fn () => TagTypeNotAllowedException::onModel('x', 'Y'), 'tag_type_not_allowed', 422, false],
    [fn () => TagTypeMismatchException::expected('x', 'y'), 'tag_type_mismatch', 422, false],
    [fn () => InvalidTagHierarchyException::cycle(), 'invalid_hierarchy', 422, false],
    [fn () => TagHasChildrenException::forTag('x'), 'tag_has_children', 409, false],
    [fn () => UnknownMorphAliasException::alias('scope'), 'unknown_alias', 422, false],
    [fn () => UnsavedTaggableException::make('X'), 'unsaved_taggable', 500, false],
    [fn () => TagTypeAlreadyDefinedException::twice('x', 'a', 'b'), 'type_already_defined', 500, false],
    [fn () => InvalidTagTypeException::invalidName(), 'invalid_tag_type', 500, false],
]);

test('the_config_and_relation_exceptions_stay_outside_the_family', function (): void {
    expect(InvalidTagsConfigurationException::invalid('x', 'y'))->not->toBeInstanceOf(TagsException::class)
        ->and(ReadOnlyTagsRelationException::for('attach'))->toBeInstanceOf(LogicException::class)
        ->and(ReadOnlyTagsRelationException::for('attach'))->not->toBeInstanceOf(TagsException::class);
});

test('scope_mismatch_presents_as_tag_not_found', function (): void {
    $mismatch = TagScopeMismatchException::forTag('fax');
    $missing = TagNotFoundException::generic();

    expect($mismatch->errorCode())->toBe($missing->errorCode())
        ->and($mismatch->clientMessage())->toBe($missing->clientMessage())
        ->and($mismatch->getMessage())->toContain('fax')->not->toBe($missing->getMessage());
});

test('the_api_maps_exceptions_only_through_the_base_methods', function (): void {
    $exception = new class('internal detail') extends TagsException
    {
        public function errorCode(): string
        {
            return 'custom_code';
        }

        public function status(): int
        {
            return 418;
        }

        public function clientMessage(): string
        {
            return 'What a client may see.';
        }
    };

    $response = Controller::error($exception, $exception->status());

    expect($response->getStatusCode())->toBe(418)
        ->and($response->getData(true))->toBe(['message' => 'What a client may see.', 'code' => 'custom_code']);

    // The controller source maps nothing by class: no exception class names in its catch list
    // other than the base and the internal path wrapper.
    $source = (string) file_get_contents(__DIR__.'/../../src/Http/Controllers/Controller.php');
    preg_match_all('/catch \(([^)]+)\)/', $source, $matches);

    expect($matches[1])->toBe(['PathNotFound $notFound', 'TagsException $exception']);
});
