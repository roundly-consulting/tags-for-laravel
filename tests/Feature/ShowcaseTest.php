<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tags\Actions\Tagging\AttachTagsAction;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;
use RoundlyConsulting\Tags\Rules\TagsRule;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Testing\TagExpectations;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\EventTagType;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Types\TagTypeBuilder;

/**
 * The plan's DX showcase (§1.2–§1.10), call for call, on the suite's fixtures: Article stands in
 * for the event model, ScopedArticle for a ticket, Team for a project.
 */
function defineEventTypes(): void
{
    app('translator')->addLines(['tags.category' => 'Category', 'tags.label' => 'Label'], 'en', 'events');
    app('translator')->addLines(['tags.category' => 'Kategória', 'tags.label' => 'Označenie'], 'sk', 'events');

    // EventsServiceProvider::boot()
    Tags::define(EventTagType::Category)
        ->label('events::tags.category')      // a key in events' own en + sk lang files, translated on every read
        ->hierarchical(maxDepth: 3)
        ->single()
        ->stableSlugs()                       // /events?category=jazz never moves
        ->on(Article::class);

    Tags::define(EventTagType::Label)
        ->label('events::tags.label')
        ->max(10)
        ->on(Article::class);
}

it('runs §1.2: a package registers its types', function (): void {
    defineEventTypes();

    $category = Tags::type(EventTagType::Category)->definition();

    expect($category->label())->toBe('Category')
        ->and($category->label('sk'))->toBe('Kategória')
        ->and($category->maxDepth)->toBe(3)
        ->and($category->isSingle())->toBeTrue()
        ->and($category->stableSlugs)->toBeTrue()
        ->and($category->curated)->toBeTrue()
        ->and(Tags::type(EventTagType::Label)->definition()->max)->toBe(10);
});

it('runs §1.3: the host adjusts a package type, or adds its own', function (): void {
    config()->set('tags.overrides', ['events:label' => ['curated' => true, 'max' => 5]]);
    config()->set('tags.types', ['skill' => ['hierarchical' => true, 'max_depth' => 2, 'on' => [Note::class]]]);
    defineEventTypes();

    // or in code (AppServiceProvider::boot): applied after the package's define(), whatever the provider order
    Tags::extend(EventTagType::Label, fn (TagTypeBuilder $type) => $type->globalOnly());

    $label = Tags::type(EventTagType::Label)->definition();

    expect($label->curated)->toBeTrue()
        ->and($label->max)->toBe(5)
        ->and($label->globalOnly)->toBeTrue()
        ->and(Tags::type('skill')->definition()->maxDepth)->toBe(2);

    Artisan::call('tags:types');

    expect(Artisan::output())->toContain('events:label')->toContain('extend #1, config overrides')->toContain('skill');
});

it('runs §1.4: tenant-scoped tags', function (): void {
    $project = Team::query()->create(['name' => 'Project']);
    $other = Team::query()->create(['name' => 'Other']);
    $ticket = ScopedArticle::query()->create(['team_id' => $project->getKey()]);
    $tagOfAnotherProject = Tags::type('tag')->within($other)->make('Theirs')->create();

    $ticket->attachTags('printer');            // the project's "printer", else the global one, else a new project tag

    expect(Tags::type('tag')->within($project)->all()->pluck('slug')->all())->toBe(['printer'])
        ->and(fn () => $ticket->attachTags($tagOfAnotherProject))->toThrow(TagScopeMismatchException::class);
});

