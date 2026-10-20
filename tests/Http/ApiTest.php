<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagAttributes;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;

beforeEach(function (): void {
    defineTagGates();
    Tags::define('genre')->hierarchical()->single()->label(['en' => 'Genre', 'sk' => 'Žáner']);
    Tags::define('label')->max(5);
    $this->music = Tags::type('genre')->make('Music')->create();
    $this->jazz = Tags::type('genre')->make('Jazz')->under($this->music)->create();
});

it('lists and shows the types', function (): void {
    apiUser();

    $this->getJson('/api/tag-types')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'genre')
        ->assertJsonPath('data.0.label', 'Genre')
        ->assertJsonPath('data.0.hierarchical', true)
        ->assertJsonPath('data.0.max', 1)
        ->assertJsonPath('data.0.curated', true)
        ->assertJsonCount(3, 'data');

    app()->setLocale('sk');

    $this->getJson('/api/tag-types/genre')->assertOk()->assertJsonPath('data.label', 'Žáner');
    $this->getJson('/api/tag-types/nope')->assertNotFound()->assertJson(['code' => 'unknown_tag_type']);
    $this->getJson('/api/tag-types/No%20Pe')->assertNotFound()->assertJson(['code' => 'unknown_tag_type']);
});

it('lists the tags of a type, filtered and sorted', function (): void {
    apiUser();
    $rock = Tags::type('genre')->make(['en' => 'Rock', 'sk' => 'Rock'])->create();

    $this->getJson('/api/tags/genre')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.slug', 'music');
    $this->getJson('/api/tags/genre?filter[parent]=root')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/tags/genre?filter[parent]='.$this->music->getKey())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'jazz');
    $this->getJson('/api/tags/genre?filter[parent]=junk')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/tags/genre?filter[depth]=2')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/tags/genre?filter[depth]=x')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/tags/genre?filter[search]=az')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/tags/genre?filter[ids]='.$rock->getKey().',junk')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/tags/genre?filter[ids]=junk')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/tags/genre?filter[slug]=rock,jazz')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/tags/genre?sort=-name')->assertOk()->assertJsonPath('data.0.slug', 'rock');
    $this->getJson('/api/tags/genre?sort=name')->assertOk()->assertJsonPath('data.0.slug', 'jazz');
    $this->getJson('/api/tags/genre?sort=-usage_count,created_at&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    $this->getJson('/api/tags/genre?per_page=1000')->assertUnprocessable();
    $this->getJson('/api/tags/genre?filter[trashed]=with')->assertStatus(400);
    $this->getJson('/api/tags/genre?filter[nope]=x')->assertStatus(400);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

it('returns a tree on request, up to the node limit', function (): void {
    apiUser();

    $this->getJson('/api/tags/genre?tree=1')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'music')
        ->assertJsonPath('data.0.children.0.slug', 'jazz')
        ->assertJsonPath('data.0.children.0.parent_id', $this->music->getKey());

    // A flat type ignores the switch.
    $this->getJson('/api/tags/label?tree=1')->assertOk()->assertJsonPath('data', []);

    config()->set('tags.routes.max_tree_nodes', 1);

    $this->getJson('/api/tags/genre?tree=1')->assertUnprocessable()->assertJson(['code' => 'tree_too_large']);
});

it('shows one tag by key or slug, with its ancestors', function (): void {
    apiUser();

    $this->getJson('/api/tags/genre/jazz')
        ->assertOk()
        ->assertJsonPath('data.slug', 'jazz')
        ->assertJsonPath('data.depth', 2)
        ->assertJsonPath('data.ancestors.0.slug', 'music')
        ->assertJsonPath('data.scope', null)
        ->assertJsonMissingPath('data.usage_count');

    $this->getJson('/api/tags/genre/'.$this->jazz->getKey())->assertOk()->assertJsonPath('data.slug', 'jazz');
    $this->getJson('/api/tags/genre/nope')->assertNotFound()->assertJson(['code' => 'tag_not_found']);
    $this->getJson('/api/tags/label/jazz')->assertNotFound()->assertJson(['code' => 'tag_not_found']);
});

