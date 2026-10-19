<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\Support\IndexNames;
use RoundlyConsulting\Tags\Actions\Maintenance\RepairTagsAction;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Tags\DataTransferObjects\ScopedTagging;
use RoundlyConsulting\Tags\Enums\FindingSeverity;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;
use RoundlyConsulting\Tags\Exceptions\TagsException;
use RoundlyConsulting\Tags\Models\Tagging;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;
use Throwable;

/**
 * The checks behind `tags:doctor`: everything the data or the setup can disagree on that a write
 * would not catch. Read-only.
 *
 * @internal
 */
final readonly class Doctor
{
    private const int PAGE = 500;

    public function __construct(
        private TagTypeRegistry $registry,
        private RepairTagsAction $repair,
    ) {}

    /**
     * @return list<DoctorFinding>
     */
    public function run(bool $counts = false): array
    {
        $findings = [];
        $error = static function (string $section, string $message) use (&$findings): void {
            $findings[] = new DoctorFinding(FindingSeverity::Error, $section, $message);
        };
        $warning = static function (string $section, string $message) use (&$findings): void {
            $findings[] = new DoctorFinding(FindingSeverity::Warning, $section, $message);
        };

        $this->types($error);
        $this->limits($error);
        $this->trees($error, $warning);
        $this->indexes($error);
        $this->locales($error, $warning);
        $this->keyTypes($error, $warning);
        $this->scopes($error, $warning);

        $report = $this->repair->execute(new RepairOptions(paths: false, scopeKeys: false, counts: $counts, orphans: true, dryRun: true));

        if ($report->counts > 0) {
            $warning('counts', "{$report->counts} tag(s) have a usage count that does not match their taggings: run tags:repair --counts.");
        }

        if ($report->orphans > 0) {
            $warning('orphans', "{$report->orphans} tagging(s) point at a model that no longer exists: run tags:repair --orphans.");
        }

        if ($report->trashedTaggings > 0) {
            $error('trashed', "{$report->trashedTaggings} tagging(s) point at deleted tags: run tags:repair --orphans.");
        }

        foreach ($report->unresolvableTypes as $type) {
            $error('orphans', "Taggings name the taggable type [{$type}], which is no model class.");
        }

        $middleware = TagsConfig::routesMiddleware();

        if (TagsConfig::routesEnabled() && ! collect($middleware)->contains(static fn (string $entry): bool => $entry === 'auth' || str_starts_with($entry, 'auth:'))) {
            $warning('routes', 'The JSON API is on without the auth middleware in tags.routes.middleware.');
        }

        return $findings;
    }

    /**
     * @param  callable(string, string): void  $error
     */
    private function types(callable $error): void
    {
        $defined = $this->registry->names();

        foreach (TagModel::query()->withTrashed()->distinct()->pluck('type') as $type) {
            if (is_string($type) && ! in_array($type, $defined, true)) {
                $error('types', "Tags of the undefined type [{$type}] exist.");
            }
        }

        foreach ($this->registry->orphanAdjustments() as $name) {
            $error('types', "Tags::extend() or tags.overrides adjusts [{$name}], which no one defines.");
        }

        foreach ($defined as $name) {
            try {
                $this->registry->resolve($name);
            } catch (Throwable $exception) {
                $error('types', $exception->getMessage());
            }
        }
    }

    /**
     * @param  callable(string, string): void  $error
     */
    private function limits(callable $error): void
    {
        foreach ($this->registry->names() as $name) {
            $type = $this->safely($name);

            if ($type === null || $type->max === null) {
                continue;
            }

            $over = Tagging::query()
                ->where('type', $name)
                ->whereIn('tag_id', Taggings::liveTagKeys())
                ->select('taggable_type', 'taggable_id')
                ->groupBy('taggable_type', 'taggable_id')
                ->havingRaw('count(*) > ?', [$type->max])
                ->toBase()
                ->getCountForPagination();

            if ($over > 0) {
                $error('limits', "{$over} model(s) carry more [{$name}] tags than its max of {$type->max}.");
            }
        }
    }

    /**
     * @param  callable(string, string): void  $error
     * @param  callable(string, string): void  $warning
     */
    private function trees(callable $error, callable $warning): void
    {
        foreach ($this->registry->names() as $name) {
            $type = $this->safely($name);

            if ($type === null) {
                continue;
            }

            if (! $type->hierarchical && TagModel::query()->withTrashed()->where('type', $name)->whereNotNull('parent_id')->exists()) {
                $error('trees', "Tags of the flat type [{$name}] have parents.");
            }

            // A cap lowered after data exists rewrites nothing (§4.2): list what sits too deep.
            $deep = $type->maxDepth === null ? 0 : TagModel::query()->where('type', $name)->where('depth', '>', $type->maxDepth)->count();

            if ($deep > 0) {
                $error('trees', "{$deep} tag(s) of [{$name}] sit deeper than its max depth of {$type->maxDepth}.");
            }
        }

        $report = $this->repair->execute(new RepairOptions(paths: true, scopeKeys: true, counts: false, orphans: false, dryRun: true));

        if ($report->paths > 0) {
            $error('trees', "{$report->paths} tag(s) have a path or depth that does not match their parents: run tags:repair --paths.");
        }

        if ($report->cycles > 0) {
            $error('trees', "{$report->cycles} tag(s) sit in a parent cycle: fix their parent_id by hand.");
        }

        $tags = TagModel::make();
        $orphaned = TagModel::query()
            ->whereIn('parent_id', TagModel::query()->onlyTrashed()->select($tags->getQualifiedKeyName())->toBase())
            ->count();

        if ($orphaned > 0) {
            $warning('trees', "{$orphaned} live tag(s) sit under a deleted parent: restore the parent or move them.");
        }

        if ($report->scopeKeys > 0) {
            $error('scopes', "{$report->scopeKeys} tag(s) have a scope key that does not match their scope: run tags:repair --scope-keys.");
        }
    }

    /**
     * @param  callable(string, string): void  $error
     */
    private function indexes(callable $error): void
    {
        $table = TagsConfig::table('tags');
        $schema = TagModel::make()->getConnection()->getSchemaBuilder();

        foreach (TagSlugs::locales() as $locale) {
            if (! $schema->hasIndex($table, IndexNames::index($table, 'slug', $locale))) {
                $error('indexes', "No unique slug index for the locale [{$locale}]: run php artisan sluggable:indexes on the tag model.");
            }
        }
    }

    /**
     * Tag names are stored per locale: a plain string names a tag in the current locale, so an app
     * locale outside `translatable.locales` makes every tagging by name throw. A fallback locale
     * outside it only means names never fall back to it.
     *
     * @param  callable(string, string): void  $error
     * @param  callable(string, string): void  $warning
     */
    private function locales(callable $error, callable $warning): void
    {
        $locales = app(SlugLocales::class);
        $supported = $locales->supported();
        $current = $locales->current();
        $fallback = $locales->fallback();

        if (! in_array($current, $supported, true)) {
            $error('locales', "The app locale [{$current}] is not one of the supported locales (translatable.locales): tagging by name fails until you add it.");
        }

        if ($fallback !== null && ! in_array($fallback, $supported, true)) {
            $warning('locales', "The fallback locale [{$fallback}] (translatable.fallback_locale) is not one of the supported locales (translatable.locales): names never fall back to it.");
        }
    }

    /**
     * @param  callable(string, string): void  $error
     * @param  callable(string, string): void  $warning
     */
    private function keyTypes(callable $error, callable $warning): void
    {
        $classes = [...array_values(TagsConfig::scopes()), ...array_values(TagsConfig::taggables())];

        foreach ($this->registry->names() as $name) {
            $type = $this->safely($name);
            $classes = [...$classes, ...($type === null ? [] : $type->on)];
        }

        foreach (Tagging::query()->distinct()->pluck('taggable_type') as $morph) {
            if (is_string($morph)) {
                $classes[] = Relation::getMorphedModel($morph) ?? $morph;
            }
        }

        $connection = TagModel::make()->getConnectionName();

        foreach (array_unique(array_filter($classes, is_string(...))) as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $model = new $class;

            if (self::keyType($model) !== TagsConfig::keyType()) {
                $error('keys', class_basename($class).' is keyed by '.self::keyType($model)->value.', but tags.key_type is '.TagsConfig::keyType()->value.'.');
            }

            if ($model->getConnectionName() !== $connection) {
                $warning('connections', class_basename($class).' uses another database connection than the tags tables; the query scopes need one connection.');
            }
        }
    }

    /**
     * Taggings whose tag no longer belongs to one of the model's candidate scopes (its
     * `tagScope()` changed since): kept until the next sync of that type. Streams the scoped
     * taggings of live tags in pages and loads each page's models with one query per class, so
     * the query count does not grow with the taggings on a page. A model whose `tagScope()` gives
     * no usable scope (unsaved, or keyed against `tags.key_type`) is an error, never a crash.
     *
     * @param  callable(string, string): void  $error
     * @param  callable(string, string): void  $warning
     */
    private function scopes(callable $error, callable $warning): void
    {
        $outside = 0;
        /** @var array<string, array<string, true>> $broken class basename => model keys */
        $broken = [];
        $tags = TagModel::make();
        $taggings = new Tagging;

        Tagging::query()
            ->join($tags->getTable(), $tags->getQualifiedKeyName(), '=', $taggings->qualifyColumn('tag_id'))
            ->where($tags->qualifyColumn('scope_key'), '!=', ScopeKey::GLOBAL)
            ->whereNull($tags->qualifyColumn($tags->getDeletedAtColumn()))
            ->select([$taggings->qualifyColumn('taggable_type'), $taggings->qualifyColumn('taggable_id'), $tags->qualifyColumn('scope_key'), $tags->qualifyColumn('type')])
            ->orderBy($taggings->qualifyColumn('taggable_type'))
            ->orderBy($taggings->qualifyColumn('taggable_id'))
            ->orderBy($taggings->qualifyColumn('tag_id'))
            ->toBase()
            ->lazy(self::PAGE)
            ->chunk(self::PAGE)
            ->each(function (LazyCollection $page) use (&$outside, &$broken): void {
                $outside += $this->outsideScope($broken, $page->map(static fn (object $row): ScopedTagging => new ScopedTagging(
                    (string) ($row->taggable_type ?? ''),
                    (string) ($row->taggable_id ?? ''),
                    (string) ($row->scope_key ?? ''),
                    (string) ($row->type ?? ''),
                )));
            });

        if ($outside > 0) {
            $warning('scopes', "{$outside} tagging(s) sit outside their model's current scope; the next sync of that type drops them.");
        }

        foreach ($broken as $class => $models) {
            $error('scopes', count($models)." model(s) of [{$class}] have a tagScope() that is not a saved model whose key fits tags.key_type.");
        }
    }

    /**
     * The taggings of one page outside their model's candidate scopes. The models of one class load
     * in one query; a `tagScope()` that reads a relation (`$this->team`) loads it for the whole
     * page at its first read, where Eloquent supports relationship autoloading.
     *
     * @param  array<string, array<string, true>>  $broken  models whose tagScope() is unusable, filled in
     * @param  LazyCollection<int, ScopedTagging>  $page
     */
    private function outsideScope(array &$broken, LazyCollection $page): int
    {
        $outside = 0;

        foreach ($page->groupBy(static fn (ScopedTagging $row): string => $row->taggableType) as $morph => $rows) {
            $class = Relation::getMorphedModel((string) $morph) ?? (string) $morph;

            if (! class_exists($class) || ! is_subclass_of($class, Taggable::class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $models = $class::query()->withoutGlobalScopes()
                ->whereKey($rows->map(static fn (ScopedTagging $row): string => $row->taggableId)->unique()->values()->all())
                ->get();

            // Relationship autoloading exists from Laravel 12.8 on; an older 12.x reads each model's
            // relation lazily — slower, the same answer. A runtime check, as the analyser only sees
            // the installed version.
            if ((new ReflectionClass($models))->hasMethod('withRelationshipAutoloading')) {
                $models->withRelationshipAutoloading();
            }

            $byKey = $models->keyBy(static fn (Model $model): string => (string) $model->getKey());

            foreach ($rows as $row) {
                $model = $byKey->get($row->taggableId);
                $type = $this->safely($row->type);

                if (! $model instanceof Taggable || $type === null) {
                    continue;
                }

                try {
                    $candidates = Taggings::candidateScopeKeys($model, $type);
                } catch (InvalidArgumentException|InvalidTagsConfigurationException) {
                    $broken[class_basename($model)][(string) $row->taggableId] = true;

                    continue;
                }

                if (! in_array($row->scopeKey, $candidates, true)) {
                    $outside++;
                }
            }
        }

        return $outside;
    }

    /**
     * The resolved type, or null when it is undefined or does not resolve (reported elsewhere).
     */
    private function safely(string $name): ?TagType
    {
        try {
            return $this->registry->find($name);
        } catch (TagsException) {
            return null;
        }
    }

    private static function keyType(Model $model): KeyType
    {
        if (! $model->usesUniqueIds()) {
            return KeyType::BigInt;
        }

        return Str::isUlid($model->newUniqueId()) ? KeyType::Ulid : KeyType::Uuid;
    }
}
