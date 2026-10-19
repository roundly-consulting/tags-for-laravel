<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

beforeEach(function (): void {
    $this->acme = Team::query()->create(['name' => 'Acme']);
    $this->globex = Team::query()->create(['name' => 'Globex']);
    $this->ticket = ScopedArticle::query()->create(['title' => 'Ticket', 'team_id' => $this->acme->getKey()]);
});

test('free_form_tags_are_created_in_the_models_scope', function (): void {
    $this->ticket->attachTags('printer');

    $tag = Tag::query()->firstOrFail();

    expect($tag->scope_key)->toBe(ScopeKey::for($this->acme))
        ->and($tag->scope?->is($this->acme))->toBeTrue();
});

test('scoped_then_global_resolution_when_tagging', function (): void {
    $global = Tags::type('tag')->make('Printer')->create();

    $this->ticket->attachTags('printer');

    expect($this->ticket->tagOf('tag')?->is($global))->toBeTrue()
        ->and(Tag::query()->count())->toBe(1);

    $scoped = Tags::type('tag')->within($this->acme)->make('Printer')->create();
    $other = ScopedArticle::query()->create(['team_id' => $this->acme->getKey()]);
    $other->attachTags('printer');

    expect($other->tagOf('tag')?->is($scoped))->toBeTrue();
});

test('a_trashed_scoped_tag_never_shadows_a_live_global_one_when_tagging', function (): void {
    $global = Tags::type('tag')->make('Printer')->create();
    $scoped = Tags::type('tag')->within($this->acme)->make('Printer')->create();
    Tags::type('tag')->within($this->acme)->delete($scoped);

    $this->ticket->attachTags('printer');

    expect($this->ticket->tagOf('tag')?->is($global))->toBeTrue()
        ->and($scoped->fresh()?->trashed())->toBeTrue();
});

test('cross_scope_tags_are_refused', function (): void {
    $theirs = Tags::type('tag')->within($this->globex)->make('Fax')->create();

    expect(fn () => $this->ticket->attachTags($theirs))->toThrow(TagScopeMismatchException::class)
        ->and(fn () => $this->ticket->syncTags([$theirs]))->toThrow(TagScopeMismatchException::class)
        ->and(fn () => Article::query()->create()->attachTags($theirs))->toThrow(TagScopeMismatchException::class)
        // A name of another scope is not visible: a free-form string creates the model's own.
        ->and(Tags::attach($this->ticket, 'fax')->attached[0]->scope_key)->toBe(ScopeKey::for($this->acme))
        ->and(Tagging::query()->count())->toBe(1);
});

test('global_only_types_ignore_the_model_scope_when_tagging', function (): void {
    Tags::define('country')->globalOnly();

    $this->ticket->attachTags('Slovakia', 'country');

    expect(Tag::query()->where('type', 'country')->firstOrFail()->scope_key)->toBe('');
});

test('scope_change_keeps_old_tags_until_synced', function (): void {
    $this->ticket->attachTags(['printer', 'scanner']);
    $printer = $this->ticket->tagsOf('tag')->firstWhere('slug', 'printer');

    $this->ticket->update(['team_id' => $this->globex->getKey()]);
    $this->ticket->unsetRelation('team');

    expect($this->ticket->tagsOf('tag'))->toHaveCount(2);

    // A sync names the desired set; the old scope's tags are not in it, so they go.
    $this->ticket->syncTags(['printer']);

    $tags = $this->ticket->tagsOf('tag');

    expect($tags)->toHaveCount(1)
        ->and($tags->first()?->scope_key)->toBe(ScopeKey::for($this->globex))
        ->and($tags->first()?->is($printer))->toBeFalse();
});

test('deleting_a_tag_detaches_it_everywhere', function (): void {
    $other = ScopedArticle::query()->create(['team_id' => $this->acme->getKey()]);
    $this->ticket->attachTags(['printer', 'scanner']);
    $other->attachTags('printer');
    $printer = Tags::type('tag')->within($this->acme)->findOrFail('printer');

    $deleted = Tags::type('tag')->within($this->acme)->delete($printer);

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->usage_count)->toBe(0)
        ->and(Tagging::query()->where('tag_id', $printer->getKey())->count())->toBe(0)
        ->and($this->ticket->tagsOf('tag')->pluck('slug')->all())->toBe(['scanner']);
});

test('a_recreated_tag_never_relabels_old_models', function (): void {
    $this->ticket->attachTags('printer');
    $printer = Tags::type('tag')->within($this->acme)->findOrFail('printer');
    Tags::type('tag')->within($this->acme)->delete($printer);

    $again = Tags::type('tag')->within($this->acme)->findOrCreate('Printer');

    expect($again->is($printer))->toBeTrue()
        ->and($again->usage_count)->toBe(0)
        ->and($this->ticket->tagsOf('tag'))->toHaveCount(0);
});
