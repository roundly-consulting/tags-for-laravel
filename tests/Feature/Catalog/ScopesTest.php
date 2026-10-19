<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

beforeEach(function (): void {
    $this->acme = Team::query()->create(['name' => 'Acme']);
    $this->globex = Team::query()->create(['name' => 'Globex']);
});

test('within_reads_scope_and_global', function (): void {
    $global = Tags::type('tag')->make('Printer')->create();
    $acme = Tags::type('tag')->within($this->acme)->make('Scanner')->create();
    $globex = Tags::type('tag')->within($this->globex)->make('Fax')->create();

    expect(Tags::type('tag')->all()->modelKeys())->toBe([$global->id])
        ->and(Tags::type('tag')->within($this->acme)->all()->pluck('name')->sort()->values()->all())->toBe(['Printer', 'Scanner'])
        ->and(Tags::type('tag')->within($this->acme, includeGlobal: false)->all()->modelKeys())->toBe([$acme->id])
        ->and(Tags::type('tag')->within($this->acme)->find('fax'))->toBeNull()
        ->and(Tags::type('tag')->within($this->globex)->find('fax')?->is($globex))->toBeTrue()
        ->and(Tags::type('tag')->within($this->acme)->find($globex))->toBeNull()
        ->and(Tags::type('tag')->within($this->acme)->find((int) $globex->id))->toBeNull();
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('scoped_then_global_resolution', function (): void {
    $global = Tags::type('tag')->make('Printer')->create();
    $scoped = Tags::type('tag')->within($this->acme)->make('Printer')->create();

    expect(Tags::type('tag')->within($this->acme)->find('printer')?->is($scoped))->toBeTrue()
        ->and(Tags::type('tag')->within($this->globex)->find('printer')?->is($global))->toBeTrue()
        ->and(Tags::type('tag')->find('printer')?->is($global))->toBeTrue()
        ->and(Tags::type('tag')->within($this->acme)->findOrCreate('Printer')->is($scoped))->toBeTrue()
        ->and(Tags::type('tag')->within($this->globex)->findOrCreate('Printer')->is($global))->toBeTrue();
});

test('a_trashed_scoped_tag_never_shadows_a_live_global_one', function (): void {
    $global = Tags::type('tag')->make('Printer')->create();
    $scoped = Tags::type('tag')->within($this->acme)->make('Printer')->create();
    Tags::type('tag')->within($this->acme)->delete($scoped);

    expect(Tags::type('tag')->within($this->acme)->find('printer')?->is($global))->toBeTrue()
        ->and(Tags::type('tag')->within($this->acme)->findOrCreate('printer')->is($global))->toBeTrue();
});

test('type_handles_refuse_tags_of_another_scope', function (Closure $write): void {
    $acmeTag = Tags::type('tag')->within($this->acme)->make('Scanner')->create();

    expect(fn () => $write(Tags::type('tag')->within($this->globex), $acmeTag))->toThrow(TagScopeMismatchException::class);
})->with([
    'update' => [fn ($handle, Tag $tag) => $handle->update($tag, new UpdateTagData(color: '#000000'))],
    'rename' => [fn ($handle, Tag $tag) => $handle->rename($tag, 'Other')],
    'edit' => [fn ($handle, Tag $tag) => $handle->edit($tag)],
    'delete' => [fn ($handle, Tag $tag) => $handle->delete($tag)],
    'restore' => [fn ($handle, Tag $tag) => $handle->restore($tag)],
]);

test('a_scoped_handle_never_writes_global_tags', function (): void {
    $global = Tags::type('tag')->make('Printer')->create();
    $acme = Tags::type('tag')->within($this->acme);

    // Readable through the scoped handle, never writable through it.
    expect($acme->find('printer')?->is($global))->toBeTrue()
        ->and(fn () => $acme->rename($global, 'Copier'))->toThrow(TagScopeMismatchException::class)
        ->and(fn () => $acme->delete($global))->toThrow(TagScopeMismatchException::class)
        ->and($global->fresh()?->name)->toBe('Printer')
        // And a global handle never writes a scoped tag.
        ->and(fn () => Tags::type('tag')->rename($acme->make('Scanner')->create(), 'X'))->toThrow(TagScopeMismatchException::class);
});

it('refuses tags of another type through a handle', function (): void {
    Tags::define('genre')->hierarchical();
    $rock = Tags::type('tag')->make('Rock')->create();

    expect(fn () => Tags::type('genre')->rename($rock, 'Rock music'))->toThrow(TagTypeMismatchException::class)
        ->and(Tags::type('genre')->find($rock))->toBeNull();
});

test('global_only_types_ignore_the_model_scope', function (): void {
    Tags::define('country')->globalOnly();

    $slovakia = Tags::type('country')->make('Slovakia')->create();

    expect($slovakia->scope_key)->toBe('')
        ->and(Tags::type('country')->within($this->acme)->find('slovakia')?->is($slovakia))->toBeTrue()
        ->and(Tags::type('country')->within($this->acme)->readScopeKeys())->toBe([''])
        ->and(fn () => Tags::type('country')->within($this->acme)->make('Austria')->create())->toThrow(InvalidTagException::class, 'always global')
        ->and(fn () => Tags::type('country')->within($this->acme)->findOrCreate('Austria'))->toThrow(InvalidTagException::class, 'always global');
});

it('keeps uniqueness per scope', function (): void {
    $acme = Tags::type('tag')->within($this->acme)->create(new TagData(name: 'Printer'));
    $globex = Tags::type('tag')->within($this->globex)->create(new TagData(name: 'Printer'));
    $global = Tags::type('tag')->create(new TagData(name: 'Printer'));

    expect([$acme->slug, $globex->slug, $global->slug])->toBe(['printer', 'printer', 'printer'])
        ->and(Tag::query()->count())->toBe(3);
});
