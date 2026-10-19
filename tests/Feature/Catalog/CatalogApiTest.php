<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tags\Actions\Catalog\CreateTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\DeleteTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\FindOrCreateTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\RestoreTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\UpdateTagAction;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Events\TagCreated;
use RoundlyConsulting\Tags\Events\TagDeleted;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Events\TagUpdated;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ArticleTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

it('creates a tag through the facade, dependency injection and the raw action alike', function (): void {
    Tags::define(ArticleTagType::Label);

    $facade = Tags::type(ArticleTagType::Label)->create(new TagData(name: 'Live'));
    $injected = app(TagsManager::class)->type('label')->create(new TagData(name: 'Outdoor'));
    $action = app(CreateTagAction::class)->execute(ArticleTagType::Label, new TagData(name: ['en' => 'Free', 'sk' => 'Zadarmo']));

    expect([$facade->slug, $injected->slug, $action->slug])->toBe(['live', 'outdoor', 'free'])
        ->and($action->type)->toBe('label')
        ->and(app(FindOrCreateTagAction::class)->execute('label', 'live')->is($facade))->toBeTrue()
        ->and(app(UpdateTagAction::class)->execute($facade, new UpdateTagData(color: '#123456'))->color)->toBe('#123456')
        ->and(app(DeleteTagAction::class)->execute($facade)->trashed())->toBeTrue()
        ->and(app(RestoreTagAction::class)->execute($facade)->trashed())->toBeFalse();
});

test('builders_match_the_dto_path', function (): void {
    Tags::define('genre')->hierarchical();
    $music = Tags::type('genre')->make('Music')->create();

    $built = Tags::type('genre')->make(['en' => 'Jazz', 'sk' => 'Džez'])
        ->description('Swing and more.')
        ->slug('jazz')
        ->color('#F24E29')
        ->icon('sax')
        ->under($music)
        ->position(0)
        ->create();

    $viaDto = Tags::type('genre')->create(new TagData(
        name: ['en' => 'Blues', 'sk' => 'Blues'],
        description: 'Twelve bars.',
        slug: 'blues',
        color: '#F24E29',
        icon: 'harmonica',
        parent: $music,
        position: 0,
    ));

    expect($built->only(['color', 'parent_id', 'depth', 'type', 'scope_key']))->toBe($viaDto->only(['color', 'parent_id', 'depth', 'type', 'scope_key']))
        ->and($built->icon)->toBe('sax')
        ->and($built->color)->toBe('#f24e29')
        ->and($built->getTranslation('name', 'sk'))->toBe('Džez')
        ->and($built->description)->toBe('Swing and more.')
        ->and($built->fresh()?->position)->toBe(1)
        ->and($viaDto->position)->toBe(0);
});

test('update_distinguishes_not_given_from_null', function (): void {
    $tag = Tags::type('tag')->make('Rock')->description('Loud.')->color('#000000')->icon('guitar')->create();

    $untouched = Tags::type('tag')->update($tag, new UpdateTagData);
    $cleared = Tags::type('tag')->update($tag, new UpdateTagData(description: null, color: null, icon: null));

    expect($untouched->color)->toBe('#000000')
        ->and($untouched->description)->toBe('Loud.')
        ->and($cleared->color)->toBeNull()
        ->and($cleared->icon)->toBeNull()
        ->and($cleared->getTranslations('description'))->toBe([]);
});

// A blank (or null) entry clears that locale's description only; `null` for the whole field
// clears every locale; an empty map changes nothing.
test('clearing_one_locales_description_keeps_the_others', function (): void {
    $tags = Tags::type('tag');
    $tag = $tags->make('Rock')->description(['en' => 'Loud.', 'sk' => 'Hlasné.'])->create();

    expect($tags->update($tag, new UpdateTagData(description: ['en' => '']))->getTranslations('description'))->toBe(['sk' => 'Hlasné.'])
        ->and($tags->update($tag, new UpdateTagData(description: ['en' => 'Loud again.', 'sk' => null]))->getTranslations('description'))->toBe(['en' => 'Loud again.'])
        ->and($tags->update($tag, new UpdateTagData(description: []))->getTranslations('description'))->toBe(['en' => 'Loud again.'])
        ->and($tags->update($tag, new UpdateTagData(description: ['sk' => 'Hlasné.']))->getTranslations('description'))->toBe(['en' => 'Loud again.', 'sk' => 'Hlasné.']);

    app()->setLocale('sk');

    expect($tags->update($tag, new UpdateTagData(description: '  '))->getTranslations('description'))->toBe(['en' => 'Loud again.'])
        ->and($tags->update($tag, new UpdateTagData(description: null))->getTranslations('description'))->toBe([]);
});

it('edits a tag fluently', function (): void {
    $tag = Tags::type('tag')->make(['en' => 'Rock', 'sk' => 'Skala'])->create();

    $edited = Tags::type('tag')->edit($tag)
        ->name('Rock music')
        ->description(['en' => 'Guitars.'])
        ->color('#abcdef')
        ->icon('guitar')
        ->save();

    expect($edited->name)->toBe('Rock music')
        ->and($edited->slug)->toBe('rock-music')
        ->and($edited->getTranslation('name', 'sk'))->toBe('Skala')
        ->and($edited->description)->toBe('Guitars.')
        ->and($edited->color)->toBe('#abcdef')
        ->and($edited->icon)->toBe('guitar');
});