it('runs §1.5: inputs, limits and the one-line category', function (): void {
    defineEventTypes();
    $event = Article::query()->create();
    $live = Tags::type(EventTagType::Label)->make('Live')->create();
    $fortyTwo = Tags::type(EventTagType::Label)->make('Forty two')->create();
    Tags::type(EventTagType::Category)->make('Jazz')->create();
    Tags::type(EventTagType::Category)->make('Rock')->create();

    $event->attachTags([$live, (int) $fortyTwo->getKey(), 'outdoor', 'Late night'], EventTagType::Label); // model, id, slug, name
    $event->syncTags('jazz', EventTagType::Category);     // single type: sets the category

    expect(fn () => $event->attachTags('rock', EventTagType::Category))->toThrow(TagLimitExceededException::class)
        ->and(fn () => $event->attachTags('Polka', EventTagType::Category))->toThrow(TagNotFoundException::class)
        ->and($event->tagsOf(EventTagType::Label)->pluck('slug')->sort()->values()->all())->toBe(['forty-two', 'late-night', 'live', 'outdoor']);

    $event->syncTags(null, EventTagType::Category);       // clears it

    $changes = Tags::attach($event, ['live'], EventTagType::Label);   // TagChanges: what really changed

    expect($event->tagOf(EventTagType::Category))->toBeNull()
        ->and($changes->isEmpty())->toBeTrue();                       // true when "live" was already there
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'int keys');

it('runs §1.6: category trees', function (): void {
    defineEventTypes();
    $categories = Tags::type(EventTagType::Category);
    $music = $categories->make('Music')->create();
    $jazz = $categories->make('Jazz')->under($music)->create();
    $bebop = $categories->make('Bebop')->under($jazz)->create();
    $rock = $categories->make('Rock')->under($music)->create();
    $pop = $categories->make('Pop')->under($music)->create();

    $tree = Tags::type(EventTagType::Category)->tree();             // list<TagNode>, one query
    $breadcrumb = $bebop->ancestors()->push($bebop)->pluck('name')->join(' › ');  // Music › Jazz › Bebop (one query)

    $categories->move($bebop, under: $music, position: 0);
    $categories->reorder($music, [$rock, $jazz, $pop]);
    $categories->delete($jazz, children: TagChildren::Reparent);    // default refuses while it has children

    expect($tree[0]->tag->slug)->toBe('music')
        ->and($breadcrumb)->toBe('Music › Jazz › Bebop')
        ->and($music->children()->get()->pluck('slug')->all())->toBe(['rock', 'pop', 'bebop'])
        ->and($jazz->fresh()?->trashed())->toBeTrue();
});

it('runs §1.7: querying', function (): void {
    defineEventTypes();
    $music = Tags::type(EventTagType::Category)->make('Music')->create();
    $jazz = Tags::type(EventTagType::Category)->make('Jazz')->under($music)->create();
    $match = Article::query()->create(['title' => 'match']);
    $match->syncTags($jazz, EventTagType::Category)->attachTags(['outdoor', 'free'], EventTagType::Label);
    Article::query()->create(['title' => 'sold out'])->syncTags($jazz, EventTagType::Category)->attachTags(['outdoor', 'free', 'sold-out'], EventTagType::Label);
    Article::query()->create(['title' => 'bare']);

    $page = Article::withAnyTags(['music'], EventTagType::Category, descendants: true)   // Music, Jazz, Bebop, …
        ->withAllTags(['outdoor', 'free'], EventTagType::Label)
        ->withoutTags(['sold-out'], EventTagType::Label)
        ->paginate();

    expect($page->pluck('title')->all())->toBe(['match'])
        ->and(Article::withoutTags(type: EventTagType::Category)->count())->toBe(1)   // events with no category yet
        ->and(Article::withTagsOf(EventTagType::Label)->count())->toBe(2);            // events with at least one label
});

it('runs §1.8: the host endpoints, validation rule and query-builder filter', function (): void {
    defineEventTypes();
    $team = Team::query()->create(['name' => 'Team']);
    $music = Tags::type(EventTagType::Category)->make('Music')->create();
    Tags::type(EventTagType::Category)->make('Jazz')->under($music)->create();
    $event = Article::query()->create(['title' => 'event']);

    $validated = Validator::make(
        ['category' => 'jazz', 'labels' => ['live', 'outdoor']],
        [
            'category' => ['nullable', TagsRule::for(EventTagType::Category)],
            'labels' => ['array', TagsRule::for(EventTagType::Label)->within($team)],
        ],
    )->validate();

    // Controller
    $event->syncTags($validated['category'], EventTagType::Category)
        ->syncTags($validated['labels'] ?? [], EventTagType::Label);

    // Index endpoint: ?filter[category]=music&filter[labels]=live,outdoor
    $request = Request::create('/events', 'GET', ['filter' => ['category' => 'music', 'labels' => 'live,outdoor']]);
    $found = QueryBuilder::for(Article::class, $request)->allowedFilters(
        AllowedFilter::custom('category', TagsFilter::any(EventTagType::Category)->withDescendants()),
        AllowedFilter::custom('labels', TagsFilter::all(EventTagType::Label)),
    )->pluck('title')->all();

    expect($found)->toBe(['event']);
});

it('runs §1.9: the fake in a test', function (): void {
    defineEventTypes();
    TagExpectations::register();
    $tags = Tags::fake();

    $event = Article::query()->create();
    $event->attachTags(['live'], EventTagType::Label);   // what CreateEventAction does inside

    $tags->assertTagged($event, ['live'], type: EventTagType::Label);
    $tags->assertNothingUntagged();
    expect($event)->toHaveTags(['live'], EventTagType::Label);
});

it('runs §1.10: one API, three styles', function (): void {
    defineEventTypes();
    $event = Article::query()->create();

    Tags::for($event)->attach(['live'], EventTagType::Label);                       // facade
    app(TagsManager::class)->for($event)->attach(['outdoor'], EventTagType::Label); // dependency injection
    app(AttachTagsAction::class)->execute($event, TagSelection::of(['free'], EventTagType::Label));   // action

    expect($event->tagsOf(EventTagType::Label)->pluck('slug')->sort()->values()->all())->toBe(['free', 'live', 'outdoor']);
});
