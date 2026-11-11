<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tags\DataTransferObjects\TagKey;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagKeyException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Rules\TagsRule;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Support\KeyTypeScenarios;

/**
 * The explicit key path: `TagKey` (`Tags::keys([...])`) names an existing tag by primary key in
 * every place a tag list goes, never a name, and never creates one. Runs on the bigint install;
 * the uuid and ulid legs run {@see KeyTypeScenarios::tagKeys()}.
 */
beforeEach(function (): void {
    Tags::define('genre')->hierarchical()->single();
    Tags::define('label');
    $this->article = Article::query()->create(['title' => 'Concert']);
    $this->live = Tags::type('label')->make('Live')->create();
    $this->outdoor = Tags::type('label')->make('Outdoor')->create();
});

test('a_tag_key_attaches_syncs_and_detaches_the_existing_tag', function (): void {
    $this->article->attachTags(TagKey::of($this->live->getKey()), 'label');
    expect($this->article->tagsOf('label')->modelKeys())->toBe([$this->live->getKey()]);

    $this->article->syncTags(Tags::keys([(string) $this->outdoor->getKey(), $this->live->getKey()]), 'label');
    expect($this->article->tagsOf('label')->pluck('slug')->sort()->values()->all())->toBe(['live', 'outdoor']);

    $this->article->detachTags([TagKey::of((string) $this->live->getKey())], 'label');
    expect($this->article->tagsOf('label')->pluck('slug')->all())->toBe(['outdoor'])
        ->and(Tag::query()->count())->toBe(2);
});

test('a_key_carries_its_own_type_and_mixes_with_names', function (): void {
    $this->article->attachTags([TagKey::of($this->live->getKey()), 'Fresh']);

    expect($this->article->tagsOf('label')->pluck('slug')->all())->toBe(['live'])
        ->and($this->article->tagsOf('tag')->pluck('slug')->all())->toBe(['fresh']);
});

test('an_unknown_key_throws_and_writes_nothing', function (mixed $key): void {
    $tags = Tag::query()->count();

    expect(fn () => $this->article->attachTags([TagKey::of($key), 'Fresh'], 'label'))
        ->toThrow(UnknownTagKeyException::class)
        ->and(fn () => Tags::sync($this->article, [TagKey::of($key)], 'label'))
        ->toThrow(TagNotFoundException::class)
        ->and(Tag::query()->count())->toBe($tags)
        ->and(Tagging::query()->count())->toBe(0);
})->with([
    'missing' => [999_999],
    'missing numeric string' => ['999999'],
    'malformed' => ['not-a-key'],
]);

test('the_unknown_key_exception_is_a_not_found_that_hides_the_key_from_clients', function (): void {
    $exception = UnknownTagKeyException::make('01JUNKNOWN');

    expect($exception)->toBeInstanceOf(TagNotFoundException::class)
        ->and($exception->errorCode())->toBe('tag_not_found')
        ->and($exception->isNotFound())->toBeTrue()
        ->and($exception->key)->toBe('01JUNKNOWN')
        ->and($exception->getMessage())->toContain('01JUNKNOWN')
        ->and($exception->clientMessage())->toBe(TagNotFoundException::generic()->getMessage());
});

test('an_unknown_int_key_throws_the_dedicated_exception_too', function (): void {
    expect(fn () => $this->article->attachTags([999_999], 'label'))->toThrow(UnknownTagKeyException::class);
});

test('a_detach_skips_an_unknown_key', function (): void {
    $this->article->attachTags([$this->live], 'label');

    $changes = Tags::detach($this->article, [TagKey::of(999_999), TagKey::of('junk')], 'label');

    expect($changes->detached)->toBe([])
        ->and($this->article->tagsOf('label'))->toHaveCount(1);
});

test('a_key_of_another_scope_is_refused_like_any_other_reference', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    $theirs = Tags::type('label')->within($globex)->make('Secret')->create();
    $article = ScopedArticle::query()->create(['title' => 'Mine', 'team_id' => $acme->getKey()]);

    expect(fn () => $article->attachTags(TagKey::of($theirs->getKey()), 'label'))->toThrow(TagScopeMismatchException::class)
        ->and(Tags::detach($article, TagKey::of($theirs->getKey()), 'label')->detached)->toBe([]);
});

test('a_numeric_string_stays_a_name_without_the_key_wrapper', function (): void {
    $this->article->attachTags([(string) $this->live->getKey()], 'label');

    expect($this->article->tagsOf('label')->pluck('name')->all())->toBe([(string) $this->live->getKey()])
        ->and(Tag::query()->count())->toBe(3);
});

test('string_keys_never_reads_numbers_as_keys_on_a_bigint_install', function (): void {
    config()->set('tags.string_keys', true);

    $this->article->attachTags([(string) $this->live->getKey()], 'label');

    expect($this->article->tagsOf('label')->pluck('name')->all())->toBe([(string) $this->live->getKey()]);
});