it('creates, updates, moves, reorders, deletes and restores for managers', function (): void {
    apiUser(admin: true);
    $fake = Tags::fake();

    $created = $this->postJson('/api/tags/genre', [
        'name' => ['en' => 'Bebop', 'sk' => 'Bebop'],
        'description' => ['en' => 'Fast.'],
        'color' => '#F24E29',
        'icon' => 'sax',
        'parent' => (string) $this->jazz->getKey(),
        'position' => 0,
    ])->assertCreated()
        ->assertJsonPath('data.slug', 'bebop')
        ->assertJsonPath('data.color', '#f24e29')
        ->assertJsonPath('data.depth', 3)
        ->assertJsonPath('data.usage_count', 0)
        ->json('data.id');

    $this->patchJson("/api/tags/genre/{$created}", ['name' => ['en' => 'Bebop jazz'], 'color' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bebop jazz')
        ->assertJsonPath('data.color', null)
        ->assertJsonPath('data.icon', 'sax');

    $this->postJson("/api/tags/genre/{$created}/move", ['parent' => null])->assertOk()->assertJsonPath('data.depth', 1);
    $this->postJson('/api/tags/genre/reorder', ['parent' => null, 'order' => [(string) $created, $this->music->getKey()]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $created);

    $this->deleteJson("/api/tags/genre/{$this->music->getKey()}")->assertStatus(409)->assertJson(['code' => 'tag_has_children']);
    $this->deleteJson("/api/tags/genre/{$this->music->getKey()}?children=reparent")->assertOk()->assertJsonPath('data.deleted_at', fn ($value): bool => is_string($value));
    $this->postJson("/api/tags/genre/{$this->music->getKey()}/restore")->assertOk()->assertJsonMissingPath('data.deleted_at');
    $this->deleteJson("/api/tags/genre/{$this->music->getKey()}?children=nope")->assertUnprocessable();

    $fake->assertTagCreated();
    $fake->assertTagUpdated();
    $fake->assertTagMoved();
    $fake->assertTagsReordered('genre');
    $fake->assertTagDeleted();
    $fake->assertTagRestored();
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('restore_addresses_a_deleted_tag_by_slug', function (): void {
    apiUser(admin: true);
    $rock = Tags::type('genre')->make('Rock')->create();
    Tags::type('genre')->delete($rock);

    $this->postJson('/api/tags/genre/rock/restore')
        ->assertOk()
        ->assertJsonPath('data.slug', 'rock')
        ->assertJsonMissingPath('data.deleted_at');

    expect($rock->fresh()?->trashed())->toBeFalse();

    // A live tag by slug restores as a no-op, like a live key; an unknown slug is not found.
    $this->postJson('/api/tags/genre/jazz/restore')->assertOk()->assertJsonPath('data.slug', 'jazz');
    $this->postJson('/api/tags/genre/polka/restore')->assertNotFound()->assertJson(['code' => 'tag_not_found']);
});

test('restore_by_slug_never_reaches_another_scope', function (): void {
    apiUser(admin: true);
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    $theirs = Tags::type('label')->within($globex)->make('Fax')->create();
    Tags::type('label')->within($globex)->delete($theirs);
    $global = Tags::type('label')->make('Telex')->create();
    Tags::type('label')->delete($global);

    $this->postJson('/api/tags/label/fax/restore?scope=team:'.$acme->getKey())->assertNotFound()->assertJson(['code' => 'tag_not_found']);
    $this->postJson('/api/tags/label/telex/restore?scope=team:'.$acme->getKey())->assertNotFound()->assertJson(['code' => 'tag_not_found']);
    $this->postJson('/api/tags/label/fax/restore')->assertNotFound()->assertJson(['code' => 'tag_not_found']);

    expect($theirs->fresh()?->trashed())->toBeTrue()
        ->and($global->fresh()?->trashed())->toBeTrue();

    $this->postJson('/api/tags/label/fax/restore?scope=team:'.$globex->getKey())->assertOk()->assertJsonPath('data.slug', 'fax');
    $this->postJson('/api/tags/label/telex/restore')->assertOk()->assertJsonPath('data.slug', 'telex');
});

test('restore_after_a_direct_delete_starts_clean_over_the_api', function (): void {
    apiUser(admin: true);
    $article = Article::query()->create();
    $article->attachTags(['live', 'outdoor'], 'label');
    $live = Tags::type('label')->findOrFail('live');
    $live->delete();

    $this->postJson('/api/tags/label/live/restore')
        ->assertOk()
        ->assertJsonPath('data.usage_count', 0)
        ->assertJsonMissingPath('data.deleted_at');

    expect($article->tagsOf('label')->pluck('slug')->all())->toBe(['outdoor']);
});

// A form post — or a JSON string — carries the position as "0"; the `integer` rule accepts it.
test('form_encoded_position_is_honoured', function (): void {
    apiUser(admin: true);
    Tags::type('genre')->make('Rock')->create();
    $form = ['Accept' => 'application/json'];

    $this->post('/api/tags/genre', ['name' => ['en' => 'First'], 'position' => '0'], $form)
        ->assertCreated()
        ->assertJsonPath('data.position', 0);

    $this->post("/api/tags/genre/{$this->jazz->getKey()}/move", ['parent' => '', 'position' => '0'], $form)
        ->assertOk()
        ->assertJsonPath('data.position', 0);

    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Second'], 'position' => '1'])
        ->assertCreated()
        ->assertJsonPath('data.position', 1);

    expect(Tags::type('genre')->roots()->pluck('slug')->all())->toBe(['jazz', 'second', 'first', 'music', 'rock']);
});

// The position column is a 32-bit integer: a larger one is invalid input, never a database error.
test('a_position_past_the_column_range_is_refused', function (): void {
    apiUser(admin: true);

    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Far'], 'position' => 3_000_000_000])->assertUnprocessable()->assertJsonValidationErrors('position');
    $this->postJson("/api/tags/genre/{$this->jazz->getKey()}/move", ['parent' => null, 'position' => 3_000_000_000])->assertUnprocessable()->assertJsonValidationErrors('position');

    expect(fn () => Tags::type('genre')->make('Far')->position(3_000_000_000))->toThrow(InvalidTagException::class)
        ->and(Tags::type('genre')->make('Near')->position(2_147_483_647)->create()->position)->toBe(2_147_483_647);
});

// A move to a later index under the same parent closes the slot it leaves, so the index
// counts the siblings without it.
test('a_same_parent_move_to_a_later_index_lands_there', function (): void {
    apiUser(admin: true);
    $genres = Tags::type('genre');
    $parent = $genres->make('Parent')->create();
    $a = $genres->make('A')->under($parent)->create();
    $b = $genres->make('B')->under($parent)->create();
    $c = $genres->make('C')->under($parent)->create();
    $order = static fn (): array => $parent->children()->get()->pluck('slug')->all();

    $genres->move($a, $parent, 1);
    expect($order())->toBe(['b', 'a', 'c']);

    $genres->reorder($parent, [$a, $b, $c]);
    $genres->move($a, $parent, 2);
    expect($order())->toBe(['b', 'c', 'a']);

    $genres->reorder($parent, [$a, $b, $c]);
    $this->postJson("/api/tags/genre/{$a->getKey()}/move", ['parent' => (string) $parent->getKey(), 'position' => 1])
        ->assertOk()
        ->assertJsonPath('data.position', 1);
    expect($order())->toBe(['b', 'a', 'c']);
});

// The pagination links carry the request's scope, filters, sort and page size.
test('index_links_keep_the_query_string', function (): void {
    apiUser(admin: true);
    $team = Team::query()->create(['name' => 'Acme']);

    foreach (range(1, 25) as $index) {
        Tags::type('label')->within($team)->make(sprintf('Printer %02d', $index))->create();
    }

    $query = 'scope=team:'.$team->getKey().'&scope_only=1&filter[search]=pr&sort=name&per_page=10';
    $first = $this->getJson('/api/tags/label?'.$query)->assertOk()->assertJsonPath('meta.total', 25);
    $next = (string) $first->json('links.next');

    foreach (['scope=team:'.$team->getKey(), 'scope_only=1', 'filter[search]=pr', 'sort=name', 'per_page=10', 'page=2'] as $part) {
        expect(urldecode($next))->toContain($part);
    }

    expect(urldecode((string) $first->json('links.last')))->toContain('filter[search]=pr')->toContain('page=3');

    $this->getJson($next)
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('data.0.slug', 'printer-11');
});

// The depth column is a small integer; a depth past its range names no tag, never a 500.
test('an_out_of_range_depth_filter_lists_nothing', function (string $depth): void {
    apiUser();

    $this->getJson('/api/tags/genre?filter[depth]='.$depth)->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/tags/genre?filter[depth]=0002')->assertOk()->assertJsonCount(1, 'data');
})->with(['40000', '99999999999', str_repeat('9', 23)]);

// query-builder splits a filter value on commas; the search is one term all the same.
test('a_search_with_a_comma_is_one_term', function (): void {
    apiUser();
    Tags::type('label')->make('Printer')->create();
    Tags::type('label')->make('Salt, pepper')->create();

    $this->getJson('/api/tags/label?filter[search]='.urlencode('zzz, qqq'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/tags/label?filter[search]=zzz,qqq')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/tags/label?filter[search]='.urlencode('salt, pep'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'salt-pepper');
    $this->getJson('/api/tags/label?filter[search]=')->assertOk()->assertJsonCount(2, 'data');
});

test('clearing_one_locales_description_over_the_api_keeps_the_others', function (): void {
    apiUser(admin: true);
    $rock = Tags::type('genre')->make('Rock')->description(['en' => 'Loud.', 'sk' => 'Hlasné.'])->create();

    $this->patchJson("/api/tags/genre/{$rock->getKey()}", ['description' => ['en' => null]])->assertOk();
    expect($rock->fresh()?->getTranslations('description'))->toBe(['sk' => 'Hlasné.']);

    $this->patchJson("/api/tags/genre/{$rock->getKey()}", ['description' => ['en' => 'Loud.', 'sk' => '']])->assertOk();
    expect($rock->fresh()?->getTranslations('description'))->toBe(['en' => 'Loud.']);

    $this->patchJson("/api/tags/genre/{$rock->getKey()}", ['description' => null])->assertOk();
    expect($rock->fresh()?->getTranslations('description'))->toBe([]);
});

// A store of a deleted tag's name brings it back: in its place unless the body names a parent,
// where `null` asks for a root.
test('store_of_a_deleted_name_moves_it_only_when_the_body_names_a_parent', function (): void {
    apiUser(admin: true);
    Tags::type('genre')->delete($this->jazz);

    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Jazz']])->assertCreated()
        ->assertJsonPath('data.id', $this->jazz->getKey())
        ->assertJsonPath('data.parent_id', $this->music->getKey())
        ->assertJsonPath('data.depth', 2);

    Tags::type('genre')->delete($this->jazz->fresh() ?? $this->jazz);

    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Jazz'], 'parent' => null])->assertCreated()
        ->assertJsonPath('data.id', $this->jazz->getKey())
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.depth', 1);
});

test('move_and_store_accept_an_integer_parent', function (): void {
    apiUser(admin: true);

    $created = $this->postJson('/api/tags/genre', ['name' => ['en' => 'Bebop'], 'parent' => (int) $this->jazz->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.depth', 3)
        ->json('data.id');

    $this->postJson("/api/tags/genre/{$created}/move", ['parent' => (int) $this->music->getKey()])
        ->assertOk()
        ->assertJsonPath('data.depth', 2)
        ->assertJsonPath('data.parent_id', $this->music->getKey());
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

it('refuses writes to readers', function (): void {
    apiUser();

    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Bebop']])->assertForbidden();
    $this->patchJson('/api/tags/genre/jazz', ['color' => '#000000'])->assertForbidden();
    $this->deleteJson('/api/tags/genre/jazz')->assertForbidden();
    $this->postJson('/api/tags/genre/jazz/move', ['parent' => null])->assertForbidden();
    $this->postJson('/api/tags/genre/reorder', ['order' => []])->assertForbidden();
    $this->postJson('/api/tags/genre/jazz/restore')->assertForbidden();
});

it('validates the body', function (): void {
    apiUser(admin: true);

    $this->postJson('/api/tags/genre', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/tags/genre', ['name' => ['en' => '']])->assertUnprocessable();
    $this->postJson('/api/tags/genre', ['name' => ['en' => str_repeat('a', 65)]])->assertUnprocessable()->assertJsonValidationErrors('name.en');
    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Ok'], 'color' => 'red'])->assertUnprocessable()->assertJson(['code' => 'invalid_tag']);
    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Jazz']])->assertStatus(409)->assertJson(['code' => 'duplicate_tag']);
    $this->postJson('/api/tags/label', ['name' => ['en' => 'X'], 'slug' => ['en' => 'x']])->assertUnprocessable()->assertJson(['code' => 'invalid_tag']);
    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Deep'], 'parent' => '999999'])->assertUnprocessable()->assertJson(['code' => 'tag_not_found']);
    $this->postJson('/api/tags/genre/jazz/move', [])->assertUnprocessable()->assertJsonValidationErrors('parent');
    $this->postJson('/api/tags/genre/reorder', ['order' => ['999999']])->assertUnprocessable()->assertJson(['code' => 'tag_not_found']);
    $this->postJson('/api/tags/genre/music/move', ['parent' => (string) $this->jazz->getKey()])->assertUnprocessable()->assertJson(['code' => 'invalid_hierarchy']);
});

it('suggests and lists popular tags', function (): void {
    apiUser();
    Tags::type('label')->make('Live')->create();
    Tags::type('label')->make('Liverpool')->create();
    Article::query()->create()->attachTags(['liverpool'], 'label');

    $this->getJson('/api/tags/label/suggest?q=liv')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.slug', 'liverpool');
    $this->getJson('/api/tags/label/suggest?q=liv&limit=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/tags/label/suggest')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/tags/label/suggest?q='.str_repeat('a', 65))->assertUnprocessable();
    $this->getJson('/api/tags/label/suggest?q=a&limit=51')->assertUnprocessable();

    $this->getJson('/api/tags/label/popular')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'liverpool');
    $this->getJson('/api/tags/label/popular?on=article&limit=5')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/tags/label/popular?on=note')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/tags/label/popular?on='.urlencode(Article::class))->assertUnprocessable()->assertJson(['code' => 'unknown_alias']);
});

test('index_has_no_n_plus_one', function (): void {
    apiUser(admin: true);
    $team = Team::query()->create(['name' => 'Acme']);

    foreach (range(1, 25) as $index) {
        Tags::type('label')->within($index % 2 === 0 ? $team : null)->make("Label {$index}")->create();
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/tags/label?scope=team:'.$team->getKey().'&per_page=50')->assertOk()->assertJsonCount(25, 'data');
    $wide = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->getJson('/api/tags/label?scope=team:'.$team->getKey().'&per_page=5')->assertOk()->assertJsonCount(5, 'data');
    $narrow = count(DB::getQueryLog());

    expect($wide)->toBe($narrow)->and($wide)->toBeLessThanOrEqual(8);
});

test('suggest_popular_and_reorder_have_no_n_plus_one', function (): void {
    apiUser(admin: true);
    Tags::define('topic');
    $team = Team::query()->create(['name' => 'Acme']);
    $article = ScopedArticle::query()->create(['title' => 'A', 'team_id' => $team->getKey()]);
    $topics = Tags::type('topic')->within($team);
    $scope = 'scope=team:'.$team->getKey().'&scope_only=1';
    $queries = static function (Closure $call): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $measure = function () use ($topics, $scope, $queries): array {
        // The current order: a reorder that moves nothing writes nothing, so only reads count.
        $order = $topics->all()->map(fn (Tag $tag): string => (string) $tag->getKey())->values()->all();

        return [
            $queries(fn () => $this->getJson("/api/tags/topic/suggest?q=rock&limit=50&{$scope}")->assertOk()),
            $queries(fn () => $this->getJson("/api/tags/topic/popular?limit=50&{$scope}")->assertOk()),
            $queries(fn () => $this->postJson("/api/tags/topic/reorder?{$scope}", ['parent' => null, 'order' => $order])->assertOk()),
        ];
    };

    $article->attachTags($topics->make('Rock 0')->create(), 'topic');
    $measure(); // the first reorder creates the tree's lock row
    $one = $measure();

    foreach (range(1, 20) as $index) {
        $article->attachTags($topics->make("Rock {$index}")->create(), 'topic');
    }

    expect($this->getJson("/api/tags/topic/popular?limit=50&{$scope}")->json('data'))->toHaveCount(21)
        ->and($measure())->toBe($one);
});

test('suggest_is_throttled', function (): void {
    // The limiter reads its limit per request.
    config()->set('tags.routes.suggest_rate_limit', 2);
    apiUser();

    $this->getJson('/api/tags/label/suggest?q=a')->assertOk();
    $this->getJson('/api/tags/label/suggest?q=a')->assertOk();
    $this->getJson('/api/tags/label/suggest?q=a')->assertStatus(429);
});

test('errors_render_code_and_message', function (): void {
    apiUser(admin: true);

    $this->getJson('/api/tags/nope')->assertNotFound()->assertExactJson(['message' => 'Unknown tag type [nope].', 'code' => 'unknown_tag_type']);
    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Jazz']])->assertStatus(409)->assertExactJson([
        'message' => 'A tag with the slug [jazz] in [en] already exists.',
        'code' => 'duplicate_tag',
    ]);
});

it('keeps every tag key valid on a key-type mismatch', function (): void {
    apiUser();

    $this->getJson('/api/tags/genre/'.str_repeat('9', 30))->assertNotFound();
    expect(Tag::query()->count())->toBe(2);
});
