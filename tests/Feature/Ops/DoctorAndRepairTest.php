<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Sluggable\Support\IndexNames;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Tags\DataTransferObjects\RepairReport;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Support\Doctor;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\ScopedArticle;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\UuidArticle;
use RoundlyConsulting\Tags\Tests\Support\RecordsLocks;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;

/**
 * @return list<array{severity: string, section: string, message: string}>
 */
function doctorFindings(array $options = []): array
{
    Artisan::call('tags:doctor', ['--json' => true, ...$options]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    Tags::define('genre')->hierarchical()->single();
    $this->music = Tags::type('genre')->make('Music')->create();
    $this->jazz = Tags::type('genre')->make('Jazz')->under($this->music)->create();
    $this->bebop = Tags::type('genre')->make('Bebop')->under($this->jazz)->create();
});

it('finds nothing on healthy data', function (): void {
    Article::query()->create()->syncTags($this->jazz, 'genre');

    $this->artisan('tags:doctor')->expectsOutputToContain('No problems found.')->assertSuccessful();
    expect(doctorFindings(['--counts' => true]))->toBe([]);
});

test('doctor_reports_and_repair_fixes_bad_paths', function (): void {
    // A parent set past the package: paths and depths no longer match.
    Tag::query()->whereKey($this->bebop->getKey())->toBase()->update(['path' => '/', 'depth' => 1]);
    Tag::query()->whereKey($this->jazz->getKey())->toBase()->update(['depth' => 7]);

    $findings = doctorFindings();

    expect(collect($findings)->pluck('message')->join(' '))->toContain('2 tag(s) have a path or depth')
        ->and($findings[0]['severity'])->toBe('error');

    $this->artisan('tags:doctor')->assertFailed();

    $dry = Tags::repair(new RepairOptions(dryRun: true));

    expect($dry->paths)->toBe(2)->and($dry->dryRun)->toBeTrue()->and($this->bebop->fresh()?->depth)->toBe(1);

    $this->artisan('tags:repair', ['--paths' => true])->expectsOutputToContain('paths')->assertSuccessful();

    expect($this->bebop->fresh()?->path)->toBe('/'.$this->music->getKey().'/'.$this->jazz->getKey().'/')
        ->and($this->bebop->fresh()?->depth)->toBe(3)
        ->and($this->jazz->fresh()?->depth)->toBe(2)
        ->and(doctorFindings())->toBe([]);
});

it('reports cycles without placing them', function (): void {
    Tag::query()->whereKey($this->music->getKey())->toBase()->update(['parent_id' => $this->bebop->getKey()]);

    $report = Tags::repair(new RepairOptions(scopeKeys: false, counts: false, orphans: false));

    expect($report->cycles)->toBe(3)
        ->and($report->isClean())->toBeFalse()
        ->and(collect(doctorFindings())->pluck('message')->join(' '))->toContain('parent cycle');
});

it('reports and repairs scope key drift', function (): void {
    $team = Team::query()->create(['name' => 'Acme']);
    $scoped = Tags::type('tag')->within($team)->make('Ours')->create();
    Tag::query()->whereKey($scoped->getKey())->toBase()->update(['scope_key' => 'wrong']);

    expect(collect(doctorFindings())->pluck('section')->all())->toContain('scopes');

    $this->artisan('tags:repair', ['--scope-keys' => true])->assertSuccessful();

    expect($scoped->fresh()?->scope_key)->not->toBe('wrong')->and(doctorFindings())->toBe([]);
});

test('repair_recounts_drifted_counts', function (): void {
    Article::query()->create()->syncTags($this->jazz, 'genre');
    Tag::query()->whereKey($this->jazz->getKey())->toBase()->update(['usage_count' => 9]);
    Tag::query()->whereKey($this->music->getKey())->toBase()->update(['usage_count' => 4]);

    expect(doctorFindings())->toBe([])
        ->and(collect(doctorFindings(['--counts' => true]))->pluck('message')->join(' '))->toContain('2 tag(s) have a usage count');

    $this->artisan('tags:repair', ['--counts' => true, '--type' => 'genre'])->assertSuccessful();

    expect($this->jazz->fresh()?->usage_count)->toBe(1)->and($this->music->fresh()?->usage_count)->toBe(0);
});