test('the_query_scopes_take_keys', function (): void {
    $other = Article::query()->create(['title' => 'Other']);
    $this->article->attachTags([$this->live, $this->outdoor], 'label');
    $other->attachTags([$this->live], 'label');

    $live = TagKey::of((string) $this->live->getKey());
    $outdoor = TagKey::of($this->outdoor->getKey());
    $unknown = TagKey::of(999_999);

    expect(Article::query()->withAnyTags([$outdoor], 'label')->pluck('title')->all())->toBe(['Concert'])
        ->and(Article::query()->withAllTags([$live, $outdoor])->pluck('title')->all())->toBe(['Concert'])
        ->and(Article::query()->withoutTags([$outdoor], 'label')->pluck('title')->all())->toBe(['Other'])
        ->and(Article::query()->withAnyTags($live)->count())->toBe(2)
        ->and(Article::query()->withAnyTags([$unknown])->count())->toBe(0)
        ->and(Article::query()->withAllTags([$live, $unknown])->count())->toBe(0);
});

test('the_reads_take_keys', function (): void {
    $this->article->attachTags([$this->live], 'label');
    $live = TagKey::of((string) $this->live->getKey());
    $outdoor = TagKey::of($this->outdoor->getKey());

    expect($this->article->hasTag($live))->toBeTrue()
        ->and($this->article->hasTag($live, 'label'))->toBeTrue()
        ->and($this->article->hasTag($outdoor))->toBeFalse()
        ->and($this->article->hasAnyTags([$outdoor, $live]))->toBeTrue()
        ->and($this->article->hasAllTags(Tags::keys([$this->live->getKey(), $this->outdoor->getKey()])))->toBeFalse()
        ->and(Tags::for($this->article)->findMany([$live, $outdoor, 'junk'], 'label')->modelKeys())->toBe([$this->live->getKey(), $this->outdoor->getKey()]);
});

test('the_catalog_finds_and_reorders_by_key', function (): void {
    $labels = Tags::type('label');

    expect($labels->find(TagKey::of($this->live->getKey()))?->is($this->live))->toBeTrue()
        ->and($labels->findOrFail(TagKey::of((string) $this->outdoor->getKey()))->is($this->outdoor))->toBeTrue()
        ->and($labels->find(TagKey::of(999_999)))->toBeNull()
        ->and($labels->find(TagKey::of('junk')))->toBeNull()
        ->and(fn () => $labels->findOrFail(TagKey::of(999_999)))->toThrow(TagNotFoundException::class)
        ->and(Tags::type('genre')->find(TagKey::of($this->live->getKey())))->toBeNull()
        ->and($labels->findMany([TagKey::of($this->outdoor->getKey())])->modelKeys())->toBe([$this->outdoor->getKey()])
        ->and($labels->reorder(null, [TagKey::of($this->outdoor->getKey())])->pluck('slug')->all())->toBe(['outdoor', 'live']);

    $labels->delete($this->live);

    expect($labels->findTrashed(TagKey::of($this->live->getKey()))?->is($this->live))->toBeTrue()
        ->and($labels->findTrashed(TagKey::of('junk')))->toBeNull();
});

test('keys_builds_the_list_and_refuses_what_is_not_a_key', function (): void {
    expect(Tags::keys([1, '2', TagKey::of(3)]))->toEqual([TagKey::of(1), TagKey::of('2'), TagKey::of(3)])
        ->and(Tags::keys(5))->toEqual([TagKey::of(5)])
        ->and(app(TagsManager::class)->keys(collect(['7'])))->toEqual([TagKey::of('7')])
        ->and((string) TagKey::of(42))->toBe('42')
        ->and(TagKey::of('0042')->canonical())->toBe('42')
        ->and(TagKey::of('0042')->isWellFormed())->toBeTrue()
        ->and(TagKey::of('01JUNK')->isWellFormed())->toBeFalse()
        ->and(fn () => Tags::keys([1.5]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Tags::keys([$this->live]))->toThrow(InvalidArgumentException::class);
});

test('a_selection_keeps_keys_as_given', function (): void {
    $key = TagKey::of(5);

    expect(TagSelection::of($key)->tags)->toBe([$key])
        ->and(TagSelection::of([$key, 'name'])->tags)->toBe([$key, 'name']);
});

test('the_fake_records_a_keyed_write_through_the_trait', function (): void {
    $fake = Tags::fake();

    $this->article->attachTags(TagKey::of($this->live->getKey()), 'label');

    $fake->assertTagged($this->article, [$this->live], 'label');
    $fake->assertTagged($this->article, [TagKey::of((string) $this->live->getKey())]);

    expect(fn () => $fake->assertTagged($this->article, [TagKey::of($this->outdoor->getKey())]))
        ->toThrow(ExpectationFailedException::class, 'Expected the tag ['.$this->outdoor->getKey().'] to be attached');

    try {
        $this->article->attachTags(TagKey::of(999_999), 'label');
    } catch (UnknownTagKeyException) {
    }

    expect($fake->recorded())->toHaveCount(1);
});

test('the_rule_validates_ids_unchanged_on_bigint', function (): void {
    config()->set('tags.string_keys', true);

    $passes = static fn (array $labels, TagsRule $rule): bool => Validator::make(['labels' => $labels], ['labels' => ['array', $rule]])->passes();

    expect($passes([(string) $this->live->getKey()], TagsRule::for('label')->byId()))->toBeTrue()
        ->and($passes(['999999'], TagsRule::for('label')->byId()))->toBeFalse()
        // Numbers are names on a bigint install, flag or not.
        ->and($passes(['999999'], TagsRule::for('label')))->toBeTrue();
});
