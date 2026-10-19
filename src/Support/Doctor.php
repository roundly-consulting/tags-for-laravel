<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Sluggable\Support\IndexNames;
use RoundlyConsulting\Tags\Actions\Maintenance\RepairTagsAction;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Tags\Enums\FindingSeverity;
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
        $this->trees($error);
        $this->indexes($error);
        $this->keyTypes($error, $warning);
        $this->scopes($warning);

        $report = $this->repair->execute(new RepairOptions(paths: false, scopeKeys: false, counts: $counts, orphans: true, dryRun: true));

        if ($report->counts > 0) {
            $warning('counts', "{$report->counts} tag(s) have a usage count that does not match their taggings: run tags:repair --counts.");
        }

        if ($report->orphans > 0) {
            $warning('orphans', "{$report->orphans} tagging(s) point at a model that no longer exists: run tags:repair --orphans.");
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
     */
    private function trees(callable $error): void
    {
        foreach ($this->registry->names() as $name) {
            $type = $this->safely($name);

            if ($type !== null && ! $type->hierarchical && TagModel::query()->withTrashed()->where('type', $name)->whereNotNull('parent_id')->exists()) {
                $error('trees', "Tags of the flat type [{$name}] have parents.");
            }
        }

        $report = $this->repair->execute(new RepairOptions(paths: true, scopeKeys: true, counts: false, orphans: false, dryRun: true));

        if ($report->paths > 0) {
            $error('trees', "{$report->paths} tag(s) have a path or depth that does not match their parents: run tags:repair --paths.");
        }

        if ($report->cycles > 0) {
            $error('trees', "{$report->cycles} tag(s) sit in a parent cycle: fix their parent_id by hand.");
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
     * `tagScope()` changed since): kept until the next sync of that type.
     *
     * @param  callable(string, string): void  $warning
     */
    private function scopes(callable $warning): void
    {
        $outside = 0;
        $tags = TagModel::make();
        $taggings = new Tagging;

        Tagging::query()
            ->join($tags->getTable(), $tags->getQualifiedKeyName(), '=', $taggings->qualifyColumn('tag_id'))
            ->where($tags->qualifyColumn('scope_key'), '!=', ScopeKey::GLOBAL)
            ->select([$taggings->qualifyColumn('taggable_type'), $taggings->qualifyColumn('taggable_id'), $tags->qualifyColumn('scope_key'), $tags->qualifyColumn('type')])
            ->orderBy($taggings->qualifyColumn('taggable_id'))
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$outside): void {
                $class = Relation::getMorphedModel((string) $row->taggable_type) ?? (string) $row->taggable_type;
                $type = $this->safely((string) $row->type);

                if ($type === null || ! class_exists($class) || ! is_subclass_of($class, Taggable::class) || ! is_subclass_of($class, Model::class)) {
                    return;
                }

                $model = $class::query()->withoutGlobalScopes()->whereKey($row->taggable_id)->first();

                if ($model instanceof Taggable && ! in_array($row->scope_key, Taggings::candidateScopeKeys($model, $type), true)) {
                    $outside++;
                }
            });

        if ($outside > 0) {
            $warning('scopes', "{$outside} tagging(s) sit outside their model's current scope; the next sync of that type drops them.");
        }
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