test('repair_removes_orphan_taggings', function (): void {
    $kept = Article::query()->create();
    $gone = Article::query()->create();
    $kept->attachTags('live');
    $gone->attachTags('live');
    $trashed = Article::query()->create();
    $trashed->attachTags('live');
    $trashed->delete();

    // A mass delete fires no model event: the tagging stays behind.
    Article::query()->whereKey($gone->getKey())->forceDelete();
    Tagging::query()->insert(['tag_id' => Tag::query()->where('slug->en', 'live')->value('id'), 'taggable_type' => 'Gone\\Model', 'taggable_id' => 1, 'type' => 'tag']);

    expect(collect(doctorFindings())->pluck('section')->all())->toContain('orphans');

    $dry = Tags::repair(new RepairOptions(paths: false, scopeKeys: false, counts: false, dryRun: true));

    expect($dry->orphans)->toBe(1)->and($dry->unresolvableTypes)->toBe(['Gone\\Model']);

    $this->artisan('tags:repair', ['--orphans' => true, '--counts' => true])
        ->expectsOutputToContain('it is no model class')
        ->assertSuccessful();

    expect(Tagging::query()->where('taggable_type', $kept->getMorphClass())->count())->toBe(2)
        ->and(Tag::query()->where('slug->en', 'live')->firstOrFail()->usage_count)->toBe(3);
})->skip(fn (): bool => config('tags.primary_key_type') !== 'bigint', 'raw rows');

test('doctor_reports_taggings_on_deleted_tags', function (): void {
    Article::query()->create()->attachTags(['rock', 'pop']);
    Tags::type('tag')->findOrFail('rock')->delete();

    $finding = collect(doctorFindings())->firstWhere('section', 'trashed');

    expect($finding)->toBe([
        'severity' => 'error',
        'section' => 'trashed',
        'message' => '1 tagging(s) point at deleted tags: run tags:repair --orphans.',
    ]);

    $this->artisan('tags:doctor')->assertFailed();
});

test('doctor_warns_about_live_tags_under_a_deleted_parent', function (): void {
    $this->jazz->delete();

    $findings = collect(doctorFindings());

    expect($findings->where('section', 'trees')->values()->all())->toBe([[
        'severity' => 'warning',
        'section' => 'trees',
        'message' => '1 live tag(s) sit under a deleted parent: restore the parent or move them.',
    ]]);
});

test('repair_orphans_removes_taggings_on_deleted_tags', function (bool $dryRun): void {
    $article = Article::query()->create();
    $article->attachTags(['rock', 'pop']);
    $rock = Tags::type('tag')->findOrFail('rock');
    $rock->delete();

    $report = Tags::repair(new RepairOptions(paths: false, scopeKeys: false, counts: false, orphans: true, dryRun: $dryRun));

    expect($report->trashedTaggings)->toBe(1)
        ->and($report->orphans)->toBe(0)
        ->and($report->isClean())->toBeFalse()
        ->and(Tagging::query()->count())->toBe($dryRun ? 2 : 1)
        ->and(Tag::query()->withTrashed()->findOrFail($rock->getKey())->usage_count)->toBe($dryRun ? 1 : 0)
        ->and($article->tagsOf('tag')->pluck('slug')->all())->toBe(['pop']);

    if (! $dryRun) {
        expect(Tags::repair(new RepairOptions(dryRun: true))->isClean())->toBeTrue();
    }
})->with(['dry run' => [true], 'repair' => [false]]);

it('prints taggings on deleted tags in the repair report', function (): void {
    Article::query()->create()->attachTags(['rock']);
    Tags::type('tag')->findOrFail('rock')->delete();

    $this->artisan('tags:repair', ['--orphans' => true])
        ->expectsTable(['Repair', 'Fixed'], [
            ['paths', '0'],
            ['cycles (left alone)', '0'],
            ['scope keys', '0'],
            ['orphan taggings', '0'],
            ['taggings on deleted tags', '1'],
            ['usage counts', '0'],
        ])
        ->assertSuccessful();

    expect(Tagging::query()->count())->toBe(0);
});

