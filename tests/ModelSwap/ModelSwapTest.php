<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\CustomTag;

beforeEach(function (): void {
    Tags::define('genre')->hierarchical();
});

it('honours the tag model swap in every flow that creates tags', function (): void {
    expect('tags.models.tag')->toHonourModelSwap(CustomTag::class, function (): array {
        $music = Tags::type('genre')->make('Music')->create();
        $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
        $found = Tags::type('tag')->findOrCreate('Rock');
        $changes = Tags::attach(Article::query()->create(), ['Live', 'Outdoor']);

        return [$music, $jazz, $found, ...$changes->attached, Tag::factory()->create()];
    });
});

test('swapped_tag_model_is_used_everywhere', function (): void {
    $music = Tags::type('genre')->make('Music')->create();
    $jazz = Tags::type('genre')->make('Jazz')->under($music)->create();
    $article = Article::query()->create();
    $article->syncTags($jazz, 'genre')->attachTags('Live');

    $reads = [
        $jazz->parent,
        $music->children()->first(),
        $jazz->ancestors()->first(),
        $music->descendants()->first(),
        $article->tags()->first(),
        Article::query()->with('tags')->firstOrFail()->tags->first(),
        $article->tagOf('genre'),
        Tags::find($jazz->getKey()),
        Tags::type('genre')->find('jazz'),
        Tags::type('genre')->tree()[0]->tag,
        Tags::type('tag')->suggest('liv')->first(),
        Tags::type('tag')->popular()->first(),
        Tags::type('genre')->findMany([$jazz->getKey()])->first(),
        Tags::type('genre')->rename($jazz, 'Jazz music'),
        Tags::type('genre')->move($jazz),
        Tags::type('genre')->delete($jazz),
        Tags::type('genre')->restore($jazz),
    ];

    foreach ($reads as $index => $tag) {
        expect($tag)->toBeInstanceOf(CustomTag::class, "read #{$index}");
    }

    expect(Tags::type('genre')->findOrFail('jazz-music')->shout())->toBe('JAZZ MUSIC')
        ->and((new CustomTag)->getTable())->toBe('tags');
});
