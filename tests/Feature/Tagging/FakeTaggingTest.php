<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Testing\TagsFake;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;

beforeEach(function (): void {
    Tags::define('label');
    $this->article = Article::query()->create(['title' => 'Concert']);
});

test('fake_assertions_pass_through_every_layer', function (): void {
    $fake = Tags::fake();

    $this->article->attachTags(['live'], 'label');                 // the trait
    Tags::for($this->article)->attach('outdoor', 'label');         // the handle
    app(TagsManager::class)->sync($this->article, ['live', 'free'], 'label'); // DI
    Tags::detach($this->article, 'free', 'label');                 // the facade
    $this->article->clearTags('tag');

    $fake->assertTagged($this->article, ['live'], type: 'label');
    $fake->assertTagged($this->article, ['outdoor', 'free']);
    $fake->assertUntagged($this->article, ['outdoor', 'free'], 'label');
    $fake->assertSynced($this->article, 'label', ['live']);
    $fake->assertCleared($this->article, 'tag');
    $fake->assertCleared($this->article);

    // Every call ran for real.
    expect($this->article->tagsOf('label')->pluck('slug')->all())->toBe(['live']);
});

test('fake_records_the_deletion_hook_as_a_clear', function (): void {
    $note = Note::query()->create(['body' => 'x']);
    $note->attachTags('x');
    $fake = Tags::fake();

    $note->delete();

    $fake->assertCleared($note);
    $fake->assertUntagged($note, ['x']);
});

test('fake_assertions_fail_with_a_message', function (Closure $assert, string $message): void {
    $fake = Tags::fake();
    $other = Article::query()->create(['title' => 'Other']);
    $this->article->attachTags(['live'], 'label');

    expect(fn () => $assert($fake, $this->article, $other))->toThrow(ExpectationFailedException::class, $message);
})->with([
    'tagged, another model' => [fn (TagsFake $fake, $article, $other) => $fake->assertTagged($other), 'Expected tags to be attached to the model'],
    'tagged, another tag' => [fn (TagsFake $fake, $article) => $fake->assertTagged($article, ['outdoor']), 'tag [outdoor] to be attached'],
    'tagged, another type' => [fn (TagsFake $fake, $article) => $fake->assertTagged($article, null, 'tag'), 'Expected tags to be attached'],
    'nothing tagged' => [fn (TagsFake $fake) => $fake->assertNothingTagged(), 'Expected no tags to be attached, but 1 were.'],
    'untagged' => [fn (TagsFake $fake, $article) => $fake->assertUntagged($article), 'Expected tags to be detached'],
    'synced' => [fn (TagsFake $fake, $article) => $fake->assertSynced($article, 'label'), "Expected the model's [label] tags to be synced"],
    'cleared' => [fn (TagsFake $fake, $article) => $fake->assertCleared($article), "Expected the model's tags to be cleared"],
    'cleared of a type' => [fn (TagsFake $fake, $article) => $fake->assertCleared($article, 'label'), "Expected the model's [label] tags to be cleared"],
]);

test('fake_nothing_assertions_fail_after_the_effect', function (): void {
    $fake = Tags::fake();
    $this->article->attachTags(['live', 'outdoor'], 'label')->detachTags('live', 'label')->syncTags(['outdoor'], 'label')->clearTags();

    expect(fn () => $fake->assertNothingUntagged())->toThrow(ExpectationFailedException::class, 'Expected no tags to be detached, but 2 were.')
        ->and(fn () => $fake->assertNothingSynced())->toThrow(ExpectationFailedException::class, 'Expected no tags to be synced, but 1 syncs were recorded.')
        ->and(fn () => $fake->assertNothingCleared())->toThrow(ExpectationFailedException::class, 'Expected no tags to be cleared, but 1 clears were recorded.')
        ->and(fn () => $fake->assertSynced($this->article, 'label', ['live']))->toThrow(ExpectationFailedException::class, 'synced to 1 tag(s), but it carries 0');
});

test('fake_nothing_assertions_pass_on_an_untouched_fake', function (): void {
    $fake = Tags::fake();

    $fake->assertNothingTagged();
    $fake->assertNothingUntagged();
    $fake->assertNothingSynced();
    $fake->assertNothingCleared();
    $fake->assertNothingHappened();
    $fake->assertNoTagCreated();
    $fake->assertNoTagUpdated();
    $fake->assertNoTagMoved();
    $fake->assertNoTagDeleted();
    $fake->assertNoTagRestored();
    $fake->assertNoTagsReordered();

    expect($fake->recorded())->toBe([]);
});

test('fake_matches_tags_by_model_or_slug', function (): void {
    $fake = Tags::fake();
    $this->article->attachTags(['Live'], 'label');
    $live = Tag::query()->firstOrFail();

    $fake->assertTagged($this->article, [$live]);
    $fake->assertTagged($this->article, ['Live']);
    $fake->assertTagged($this->article, ['live'], 'label');
});

test('fake_naming_rule_is_pinned', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(TagsFake::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'assert'),
        ),
    );

    $pairs = 0;

    foreach ($methods as $method) {
        if ($method === 'assertNothingHappened') {
            continue;
        }

        if (str_starts_with($method, 'assertNothing')) {
            expect($methods)->toContain('assert'.substr($method, strlen('assertNothing')));
        } elseif (str_starts_with($method, 'assertNo')) {
            expect($methods)->toContain('assert'.substr($method, strlen('assertNo')));
        } else {
            $verb = substr($method, strlen('assert'));
            // assert<Verb> ↔ assertNothing<Verb>; assert<Noun><Verb> ↔ assertNo<Noun><Verb>.
            expect(in_array('assertNothing'.$verb, $methods, true) || in_array('assertNo'.$verb, $methods, true))->toBeTrue($method);
            $pairs++;
        }
    }

    expect($pairs)->toBe(10);
});