it('finds tags by key, slug and model, and many by key in the order given', function (): void {
    $rock = Tags::type('tag')->make('Rock')->create();
    $pop = Tags::type('tag')->make('Pop')->create();
    $deleted = Tags::type('tag')->make('Ska')->create();
    Tags::type('tag')->delete($deleted);

    expect(Tags::find($rock->id)?->is($rock))->toBeTrue()
        ->and(Tags::find('not-a-key'))->toBeNull()
        ->and(Tags::findOrFail($pop->id)->is($pop))->toBeTrue()
        ->and(fn () => Tags::findOrFail(999999))->toThrow(TagNotFoundException::class, '999999')
        ->and(Tags::type('tag')->find($rock)?->is($rock))->toBeTrue()
        ->and(Tags::type('tag')->find($deleted))->toBeNull()
        ->and(Tags::type('tag')->findOrFail('pop')->is($pop))->toBeTrue()
        ->and(fn () => Tags::type('tag')->findOrFail('jazz'))->toThrow(TagNotFoundException::class, 'jazz')
        ->and(fn () => Tags::type('tag')->findOrFail(999999))->toThrow(TagNotFoundException::class)
        ->and(Tags::type('tag')->findMany([(string) $pop->id, $rock->id, 'junk', 999999, $deleted->id, null])->modelKeys())->toBe([$pop->id, $rock->id])
        ->and(Tags::type('tag')->findMany([])->all())->toBe([])
        ->and(Tags::type('tag')->roots()->modelKeys())->toBe([$rock->id, $pop->id])
        ->and(Tags::type('tag')->query()->count())->toBe(2);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

it('dispatches catalog events after commit', function (): void {
    Event::fake([TagCreated::class, TagUpdated::class, TagDeleted::class, TagRestored::class]);

    $tag = Tags::type('tag')->make('Rock')->create();
    Tags::type('tag')->update($tag, new UpdateTagData);
    Tags::type('tag')->rename($tag, 'Rock music');
    Tags::type('tag')->delete($tag);
    Tags::type('tag')->restore($tag);
    Tags::type('tag')->delete($tag);
    Tags::type('tag')->make('Rock music')->create();

    Event::assertDispatchedTimes(TagCreated::class, 1);
    Event::assertDispatchedTimes(TagUpdated::class, 1);
    Event::assertDispatched(TagUpdated::class, fn (TagUpdated $event): bool => $event->changed === ['name']);
    Event::assertDispatchedTimes(TagDeleted::class, 2);
    Event::assertDispatched(TagDeleted::class, fn (TagDeleted $event): bool => $event->detached === 0 && $event->subtree === []);
    Event::assertDispatchedTimes(TagRestored::class, 2);
});

it('keeps deletes and restores idempotent', function (): void {
    $tag = Tags::type('tag')->make('Rock')->create();

    $first = Tags::type('tag')->delete($tag);
    $second = Tags::type('tag')->delete($tag);
    $restored = Tags::type('tag')->restore($tag);
    $again = Tags::type('tag')->restore($tag);

    expect($first->trashed())->toBeTrue()
        ->and($second->trashed())->toBeTrue()
        ->and($restored->trashed())->toBeFalse()
        ->and($again->trashed())->toBeFalse();
});

it('records catalog calls on the fake while they still run', function (): void {
    $fake = Tags::fake();
    $team = Team::query()->create(['name' => 'Acme']);

    $fake->assertNoTagCreated();
    $fake->assertNothingHappened();

    $tag = Tags::type('tag')->within($team)->make('Rock')->create();
    Tags::type('tag')->findOrCreate('Rock');
    Tags::type('tag')->findOrCreate('Rock');
    Tags::type('tag')->within($team)->rename($tag, 'Rock music');
    Tags::type('tag')->within($team)->delete($tag);
    Tags::type('tag')->within($team)->restore($tag);

    $fake->assertTagCreated();
    $fake->assertTagCreated(fn (Tag $created): bool => $created->is($tag));
    $fake->assertTagUpdated(fn (Tag $updated): bool => $updated->name === 'Rock music');
    $fake->assertTagDeleted();
    $fake->assertTagRestored();
    Tags::assertNoTagMoved();
    Tags::assertNoTagsReordered();

    expect(Tag::query()->count())->toBe(2)
        ->and($fake->recorded())->toHaveCount(6);
});

it('fails the catalog assertions with a message', function (Closure $assert, string $message): void {
    $fake = Tags::fake();
    $tag = Tags::type('tag')->make('Rock')->create();
    Tags::type('tag')->delete($tag);

    expect(fn () => $assert($fake))->toThrow(ExpectationFailedException::class, $message);
})->with([
    'created, no match' => [fn ($fake) => $fake->assertTagCreated(fn (): bool => false), 'none matched the callback'],
    'no tag created' => [fn ($fake) => $fake->assertNoTagCreated(), 'Expected no tag to be created, but 1 were.'],
    'updated' => [fn ($fake) => $fake->assertTagUpdated(), 'Expected a tag to be updated, but none was.'],
    'no tag deleted' => [fn ($fake) => $fake->assertNoTagDeleted(), 'Expected no tag to be deleted'],
    'restored' => [fn ($fake) => $fake->assertTagRestored(), 'Expected a tag to be restored'],
    'moved' => [fn ($fake) => $fake->assertTagMoved(), 'Expected a tag to be moved'],
    'nothing happened' => [fn ($fake) => $fake->assertNothingHappened(), 'Expected no tag operations, but 2 were recorded.'],
]);

it('records nothing for a call that throws', function (): void {
    $fake = Tags::fake();

    expect(fn () => Tags::type('tag')->make('')->create())->toThrow(InvalidTagException::class);

    $fake->assertNothingHappened();
});