test('doctor_reports_missing_locale_indexes', function (): void {
    $table = 'tags';
    DB::statement('drop index '.(DB::getDriverName() === 'mysql' ? '`'.IndexNames::index($table, 'slug', 'sk').'` on `tags`' : '"'.IndexNames::index($table, 'slug', 'sk').'"'));

    expect(collect(doctorFindings())->pluck('message')->join(' '))->toContain('No unique slug index for the locale [sk]');
});

it('reports the type problems', function (): void {
    Tags::extend('genere', static fn ($type) => $type);
    config()->set('tags.overrides', ['lable' => ['max' => 2]]);
    Tags::define('broken')->max(0);
    Tag::factory()->ofType('gone')->create();

    $messages = collect(doctorFindings())->pluck('message')->join(' ');

    expect($messages)->toContain('undefined type [gone]')
        ->toContain('adjusts [genere]')
        ->toContain('adjusts [lable]')
        ->toContain('max below 1');
});

it('reports models over a lowered max and parents on a flat type', function (): void {
    Tags::define('label')->max(3);
    Article::query()->create()->attachTags(['a', 'b', 'c'], 'label');
    config()->set('tags.overrides', ['label' => ['max' => 2], 'genre' => ['hierarchical' => false]]);
    Tags::extend('label', static fn ($type) => $type);
    Tags::extend('genre', static fn ($type) => $type);

    $messages = collect(doctorFindings())->pluck('message')->join(' ');

    expect($messages)->toContain('1 model(s) carry more [label] tags than its max of 2')
        ->toContain('flat type [genre] have parents');
});

it('reports key-type mismatches and other connections', function (): void {
    Tags::define('label')->on(UuidArticle::class, Note::class);

    $findings = collect(doctorFindings());

    expect($findings->pluck('message')->join(' '))->toContain('UuidArticle is keyed by uuid, but tags.key_type is bigint')
        ->and($findings->where('section', 'keys'))->toHaveCount(1);
})->skip(fn (): bool => config('tags.key_type') !== 'bigint', 'the bigint leg');

it('warns about taggings outside their model scope and an unguarded API', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);
    $article = ScopedArticle::query()->create(['team_id' => $acme->getKey()]);
    $article->attachTags('printer');
    $article->update(['team_id' => $globex->getKey()]);
    config()->set('tags.routes.enabled', 'on');
    config()->set('tags.routes.middleware', ['api']);

    $findings = collect(doctorFindings());

    expect($findings->pluck('message')->join(' '))->toContain("1 tagging(s) sit outside their model's current scope")
        ->toContain('without the auth middleware')
        ->and($findings->every(fn (array $finding): bool => $finding['severity'] === 'warning'))->toBeTrue();

    $this->artisan('tags:doctor')->assertSuccessful();
});

it('records repairs on the fake and prints the report', function (): void {
    $fake = Tags::fake();

    $this->artisan('tags:repair', ['--dry-run' => true])->expectsOutputToContain('Would fix')->assertSuccessful();

    expect($fake->recorded(TagOperation::Repair))->toHaveCount(1)
        ->and(Tags::repair()->isClean())->toBeTrue();
});

test('assert_repaired_pass_and_fail', function (): void {
    $fake = Tags::fake();

    $fake->assertNothingRepaired();

    expect(fn () => $fake->assertRepaired())->toThrow(ExpectationFailedException::class, 'Expected tags to be repaired, but they were not.');

    Tags::repair(new RepairOptions(counts: false, dryRun: true));

    $fake->assertRepaired();
    $fake->assertRepaired(fn (RepairReport $report): bool => $report->dryRun && $report->isClean());

    expect(fn () => $fake->assertRepaired(fn (RepairReport $report): bool => ! $report->dryRun))
        ->toThrow(ExpectationFailedException::class, 'Expected a repair, but none matched the callback.')
        ->and(fn () => $fake->assertNothingRepaired())
        ->toThrow(ExpectationFailedException::class, 'Expected no repairs, but 1 were recorded.');
});

