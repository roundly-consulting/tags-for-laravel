<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Tags\TagsManager;

/**
 * `php artisan tags:repair` — paths, scope keys, usage counts and orphan taggings (all of them
 * unless options pick some).
 */
final class RepairCommand extends Command
{
    protected $signature = 'tags:repair
        {--paths : Recompute tree paths and depths}
        {--scope-keys : Recompute scope keys}
        {--counts : Recount usage counts}
        {--orphans : Delete taggings whose model is gone}
        {--type= : Only tags of this type}
        {--dry-run : Report without writing}';

    protected $description = 'Repair tag paths, scope keys, usage counts and orphan taggings';

    public function handle(TagsManager $tags): int
    {
        $picked = array_filter([
            'paths' => $this->option('paths') === true,
            'scopeKeys' => $this->option('scope-keys') === true,
            'counts' => $this->option('counts') === true,
            'orphans' => $this->option('orphans') === true,
        ]);
        $all = $picked === [];
        $type = $this->option('type');

        $report = $tags->repair(new RepairOptions(
            paths: $all || isset($picked['paths']),
            scopeKeys: $all || isset($picked['scopeKeys']),
            counts: $all || isset($picked['counts']),
            orphans: $all || isset($picked['orphans']),
            type: is_string($type) && $type !== '' ? $type : null,
            dryRun: $this->option('dry-run') === true,
        ));

        $this->table(['Repair', $report->dryRun ? 'Would fix' : 'Fixed'], [
            ['paths', (string) $report->paths],
            ['cycles (left alone)', (string) $report->cycles],
            ['scope keys', (string) $report->scopeKeys],
            ['orphan taggings', (string) $report->orphans],
            ['usage counts', (string) $report->counts],
        ]);

        foreach ($report->unresolvableTypes as $unresolvable) {
            $this->warn("Taggings of [{$unresolvable}] were left alone: it is no model class.");
        }

        return self::SUCCESS;
    }
}
