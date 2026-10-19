<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Resources\TagResource;
use RoundlyConsulting\Tags\Http\Resources\TagScopeResource;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\HostScopeResource;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    Tags::define('label');
    $this->acme = Team::query()->create(['name' => 'Acme']);
    $this->globex = Team::query()->create(['name' => 'Globex']);
    $this->global = Tags::type('label')->make('Printer')->create();
    $this->ours = Tags::type('label')->within($this->acme)->make('Scanner')->create();
    $this->theirs = Tags::type('label')->within($this->globex)->make('Fax')->create();
});

test('scope_param_accepts_aliases_only', function (): void {
    defineTagGates();
    apiUser();

    $this->getJson('/api/tags/label?scope=team:'.$this->acme->getKey())->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/tags/label?scope=team:'.$this->acme->getKey().'&scope_only=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/tags/label?scope='.urlencode(Team::class.':'.$this->acme->getKey()))->assertUnprocessable()->assertJson(['code' => 'unknown_alias']);
    $this->getJson('/api/tags/label?scope=user:1')->assertUnprocessable()->assertJson(['code' => 'unknown_alias']);
    $this->getJson('/api/tags/label?scope=team')->assertUnprocessable()->assertJson(['code' => 'unknown_alias']);
});

test('malformed_keys_never_500', function (string $reference): void {
    defineTagGates();
    apiUser();

    $this->getJson('/api/tags/label?scope='.urlencode($reference))->assertUnprocessable()->assertJson(['code' => 'unknown_alias']);
})->with(['team:abc', 'team:', 'team:1.5', 'team:999999', "team:1' or 1=1", 'team:'.str_repeat('9', 40), 'team:9223372036854775808', 'team:9999999999999999999']);