test('assert_repaired_sees_the_command', function (): void {
    $fake = Tags::fake();

    Artisan::call('tags:repair', ['--orphans' => true]);

    $fake->assertRepaired(fn (RepairReport $report): bool => ! $report->dryRun);
});

test('doctor_scope_check_query_count_is_bounded', function (): void {
    $acme = Team::query()->create(['name' => 'Acme']);
    $globex = Team::query()->create(['name' => 'Globex']);

    $queries = function (int $taggings) use ($acme, $globex): int {
        while (Tagging::query()->count() < $taggings) {
            $article = ScopedArticle::query()->create(['team_id' => $acme->getKey()]);
            $article->attachTags('printer');
            Note::query()->create(['body' => 'x'])->attachTags('global');
        }

        // One article moved teams: its tagging sits outside its scope.
        ScopedArticle::query()->orderBy('id')->firstOrFail()->update(['team_id' => $globex->getKey()]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $findings = app(Doctor::class)->run();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(collect($findings)->pluck('message')->join(' '))->toContain("1 tagging(s) sit outside their model's current scope");

        return $count;
    };

    expect($queries(30))->toBe($queries(60));
});

test('repair_counts_lock_tag_rows_before_counting', function (): void {
    Article::query()->create()->attachTags(['live', 'outdoor']);
    Tag::query()->where('type', 'tag')->toBase()->update(['usage_count' => 7]);
    $statements = [];
    RecordsLocks::start();
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $report = Tags::repair(new RepairOptions(paths: false, scopeKeys: false, orphans: false));

    $lock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'lock-for-update') && str_contains($sql, '"tags"'));
    $count = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, '"taggables"'));

    expect($report->counts)->toBe(2)
        ->and($lock)->toBeInt()
        ->and($count)->toBeGreaterThan($lock)
        ->and(collect(LockRecorder::recorded())->every(fn (array $recorded): bool => $recorded['transactionDepth'] >= 1))->toBeTrue()
        ->and(Tag::query()->where('type', 'tag')->pluck('usage_count')->all())->toBe([1, 1]);
})->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

test('repair_paths_take_the_tree_lock', function (): void {
    Tag::query()->whereKey($this->bebop->getKey())->toBase()->update(['path' => '/', 'depth' => 1]);
    RecordsLocks::start();

    Tags::repair(new RepairOptions(scopeKeys: false, counts: false, orphans: false, dryRun: true));

    expect(RecordsLocks::writes())->toBe([]);

    RecordsLocks::start();

    $report = Tags::repair(new RepairOptions(scopeKeys: false, counts: false, orphans: false));
    $writes = RecordsLocks::writes();

    expect($report->paths)->toBe(1)
        ->and($writes[0])->toBe('lock:tag_locks')
        ->and($writes)->toContain('update:tags')
        ->and(array_search('update:tags', $writes, true))->toBeGreaterThan(0)
        ->and(collect(LockRecorder::recorded())->every(fn (array $recorded): bool => $recorded['transactionDepth'] >= 1))->toBeTrue()
        ->and($this->bebop->fresh()?->depth)->toBe(3);
})->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

test('doctor_reports_an_unsupported_app_locale', function (): void {
    app()->setLocale('de');

    $findings = collect(doctorFindings())->where('section', 'locales')->values()->all();

    expect($findings)->toBe([[
        'severity' => 'error',
        'section' => 'locales',
        'message' => 'The app locale [de] is not one of the supported locales (translatable.locales): tagging by name fails until you add it.',
    ]]);

    $this->artisan('tags:doctor')->assertFailed();

    config()->set('translatable.locales', ['en', 'sk', 'de']);

    expect(collect(doctorFindings())->where('section', 'locales')->all())->toBe([]);
});

test('doctor_warns_about_an_unsupported_fallback_locale', function (): void {
    config()->set('translatable.fallback_locale', 'de');

    $findings = collect(doctorFindings())->where('section', 'locales')->values()->all();

    expect($findings)->toBe([[
        'severity' => 'warning',
        'section' => 'locales',
        'message' => 'The fallback locale [de] (translatable.fallback_locale) is not one of the supported locales (translatable.locales): names never fall back to it.',
    ]]);

    $this->artisan('tags:doctor')->assertSuccessful();
});
