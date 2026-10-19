<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Tags\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Tags\Enums\FindingSeverity;
use RoundlyConsulting\Tags\Support\Doctor;

/**
 * `php artisan tags:doctor` — what the data and the setup disagree on. Fails on any error.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'tags:doctor
        {--counts : Also compare every usage count with its taggings}
        {--json : Print the findings as JSON}';

    protected $description = 'Check tag types, trees, scopes, indexes, locales, key types and counts';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->run(counts: $this->option('counts') === true);
        $errors = array_filter($findings, static fn (DoctorFinding $finding): bool => $finding->severity === FindingSeverity::Error);

        if ($this->option('json') === true) {
            $this->line((string) json_encode(array_map(static fn (DoctorFinding $finding): array => [
                'severity' => $finding->severity->value,
                'section' => $finding->section,
                'message' => $finding->message,
            ], $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($findings === []) {
            $this->info('No problems found.');
        } else {
            $this->table(['Severity', 'Section', 'Finding'], array_map(static fn (DoctorFinding $finding): array => [
                $finding->severity->value,
                $finding->section,
                $finding->message,
            ], $findings));
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