// 19 digits above the bigint range fit the digit pattern but no bigint column: PostgreSQL
// answers them with an error, not an empty result.
test('oversized_tag_keys_never_500', function (): void {
    defineTagGates();
    apiUser(admin: true);
    Tags::define('genre')->hierarchical();
    $root = Tags::type('genre')->make('Root')->create();
    $huge = '9999999999999999999';

    $this->getJson("/api/tags/label/{$huge}")->assertNotFound()->assertJson(['code' => 'tag_not_found']);
    $this->getJson("/api/tags/label?filter[parent]={$huge}")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/tags/label?filter[ids]={$huge}")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Child'], 'parent' => $huge])->assertUnprocessable()->assertJson(['code' => 'tag_not_found']);
    $this->postJson("/api/tags/genre/{$root->getKey()}/move", ['parent' => $huge])->assertUnprocessable()->assertJson(['code' => 'tag_not_found']);
    $this->postJson('/api/tags/genre/reorder', ['parent' => $huge, 'order' => []])->assertUnprocessable()->assertJson(['code' => 'tag_not_found']);

    expect(Tags::find($huge))->toBeNull()
        ->and(Tags::find('9223372036854775807'))->toBeNull()
        ->and(Tags::type('label')->findMany([$huge])->count())->toBe(0);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('scope_param_requires_the_scope_ability', function (): void {
    Gate::define('tags.scope', static fn (User $user, Model $scope): bool => $scope->getKey() === test()->acme->getKey());
    apiUser();

    $this->getJson('/api/tags/label?scope=team:'.$this->acme->getKey())->assertOk();
    $this->getJson('/api/tags/label?scope=team:'.$this->globex->getKey())->assertForbidden();
});

test('foreign_scope_tags_answer_like_missing_ones', function (): void {
    defineTagGates();
    apiUser(admin: true);
    $acme = 'scope=team:'.$this->acme->getKey();

    $missing = $this->getJson("/api/tags/label/nope?{$acme}")->assertNotFound()->json();
    $foreign = $this->getJson("/api/tags/label/{$this->theirs->getKey()}?{$acme}")->assertNotFound()->json();
    $foreignSlug = $this->getJson("/api/tags/label/fax?{$acme}")->assertNotFound()->json();

    expect($foreign)->toBe($missing)->and($foreignSlug)->toBe($missing);

    // A body reference to another scope's tag: 422 tag_not_found, never a scope mismatch.
    $this->postJson("/api/tags/label/reorder?{$acme}", ['order' => [(string) $this->theirs->getKey()]])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'The tag does not exist.', 'code' => 'tag_not_found']);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

test('manage_requires_the_ability_per_type', function (): void {
    Tags::define('genre')->hierarchical();
    Gate::define('tags.manage', static fn (User $user, string $type): bool => $type === 'label');
    apiUser();

    $this->postJson('/api/tags/label', ['name' => ['en' => 'Live']])->assertCreated();
    $this->postJson('/api/tags/genre', ['name' => ['en' => 'Jazz']])->assertForbidden();
});

test('manage_gate_receives_the_target_scope', function (): void {
    $calls = [];
    Gate::define('tags.manage', static function (User $user, string $type, ?Model $scope = null) use (&$calls): bool {
        $calls[] = $type.':'.($scope?->getKey() ?? 'global');

        return $scope !== null;
    });
    Gate::define('tags.scope', static fn (): bool => true);
    apiUser();

    $this->postJson('/api/tags/label?scope=team:'.$this->acme->getKey(), ['name' => ['en' => 'Ours']])->assertCreated();
    // A tenant admin cannot create a global tag every tenant sees.
    $this->postJson('/api/tags/label', ['name' => ['en' => 'Everyone']])->assertForbidden();

    expect($calls)->toContain('label:'.$this->acme->getKey(), 'label:global');
});

test('a_scoped_handle_never_writes_global_tags', function (): void {
    defineTagGates();
    apiUser(admin: true);
    $acme = 'scope=team:'.$this->acme->getKey();

    // Readable through the scope, never writable through it.
    $this->getJson("/api/tags/label/printer?{$acme}")->assertOk();
    $this->patchJson("/api/tags/label/printer?{$acme}", ['name' => ['en' => 'Copier']])->assertNotFound();
    $this->deleteJson("/api/tags/label/printer?{$acme}")->assertNotFound();

    expect($this->global->fresh()?->name)->toBe('Printer');
});

test('undefined_abilities_deny', function (): void {
    apiUser(admin: true);

    $this->postJson('/api/tags/label', ['name' => ['en' => 'Live']])->assertForbidden();
    $this->getJson('/api/tags/label?scope=team:'.$this->acme->getKey())->assertForbidden();
    // Reads need no ability unless `view` names one.
    $this->getJson('/api/tags/label')->assertOk();

    config()->set('tags.authorization.abilities.view', 'tags.view');

    $this->getJson('/api/tags/label')->assertForbidden();

    Gate::define('tags.view', static fn (User $user, string $type): bool => $type === 'label');

    $this->getJson('/api/tags/label')->assertOk();
});

test('private_types_are_invisible', function (): void {
    Tags::define('internal')->private();
    Tags::type('internal')->make('Secret')->create();
    Gate::define('tags.scope', static fn (): bool => true);
    apiUser();

    $types = collect($this->getJson('/api/tag-types')->assertOk()->json('data'))->pluck('name')->all();

    expect($types)->not->toContain('internal');

    foreach (['/api/tag-types/internal', '/api/tags/internal', '/api/tags/internal/secret', '/api/tags/internal/suggest?q=s', '/api/tags/internal/popular'] as $url) {
        $this->getJson($url)->assertNotFound()->assertJson(['code' => 'unknown_tag_type']);
    }

    $this->postJson('/api/tags/internal', ['name' => ['en' => 'X']])->assertNotFound()->assertJson(['code' => 'unknown_tag_type']);
});

test('private_types_are_visible_to_their_managers_only', function (): void {
    Tags::define('internal')->private();
    Tags::type('internal')->make('Secret')->create();
    Gate::define('tags.manage', static fn (User $user, string $type): bool => $user->is_admin && $type === 'internal');
    apiUser(admin: true);

    $types = collect($this->getJson('/api/tag-types')->assertOk()->json('data'))->pluck('name')->all();

    expect($types)->toContain('internal');

    $this->getJson('/api/tags/internal')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/tags/internal/secret')->assertOk();
    $this->postJson('/api/tags/internal', ['name' => ['en' => 'Also secret']])->assertCreated();
});

test('usage_count_is_rendered_to_managers_only', function (): void {
    defineTagGates();

    apiUser();
    $this->getJson('/api/tags/label')->assertOk()->assertJsonMissingPath('data.0.usage_count');

    apiUser(admin: true);
    $this->getJson('/api/tags/label')->assertOk()->assertJsonPath('data.0.usage_count', 0);
});

test('usage_count_gate_runs_once_per_type_and_scope', function (): void {
    $calls = 0;
    Gate::define('tags.manage', static function () use (&$calls): bool {
        $calls++;

        return true;
    });
    apiUser();

    foreach (range(1, 10) as $index) {
        Tags::type('label')->make("Extra {$index}")->create();
    }

    $this->getJson('/api/tags/label')->assertOk()->assertJsonCount(11, 'data');

    expect($calls)->toBe(1);
});

// OQ-38: a global tag's count adds up every tenant's taggings. A team admin who manages its own
// team's tags — and whom the host's gate keeps off global ones — sees the counts of its team's
// tags only, and lists deleted tags of its team only.
test('a_tenant_manager_never_sees_global_usage_counts', function (): void {
    $ours = ScopedArticle::query()->create(['title' => 'A', 'team_id' => $this->acme->getKey()]);
    $ours->attachTags([$this->ours], 'label');

    foreach (range(1, 3) as $index) {
        ScopedArticle::query()->create(['title' => "G{$index}", 'team_id' => $this->globex->getKey()])->attachTags([$this->global], 'label');
    }

    $gone = Tags::type('label')->make('Gone global')->create();
    Tags::type('label')->delete($gone);
    $goneOurs = Tags::type('label')->within($this->acme)->make('Gone ours')->create();
    Tags::type('label')->within($this->acme)->delete($goneOurs);

    $admin = User::query()->create(['name' => 'Acme admin', 'team_id' => $this->acme->getKey()]);
    $this->actingAs($admin);
    Gate::define('tags.manage', static fn (User $user, string $type, ?Model $scope = null): bool => $scope !== null && $scope->getKey() === $user->team_id);
    Gate::define('tags.scope', static fn (User $user, Model $scope): bool => $scope->getKey() === $user->team_id);
    $acme = 'scope=team:'.$this->acme->getKey();

    $rows = collect($this->getJson("/api/tags/label?{$acme}")->assertOk()->json('data'))->keyBy('slug');

    expect($rows['printer'])->not->toHaveKey('usage_count')
        ->and($rows['scanner']['usage_count'])->toBe(1);

    foreach (["/api/tags/label/suggest?q=printer&{$acme}", "/api/tags/label/popular?{$acme}", "/api/tags/label/printer?{$acme}"] as $url) {
        $printer = collect($this->getJson($url)->assertOk()->json('data'))->pipe(
            static fn ($data) => $data->has('slug') ? $data->all() : $data->firstWhere('slug', 'printer'),
        );

        expect($printer)->not->toHaveKey('usage_count');
    }

    $trashed = collect($this->getJson("/api/tags/label?filter[trashed]=only&{$acme}")->assertOk()->json('data'))->pluck('slug')->all();
    $with = collect($this->getJson("/api/tags/label?filter[trashed]=with&{$acme}")->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();

    expect($trashed)->toBe(['gone-ours'])
        ->and($with)->toBe(['gone-ours', 'printer', 'scanner']);
});

test('a_tag_resource_outside_the_api_asks_the_tags_own_scope', function (): void {
    $asked = [];
    Gate::define('tags.manage', static function (User $user, string $type, ?Model $scope = null) use (&$asked): bool {
        $asked[] = $scope?->getKey();

        return $scope !== null;
    });
    $user = User::query()->create(['name' => 'Host']);
    $request = static function () use ($user): Request {
        $request = Request::create('/host');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };

    $ours = (new TagResource($this->ours))->resolve($request());
    $global = (new TagResource($this->global))->resolve($request());
    $this->acme->delete();
    $orphan = (new TagResource($this->ours->fresh()))->resolve($request());

    expect($ours)->toHaveKey('usage_count')
        ->and($global)->not->toHaveKey('usage_count')
        ->and($orphan)->not->toHaveKey('usage_count')
        ->and($asked)->toBe([$this->acme->getKey(), null]);
});

test('translations_include_needs_manage', function (): void {
    defineTagGates();

    apiUser();
    $this->getJson('/api/tags/label?include=translations')->assertOk()->assertJsonMissingPath('data.0.translations');

    apiUser(admin: true);
    $this->getJson('/api/tags/label?include=translations')->assertOk()->assertJsonPath('data.0.translations.name.en', 'Printer');
    $this->getJson('/api/tags/label')->assertOk()->assertJsonMissingPath('data.0.translations');
});

test('resources_leak_no_class_names', function (): void {
    defineTagGates();
    apiUser(admin: true);

    $json = $this->getJson('/api/tags/label?scope=team:'.$this->acme->getKey().'&include=translations')->assertOk()->getContent();
    $tree = $this->getJson('/api/tag-types')->getContent();

    expect($json)->not->toContain('\\\\')->not->toContain('RoundlyConsulting')->not->toContain('scope_key')->not->toContain('scope_type')
        ->and($json)->toContain('"scope":{"id":'.json_encode($this->acme->getKey()).',"type":"Team"}')
        ->and($tree)->not->toContain('\\\\');
});

test('scope_resource_swap_is_honoured', function (): void {
    config()->set('tags.resources.scope', HostScopeResource::class);

    $rendered = (new TagResource($this->ours))->resolve(Request::create('/'));

    expect($rendered['scope'])->toBe(['id' => $this->acme->getKey(), 'name' => 'Acme']);
});

test('a_bad_scope_resource_config_throws_naming_the_key', function (mixed $value): void {
    config()->set('tags.resources.scope', $value);

    expect(fn () => TagsConfig::scopeResource())->toThrow(InvalidConfigurationException::class, 'tags.resources.scope');
})->with([
    'not a resource' => [Team::class],
    'the bare base' => [JsonResource::class],
    'a missing class' => ['Nope\\Missing'],
    'not a string' => [5],
]);

test('a_blank_scope_resource_falls_back', function (?string $value): void {
    config()->set('tags.resources.scope', $value);

    expect(TagsConfig::scopeResource())->toBe(TagScopeResource::class);
})->with(['null' => [null], 'empty' => [''], 'whitespace' => ['  ']]);
