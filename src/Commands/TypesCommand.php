<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Tags\Enums\TypeSource;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * `php artisan tags:types` — every tag type, its rules, where it was defined and what adjusted it.
 */
final class TypesCommand extends Command
{
    protected $signature = 'tags:types {--json : Print the types as JSON}';

    protected $description = 'List every tag type with its rules and where it was defined';

    public function handle(TagsManager $tags): int
    {
        $types = $tags->types();

        if ($this->option('json') === true) {
            $this->line((string) json_encode(
                $types->map(fn (TagType $type): array => $this->describe($type))->values()->all(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        if ($types->isEmpty()) {
            $this->info('No tag types are defined.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Label', 'Hierarchy', 'Max', 'Creation', 'Slugs', 'Scopes', 'Models', 'API', 'Source', 'Overrides'],
            $types->map(fn (TagType $type): array => array_map(
                static fn (mixed $value): string => is_array($value) ? ($value === [] ? 'any' : implode(', ', $value)) : (string) $value,
                $this->describe($type),
            ))->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, label: string, hierarchy: string, max: string, creation: string, slugs: string, scopes: string, models: list<string>, api: string, source: string, overrides: string}
     */
    private function describe(TagType $type): array
    {
        $hierarchy = 'flat';

        if ($type->hierarchical) {
            $hierarchy = 'tree, depth '.$type->maxDepth;

            if ($type->isClamped()) {
                $hierarchy .= ' (clamped from '.$type->declaredMaxDepth.')';
            }
        }

        return [
            'name' => $type->name,
            'label' => $type->label(),
            'hierarchy' => $hierarchy,
            'max' => $type->max === null ? 'unlimited' : (string) $type->max,
            'creation' => $type->curated ? 'curated' : 'free-form',
            'slugs' => $type->stableSlugs ? 'stable' : 'follow the name',
            'scopes' => $type->globalOnly ? 'global only' : 'scoped and global',
            'models' => $type->on,
            'api' => $type->private ? 'private' : 'public',
            'source' => match ($type->source) {
                TypeSource::Code => $type->definer ?? 'code',
                TypeSource::Config => 'config/tags.php',
                TypeSource::Default => 'default',
            },
            'overrides' => $type->applied === [] ? '-' : implode(', ', $type->applied),
        ];
    }
}
